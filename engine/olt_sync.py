"""Read every OLT over SNMP (read-only) every 5 minutes: all ONUs with status/power/distance, and which customer
router MAC sits behind which ONU (looked up per MAC in the OLT's MAC table, never a full-table walk).

Brand drivers: only the OIDs differ. BDCOM EPON is verified on a P3608B, VSOL EPON on a V1600D, ECOM EPON
(EasyPath / C-Data family, NSCRTV EPON MIB) on 172.26.26.30.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.olt_sync [--olt ID]
"""
import argparse
import logging
import re
import time
from datetime import datetime, timedelta, timezone

from engine import db
from engine.snmp import SNMP, text

log = logging.getLogger("zyro-olt-sync")
PAUSE = 0.04            # between SNMP requests, so the OLT's CPU is never pushed
RECHECK_HOURS = 6       # how long a found / missed MAC is trusted before asking the OLT again

SYS = {"descr": "1.3.6.1.2.1.1.1.0", "name": "1.3.6.1.2.1.1.5.0", "uptime": "1.3.6.1.2.1.1.3.0"}
IF_DESCR = "1.3.6.1.2.1.2.2.1.2"
IF_OPER = "1.3.6.1.2.1.2.2.1.8"
IF_LAST_CHANGE = "1.3.6.1.2.1.2.2.1.9"
FDB_PORT = "1.3.6.1.2.1.17.7.1.2.2.1.2"          # dot1qTpFdbPort.<vlan>.<mac>
FDB_PORT_D1D = "1.3.6.1.2.1.17.4.3.1.2"          # dot1dTpFdbPort.<mac> (all VLANs in one table)
BRIDGE_IFINDEX = "1.3.6.1.2.1.17.1.4.1.2"        # dot1dBasePortIfIndex
VLAN_NAMES = "1.3.6.1.2.1.17.7.1.4.3.1.1"        # dot1qVlanStaticName

VSOL_ONU = re.compile(r"^EPON(\d+)ONU(\d+)$", re.I)
OPTICAL = ("rx_dbm", "tx_dbm", "temp_c", "voltage")

# ECOM: no ONU interfaces in ifTable; ONUs live in the NSCRTV onuInfoTable, indexed by a device number
# 0x01000000 | (PON ifIndex << 8) | ONU id (PON ifIndex 11 = pon 1). We use that device number as the ONU's if_index.
ECOM_ONU = "1.3.6.1.4.1.17409.2.3.4.1.1"
ECOM_FDB = "1.3.6.1.4.1.34592.1.3.100.12.2.1.1.3"   # .<router mac 6 octets>.<vlan> = ONU device number


def ecom_name(dev: int) -> str:
    return f"EPON0/{((dev >> 8) & 0xFF) - 10}:{dev & 0xFF}"

# Each driver: which ifDescr names are ONUs, how an ONU's row index in the brand's own tables is built ("key"), and per
# column (OID, how to read it): "mac" = 6 raw bytes, a number = int divided by it, "dbm" = text like
# "0.01 mW (-19.00 dBm)", "num" = text like "44.25 C", None = plain int.
DRIVERS = {
    "bdcom": {
        "onu_name": lambda d: ":" in d and d.upper().startswith(("EPON", "GPON")),
        "key": lambda i, name: str(i),
        "cols": {
            "status_code": ("1.3.6.1.4.1.3320.101.10.1.1.26", None),
            "onu_mac": ("1.3.6.1.4.1.3320.101.10.1.1.3", "mac"),
            "distance_m": ("1.3.6.1.4.1.3320.101.10.1.1.27", None),
            "rx_dbm": ("1.3.6.1.4.1.3320.101.10.5.1.5", 10),
            "tx_dbm": ("1.3.6.1.4.1.3320.101.10.5.1.6", 10),
            "temp_c": ("1.3.6.1.4.1.3320.101.10.5.1.2", 256),
            "voltage": ("1.3.6.1.4.1.3320.101.10.5.1.3", 10000),
        },
    },
    # VSOL V1600D EPON: ONU interfaces are named "EPON07ONU39"; its own tables are indexed <pon>.<onu>. No status code or
    # distance column found yet (online comes from ifOperStatus). Walking past these tables made its agent stop
    # answering, so only these two tables are read, by GET.
    "vsol": {
        "onu_name": lambda d: bool(VSOL_ONU.match(d)),
        "fdb": "dot1d",
        "key": lambda i, name: ".".join(str(int(x)) for x in VSOL_ONU.match(name).groups()),
        "cols": {
            "status_code": (None, None),
            "onu_mac": ("1.3.6.1.4.1.37950.1.1.5.12.2.1.2.1.5", "mac"),
            "distance_m": (None, None),
            "rx_dbm": ("1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7", "dbm"),
            "tx_dbm": ("1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6", "dbm"),
            "temp_c": ("1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.3", "num"),
            "voltage": ("1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.4", "num"),
        },
    },
    # ECOM EPON (EasyPath, C-Data family): ONU list, status and distance from onuInfoTable (small GETBULKs are fine),
    # optical values by GET per online ONU (<col>.<dev>.0.0; offline ONUs answer noSuchInstance). No BRIDGE-MIB: the
    # customer MAC -> ONU table is C-Data's own (ECOM_FDB), about one row per online ONU, so it is walked whole.
    # A long burst of requests makes its agent stop answering for ~90 s, hence the longer pause.
    "ecom": {
        "table": True,
        "fdb": "ecom",
        "pause": 0.1,
        "optical_suffix": ".0.0",
        "key": lambda i, name: str(i),
        "cols": {
            "status_code": (ECOM_ONU + ".8", None),       # 1 online, 2 offline
            "onu_mac": (ECOM_ONU + ".7", "mac"),
            "distance_m": (ECOM_ONU + ".15", None),
            "rx_dbm": ("1.3.6.1.4.1.17409.2.3.4.2.1.4", 100),
            "tx_dbm": ("1.3.6.1.4.1.17409.2.3.4.2.1.5", 100),
            "temp_c": ("1.3.6.1.4.1.17409.2.3.4.2.1.8", 100),
            "voltage": ("1.3.6.1.4.1.17409.2.3.4.2.1.7", 100000),
        },
    },
}


def col_oid(drv: dict, col: str, key: str) -> str:
    return f"{drv['cols'][col][0]}.{key}{drv.get('optical_suffix', '') if col in OPTICAL else ''}"


def conv(v, how):
    """One SNMP value read the driver's way; None when the ONU gave nothing usable."""
    if how == "mac":
        return _fmt_mac(v)
    if how in ("dbm", "num"):
        m = re.search(r"\((-?\d+(?:\.\d+)?)\s*dBm\)" if how == "dbm" else r"-?\d+(?:\.\d+)?", text(v) or "")
        return round(float(m.group(1) if how == "dbm" else m.group(0)), 2) if m else None
    if not isinstance(v, int) or v in (-65535, 65535):
        return None
    return v if how is None else round(v / how, 2)


def routers(o: dict) -> list[str]:
    """MikroTik identities whose customers dial through this OLT (comma list); empty = all."""
    return [x.strip() for x in (o.get("router_identity") or "").split(",") if x.strip()]


def client(o: dict) -> SNMP:
    return SNMP(o["host"], o["snmp_port"], db.decrypt(o["community"]), timeout=5, retries=1)


def walk(s: SNMP, root: str, rep: int = 20, pause: float = PAUSE) -> list[tuple[str, object]]:
    out, cur, pre = [], root, root + "."
    while True:
        rows = s._request(0xA5, [cur], 0, rep)
        stop = False
        for oid, tag, val in rows:
            if tag == 0x82 or not oid.startswith(pre):
                stop = True
                break
            out.append((oid, val))
            cur = oid
        if stop or not rows:
            return out
        time.sleep(pause)


def walk_cols(s: SNMP, roots: list[str], rep: int, pause: float) -> dict[str, dict[int, object]]:
    """Walk several columns of one table together: one GETBULK carries all of them, so a table costs as many
    requests as walking one column. Returns {root: {last index: value}}."""
    out = {r: {} for r in roots}
    cur = {r: r for r in roots}
    while cur:
        live = list(cur)
        rows = s._request(0xA5, [cur[r] for r in live], 0, rep)
        if not rows:
            break
        for n, (oid, tag, val) in enumerate(rows):
            r = live[n % len(live)]
            if r not in cur:
                continue
            if tag == 0x82 or not oid.startswith(r + "."):
                del cur[r]
                continue
            out[r][idx(oid)] = val
            cur[r] = oid
        time.sleep(pause)
    return out


def idx(oid: str) -> int:
    return int(oid.rsplit(".", 1)[1])


def check(o: dict) -> dict:
    s = client(o)
    v = s.get(*SYS.values())
    descr = v[SYS["descr"]]
    descr = descr.decode(errors="replace") if isinstance(descr, bytes) else str(descr)
    return {"sys_name": text(v[SYS["name"]]), "sys_descr": " ".join(descr.split())[:250]}


def _fmt_mac(v) -> str | None:
    if isinstance(v, bytes) and len(v) == 6:
        return ":".join(f"{b:02X}" for b in v)
    m = re.fullmatch(rb"0x([0-9a-fA-F]{12})", v) if isinstance(v, bytes) else None   # VSOL: text like b"0xa25d0831d980"
    if m:
        h = m.group(1).decode().upper()
        return ":".join(h[n:n + 2] for n in range(0, 12, 2))
    return None


def poll_onus(o: dict, s: SNMP) -> int:
    drv = DRIVERS[o["brand"]]
    pause = drv.get("pause", PAUSE)
    cols = {k: {} for k in drv["cols"]}
    if drv.get("table"):
        # ONUs from the brand's own table: status, MAC, distance and seconds since the last change in four small walks
        t = walk_cols(s, [ECOM_ONU + c for c in (".8", ".7", ".15", ".18")], 5, pause)
        st, since = t[ECOM_ONU + ".8"], t[ECOM_ONU + ".18"]
        onus = {i: ecom_name(i) for i in st}
        oper = {i: 1 if v == 1 else 2 for i, v in st.items()}
        cols["status_code"] = {i: conv(v, None) for i, v in st.items()}
        cols["onu_mac"] = {i: conv(v, "mac") for i, v in t[ECOM_ONU + ".7"].items()}
        cols["distance_m"] = {i: conv(v, None) or None for i, v in t[ECOM_ONU + ".15"].items()}
        uptime = 100 * max(since.values(), default=0)   # ifLastChange-style: timeticks of "now" and of the change
        changed = {i: uptime - 100 * v for i, v in since.items() if isinstance(v, int)}
    else:
        names = {idx(oid): text(v) for oid, v in walk(s, IF_DESCR)}
        onus = {i: n for i, n in names.items() if drv["onu_name"](n)}
        oper = {idx(oid): v for oid, v in walk(s, IF_OPER) if idx(oid) in onus}
        changed = {idx(oid): v for oid, v in walk(s, IF_LAST_CHANGE) if idx(oid) in onus}
        uptime = s.get(SYS["uptime"])[SYS["uptime"]] or 0
    # BDCOM's own tables: plain GETs of known ONU indexes. A GETBULK on these tables makes the OLT's SNMP agent stop
    # answering for a minute or two, so they are never walked.
    keys = sorted(onus)
    row = {i: drv["key"](i, onus[i]) for i in keys}
    for key in ("status_code", "onu_mac", "distance_m"):
        oid, how = drv["cols"][key]
        if not oid or drv.get("table"):
            continue
        for n in range(0, len(keys), 10):
            part = keys[n:n + 10]
            got = s.get(*[f"{oid}.{row[i]}" for i in part])
            for i in part:
                cols[key][i] = conv(got.get(f"{oid}.{row[i]}"), how)
            time.sleep(PAUSE)
    # optical values: one ONU per request (the OLT asks the ONU itself). An ONU without DDM makes the OLT wait ~10 s
    # and answer -65535; remember those and skip them for a day.
    skip = {r["if_index"] for r in db.all_rows(
        "SELECT if_index FROM onus WHERE olt_id = %s AND no_ddm_at > now() - interval '1 day'", (o["id"],))}
    no_ddm = []
    slow = SNMP(o["host"], o["snmp_port"], s.community.decode(), timeout=12, retries=0)
    optical = list(OPTICAL)
    for i in keys:
        if oper.get(i) != 1 or i in skip:
            continue
        try:
            got = slow.get(*[col_oid(drv, k, row[i]) for k in optical])
        except Exception as e:
            log.warning("optical read of %s failed: %s", onus[i], e)
            no_ddm.append(i)
            continue
        for k in optical:
            cols[k][i] = conv(got.get(col_oid(drv, k, row[i])), drv["cols"][k][1])
        if cols["rx_dbm"].get(i) is None:
            no_ddm.append(i)
        time.sleep(pause)
    now = datetime.now(timezone.utc)
    rows = []
    for i, name in onus.items():
        online = oper.get(i) == 1
        lc = changed.get(i)
        last_change = (now - timedelta(seconds=(uptime - lc) / 100)).replace(tzinfo=None) if lc and uptime >= lc else None
        rows.append((o["company_id"], o["id"], i, name, cols["onu_mac"].get(i), online, cols["status_code"].get(i),
                     cols["distance_m"].get(i) if online else None, cols["rx_dbm"].get(i) if online else None,
                     cols["tx_dbm"].get(i) if online else None, cols["temp_c"].get(i) if online else None,
                     cols["voltage"].get(i) if online else None, last_change))
    with db.conn() as c:
        with c.transaction():
            with c.cursor() as cur:
                cur.executemany(
                    """INSERT INTO onus (company_id, olt_id, if_index, name, onu_mac, online, status_code, distance_m, rx_dbm,
                                         tx_dbm, temp_c, voltage, last_change_at, seen_at)
                       VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())
                       ON CONFLICT (olt_id, if_index) DO UPDATE SET name = EXCLUDED.name, onu_mac = EXCLUDED.onu_mac,
                         online = EXCLUDED.online, status_code = EXCLUDED.status_code,
                         distance_m = COALESCE(EXCLUDED.distance_m, onus.distance_m),
                         rx_dbm = EXCLUDED.rx_dbm, tx_dbm = EXCLUDED.tx_dbm, temp_c = EXCLUDED.temp_c, voltage = EXCLUDED.voltage,
                         last_change_at = EXCLUDED.last_change_at, seen_at = now()""", rows)
            c.execute("DELETE FROM onus WHERE olt_id = %s AND NOT (if_index = ANY(%s))", (o["id"], list(onus)))
            if no_ddm:
                c.execute("UPDATE onus SET no_ddm_at = now() WHERE olt_id = %s AND if_index = ANY(%s)", (o["id"], no_ddm))
            c.execute("UPDATE olts SET onu_total = %s, onu_online = %s WHERE id = %s",
                      (len(rows), sum(1 for r in rows if r[5]), o["id"]))
    return len(rows)


def vlans(o: dict, s: SNMP) -> list[int]:
    found = sorted({idx(oid) for oid, _v in walk(s, VLAN_NAMES)})
    db.execute("UPDATE olts SET vlans = %s WHERE id = %s", (__import__("json").dumps(found), o["id"]))
    return found


def _fdb_oid(mac: str, vlan: int | None) -> str:
    octets = ".".join(str(int(x, 16)) for x in mac.split(":"))
    if vlan is None:   # VSOL: dot1dTpFdbPort, MAC index carries its length ("6."), no VLAN, port = ifIndex
        return f"{FDB_PORT_D1D}.6.{octets}"
    return f"{FDB_PORT}.{vlan}.{octets}"


def map_macs(o: dict, s: SNMP, vlan_list: list[int], budget: float = 240) -> tuple[int, int]:
    """Look up the customer MACs we don't know yet (or not checked lately) in this OLT's MAC table."""
    cand = db.all_rows(
        f"""SELECT DISTINCT upper(s.caller_id) AS mac FROM ppp_sessions s JOIN mikrotik_routers r ON r.id = s.router_id
            LEFT JOIN customer_onus m ON m.company_id = s.company_id AND m.client_mac = upper(s.caller_id)
            LEFT JOIN onu_mac_misses x ON x.olt_id = %s AND x.client_mac = upper(s.caller_id)
            WHERE s.company_id = %s AND s.caller_id IS NOT NULL AND s.seen_at > now() - interval '10 minutes'
              {"AND r.identity = ANY(%s)" if routers(o) else ""}
              AND (m.id IS NULL OR m.checked_at < now() - interval '{RECHECK_HOURS} hours')
              AND (x.olt_id IS NULL OR x.checked_at < now() - interval '{RECHECK_HOURS} hours')""",
        tuple([o["id"], o["company_id"]] + ([routers(o)] if routers(o) else [])))
    macs = [r["mac"] for r in cand if r["mac"] and len(r["mac"].split(":")) == 6]
    if not macs:
        return 0, 0
    onu_ids = {r["if_index"]: r["id"] for r in db.all_rows("SELECT id, if_index FROM onus WHERE olt_id = %s", (o["id"],))}
    hits: dict[str, tuple[int, int]] = {}
    if DRIVERS[o["brand"]].get("fdb") == "ecom":
        # the whole customer MAC -> ONU table (~one row per online ONU) in small walks, then match locally
        pre = len(ECOM_FDB.split("."))
        for oid, dev in walk(s, ECOM_FDB, 10, DRIVERS[o["brand"]]["pause"]):
            p = oid.split(".")[pre:]
            if len(p) == 7 and dev in onu_ids:
                hits[":".join(f"{int(x):02X}" for x in p[:6])] = (onu_ids[dev], int(p[6]))
        _save_macs(o, macs, hits)
        return len(macs), sum(1 for m in macs if m in hits)
    bridge = {idx(oid): v for oid, v in walk(s, BRIDGE_IFINDEX)}
    # VLANs that already gave us customers first; batches of 100 MACs, each finished and saved before the next,
    # and at most `budget` seconds per run (the rest continues next run), so a big router never blocks the sync.
    known = [r["vlan"] for r in db.all_rows("SELECT vlan, count(*) n FROM customer_onus WHERE olt_id = %s AND vlan IS NOT NULL "
                                            "GROUP BY vlan ORDER BY n DESC", (o["id"],))]
    order = known + [v for v in vlan_list if v not in known]
    if DRIVERS[o["brand"]].get("fdb") == "dot1d":
        order = [None]
    started, asked = time.time(), 0
    for b0 in range(0, len(macs), 100):
        if time.time() - started > budget:
            break
        batch = macs[b0:b0 + 100]
        asked += len(batch)
        for vlan in order:
            todo = [m for m in batch if m not in hits]
            if not todo:
                break
            for i in range(0, len(todo), 20):
                chunk = todo[i:i + 20]
                res = s.get(*[_fdb_oid(m, vlan) for m in chunk])
                for m in chunk:
                    port = res.get(_fdb_oid(m, vlan))
                    ifindex = bridge.get(port, port) if isinstance(port, int) and port > 0 else None
                    if ifindex in onu_ids:
                        hits[m] = (onu_ids[ifindex], vlan)
                time.sleep(PAUSE)
        _save_macs(o, batch, hits)
    macs = macs[:asked]
    return len(macs), len(hits)


def _save_macs(o: dict, batch: list[str], hits: dict[str, tuple[int, int]]) -> None:
    """Found MACs into customer_onus, the rest into onu_mac_misses (not asked again for RECHECK_HOURS)."""
    with db.conn() as c:
        with c.transaction():
            for m in batch:
                if m in hits:
                    onu_id, vlan = hits[m]
                    c.execute("""INSERT INTO customer_onus (company_id, client_mac, olt_id, onu_id, vlan, checked_at)
                                 VALUES (%s, %s, %s, %s, %s, now())
                                 ON CONFLICT (company_id, client_mac) DO UPDATE SET olt_id = EXCLUDED.olt_id,
                                   onu_id = EXCLUDED.onu_id, vlan = EXCLUDED.vlan, checked_at = now()""",
                              (o["company_id"], m, o["id"], onu_id, vlan))
                else:
                    c.execute("""INSERT INTO onu_mac_misses (olt_id, client_mac, checked_at) VALUES (%s, %s, now())
                                 ON CONFLICT (olt_id, client_mac) DO UPDATE SET checked_at = now()""", (o["id"], m))
                    c.execute("DELETE FROM customer_onus WHERE company_id = %s AND client_mac = %s AND olt_id = %s",
                              (o["company_id"], m, o["id"]))


def sync_olt(o: dict) -> dict:
    import fcntl
    with open(f"/tmp/zyro-olt-{o['id']}.lock", "w") as fh:
        try:
            fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return {"skipped": "another sync of this OLT is running"}
        return _sync_olt(o)


def _sync_olt(o: dict) -> dict:
    if o["brand"] not in DRIVERS:
        raise RuntimeError(f"{o['brand']} OLT এখনো সমর্থিত নয়")
    t0 = time.time()
    s = client(o)
    info = check(o)
    n = poll_onus(o, s)
    vl = vlans(o, s)
    # the whole OLT within ~7 minutes, under the unit's limit: a slow ONU poll leaves less time for the MAC backlog
    asked, found = map_macs(o, s, vl, budget=max(60, 420 - (time.time() - t0)))
    db.execute("""UPDATE olts SET sys_name = %s, sys_descr = %s, last_poll_at = now(), last_poll_ok = true,
                  last_poll_message = %s, updated_at = now() WHERE id = %s""",
               (info["sys_name"], info["sys_descr"], f"{n} ONU · {found}/{asked} নতুন MAC মিলেছে", o["id"]))
    return {"onus": n, "vlans": len(vl), "macs_asked": asked, "macs_found": found}


def onu_for_mac(company_id: int, mac: str | None, live: bool = True) -> dict | None:
    """The ONU behind a customer router MAC, in the billing-like shape the views use. live=True re-reads it now."""
    if not mac:
        return None
    r = db.one("""SELECT u.*, o.name AS olt_name, o.brand, o.host, o.snmp_port, o.community, o.id AS oid
                  FROM customer_onus m JOIN onus u ON u.id = m.onu_id JOIN olts o ON o.id = m.olt_id
                  WHERE m.company_id = %s AND m.client_mac = %s""", (company_id, mac.upper()))
    if not r:
        return None
    if live and r["brand"] in DRIVERS:
        try:
            s = SNMP(r["host"], r["snmp_port"], db.decrypt(r["community"]), timeout=3, retries=0)
            drv = DRIVERS[r["brand"]]
            cols, i = drv["cols"], r["if_index"]
            k = drv["key"](i, r["name"])
            want = {c: cols[c] for c in ("rx_dbm", "tx_dbm", "distance_m") if cols[c][0]}
            state = col_oid(drv, "status_code", k) if drv.get("table") else f"{IF_OPER}.{i}"
            v = s.get(state, *[col_oid(drv, c, k) for c in want])
            r["online"] = v.get(state) == 1
            got = {c: conv(v.get(col_oid(drv, c, k)), how) for c, (_oid, how) in want.items()}
            if "rx_dbm" in got:
                r["rx_dbm"] = got["rx_dbm"] if r["online"] else None
            if "tx_dbm" in got:
                r["tx_dbm"] = got["tx_dbm"] if r["online"] else None
            r["distance_m"] = got.get("distance_m") or r["distance_m"]
        except Exception as e:
            log.warning("live ONU read failed: %s", e)
    return {"source": "olt", "OLTName": r["olt_name"], "OLTPort": r["name"], "OnuStatus": "online" if r["online"] else "offline",
            "OpticalPower": None if r["rx_dbm"] is None else float(r["rx_dbm"]),
            "TxPower": None if r["tx_dbm"] is None else float(r["tx_dbm"]),
            "Distance": r["distance_m"], "Onumacaddress": r["onu_mac"],
            "Temperature": None if r["temp_c"] is None else float(r["temp_c"]),
            "LastChange": r["last_change_at"].isoformat() if r["last_change_at"] else None,
            "LastDeregisterTime": "", "DeregisterReason": ""}


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    p = argparse.ArgumentParser()
    p.add_argument("--olt", type=int)
    a = p.parse_args()
    rows = db.all_rows("SELECT * FROM olts WHERE enabled" + (" AND id = %s" if a.olt else "") + " ORDER BY id",
                       (a.olt,) if a.olt else ())
    if not a.olt and len(rows) > 1:
        # Each OLT in its own process, all at once: one after another, a slow OLT (big MAC backlog) used up the
        # unit's time limit and the OLTs after it were never polled. Each one stays within ~5 minutes on its own.
        import subprocess
        import sys
        procs = [subprocess.Popen([sys.executable, "-m", "engine.olt_sync", "--olt", str(o["id"])]) for o in rows]
        for pr in procs:
            pr.wait()
        return
    for o in rows:
        t0 = time.time()
        try:
            log.info("olt %s (%s): %s in %.0fs", o["id"], o["name"], sync_olt(o), time.time() - t0)
        except Exception as e:
            log.warning("olt %s (%s) failed: %s", o["id"], o["name"], e)
            db.execute("""UPDATE olts SET last_poll_at = now(), last_poll_ok = false, last_poll_message = %s,
                          updated_at = now() WHERE id = %s""", (str(e)[:250], o["id"]))


if __name__ == "__main__":
    main()
