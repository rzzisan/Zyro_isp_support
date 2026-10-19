"""MikroTik backups: every night (zyro-mikrotik-backup.timer, 03:00 Dhaka) and from the panel's "এখনই ব্যাকআপ" button.

Per router, one at a time: `/export file=… show-sensitive` (.rsc, plain text with PPP secrets) and `/system/backup/save`
(.backup, binary, dont-encrypt so it restores without a password). Both files are read back over the same RouterOS API
(`/file/read`, RouterOS 7.13+; FTP with the same login as a fallback), then removed from the router. Files land in the
panel's private disk (panel/storage/app/private/mikrotik-backups/{company}/{router}/{date}/), never the public one;
mikrotik_backups keeps one row per run. After the nightly run, files older than the company's mikrotik_backup_days go.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.mikrotik_backup [--router ID]
"""
import ftplib
import io
import logging
import os
import re
import shutil
import sys
import threading
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

from engine import db
from engine.mikrotik import RouterOS, RouterOSError

log = logging.getLogger("zyro-mikrotik-backup")

ROOT = Path(os.environ.get("MIKROTIK_BACKUP_ROOT")
            or Path(__file__).resolve().parent.parent / "panel" / "storage" / "app" / "private")
SUBDIR = "mikrotik-backups"
DHAKA = timezone(timedelta(hours=6))
CHUNK = 32768
STALE_MINUTES = 30  # a "running" row older than this was cut short (engine restart, timeout)


def _safe(name: str) -> str:
    return re.sub(r"[^A-Za-z0-9._-]+", "_", name).strip("_") or "router"


def _wait_file(api: RouterOS, name: str, timeout: float = 120) -> int:
    """The router writes the file in the background on some versions: wait until it exists and its size stops growing."""
    last, deadline = None, time.monotonic() + timeout
    while time.monotonic() < deadline:
        rows = api.talk("/file/print", {".proplist": ".id,name,size"}, query={"name": name})
        size = int(rows[0].get("size") or 0) if rows else None
        if size and size == last:
            return size
        last = size
        time.sleep(1.5)
    raise RouterOSError(f"{name} রাউটারে তৈরি হয়নি")


def _read_api(api: RouterOS, name: str, size: int) -> bytes:
    buf = bytearray()
    while len(buf) < size:
        rows = api.talk("/file/read", {"file": name, "offset": len(buf), "chunk-size": CHUNK}, raw=True)
        data = (rows[0].get("data") if rows else "") or ""
        if not data:
            break
        buf += data.encode(errors="surrogateescape")
    if len(buf) != size:
        raise RouterOSError(f"{name}: {len(buf)} of {size} bytes read")
    return bytes(buf)


def _read_ftp(r: dict, password: str, name: str, size: int) -> bytes:
    out = io.BytesIO()
    with ftplib.FTP() as ftp:
        ftp.connect(r["host"], 21, timeout=30)
        ftp.login(r["username"], password)
        ftp.retrbinary(f"RETR {name}", out.write)
    if out.tell() != size:
        raise RouterOSError(f"FTP {name}: {out.tell()} of {size} bytes")
    return out.getvalue()


def _fetch(api: RouterOS, r: dict, password: str, name: str, size: int) -> bytes:
    try:
        return _read_api(api, name, size)
    except RouterOSError as e:
        api_error = str(e)
    try:
        return _read_ftp(r, password, name, size)
    except Exception as e:
        raise RouterOSError(f"ফাইল আনা যায়নি (API: {api_error}; FTP: {str(e)[:120] or e.__class__.__name__})")


def _remove(api: RouterOS, name: str) -> None:
    try:
        for row in api.talk("/file/print", {".proplist": ".id"}, query={"name": name}):
            api.talk("/file/remove", {".id": row[".id"]})
    except Exception as e:  # never fail a backup over the cleanup; the next run tries again
        log.warning("could not remove %s from the router: %s", name, e)


def _store(rel: str, data: bytes) -> None:
    path = ROOT / rel
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".part")
    with open(tmp, "wb") as f:
        f.write(data)
    os.chmod(tmp, 0o640)  # the panel (www-data) reads through the group; nobody else
    os.replace(tmp, path)


def backup_router(r: dict, row_id: int) -> dict:
    """Make both files on the router, download them, remove them there. Updates the mikrotik_backups row."""
    password = db.decrypt(r["password"]) or ""
    now = datetime.now(DHAKA)
    base = f"desk-backup-{int(time.time())}"
    label = _safe(r.get("identity") or r["host"])
    folder = f"{SUBDIR}/{r['company_id']}/{r['id']}/{now:%Y-%m-%d}"
    got, errors = {}, []
    try:
        with RouterOS(r["host"], r["api_port"], r["username"], password, timeout=20) as api:
            identity = (api.talk("/system/identity/print") or [{}])[0].get("name") or label
            label = _safe(identity)
            api.sock.settimeout(180)  # export of a few thousand secrets takes a while
            for kind, ext, command, attrs in (
                    ("rsc", "rsc", "/export", {"file": base, "show-sensitive": ""}),
                    ("backup", "backup", "/system/backup/save", {"name": base, "dont-encrypt": "yes"})):
                fname = f"{base}.{ext}"
                try:
                    try:
                        api.talk(command, attrs)
                    except RouterOSError as e:  # RouterOS 6 has no show-sensitive (it exports secrets by default)
                        if "show-sensitive" not in attrs or "unknown parameter" not in str(e):
                            raise
                        api.talk(command, {"file": base})
                    size = _wait_file(api, fname)
                    data = _fetch(api, r, password, fname, size)
                    rel = f"{folder}/{label}-{now:%Y%m%d-%H%M}.{ext}"
                    _store(rel, data)
                    got[kind] = (rel, len(data))
                except Exception as e:
                    errors.append(f".{ext}: {str(e)[:200] or e.__class__.__name__}")
                finally:
                    _remove(api, fname)
    except Exception as e:
        errors.append(str(e)[:200] or e.__class__.__name__)
    status = "ok" if len(got) == 2 else ("partial" if got else "failed")
    rsc, bak = got.get("rsc", (None, None)), got.get("backup", (None, None))
    db.execute("""UPDATE mikrotik_backups SET status = %s, router_name = %s, rsc_path = %s, rsc_size = %s, backup_path = %s,
                  backup_size = %s, error = %s, finished_at = now(), updated_at = now() WHERE id = %s""",
               (status, label, rsc[0], rsc[1], bak[0], bak[1], "; ".join(errors) or None, row_id))
    return {"status": status, "errors": errors}


def _begin(r: dict, trigger: str) -> int | None:
    """New "running" row, or None when this router already has a backup running."""
    if db.one("""SELECT 1 FROM mikrotik_backups WHERE router_id = %s AND status = 'running'
                 AND started_at > now() - make_interval(mins => %s)""", (r["id"], STALE_MINUTES)):
        return None
    return db.execute("""INSERT INTO mikrotik_backups (company_id, router_id, router_name, trigger, status, started_at, created_at, updated_at)
                         VALUES (%s, %s, %s, %s, 'running', now(), now(), now()) RETURNING id""",
                      (r["company_id"], r["id"], r.get("identity") or r["host"], trigger))["id"]


def run(r: dict, trigger: str) -> dict | None:
    row_id = _begin(r, trigger)
    if row_id is None:
        return None
    try:
        return backup_router(r, row_id)
    except Exception as e:  # keep the row honest even on a bug
        db.execute("UPDATE mikrotik_backups SET status = 'failed', error = %s, finished_at = now(), updated_at = now() WHERE id = %s",
                   (str(e)[:250], row_id))
        raise


def start(company_id: int, router_id: int) -> dict:
    """Panel button: run in the background; the page polls the row."""
    r = db.one("SELECT * FROM mikrotik_routers WHERE id = %s AND company_id = %s", (router_id, company_id))
    if not r:
        return {"error": "not found"}
    row_id = _begin(r, "manual")
    if row_id is None:
        return {"running": True}

    def go():
        try:
            backup_router(r, row_id)
        except Exception as e:
            log.exception("backup of router %s failed", router_id)
            db.execute("UPDATE mikrotik_backups SET status = 'failed', error = %s, finished_at = now(), updated_at = now() WHERE id = %s",
                       (str(e)[:250], row_id))

    threading.Thread(target=go, daemon=True).start()
    return {"started": True, "id": row_id}


def prune() -> int:
    """Delete backups older than each company's retention (default 30 days): files, then rows, then empty folders."""
    gone = 0
    for row in db.all_rows("""SELECT b.id, b.rsc_path, b.backup_path FROM mikrotik_backups b JOIN companies c ON c.id = b.company_id
                              WHERE b.status <> 'running'
                                AND b.started_at < now() - make_interval(days => GREATEST(COALESCE(c.mikrotik_backup_days, 30), 1))"""):
        for rel in (row["rsc_path"], row["backup_path"]):
            if rel and rel.startswith(SUBDIR + "/") and ".." not in rel:
                (ROOT / rel).unlink(missing_ok=True)
        db.execute("DELETE FROM mikrotik_backups WHERE id = %s", (row["id"],))
        gone += 1
    base = ROOT / SUBDIR
    if base.is_dir():  # date folders left empty
        for d in sorted((p for p in base.rglob("*") if p.is_dir()), key=lambda p: len(p.parts), reverse=True):
            if not any(d.iterdir()):
                d.rmdir()
    return gone


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    only = int(sys.argv[sys.argv.index("--router") + 1]) if "--router" in sys.argv else None
    db.execute("""UPDATE mikrotik_backups SET status = 'failed', error = 'বন্ধ হয়ে গেছে (সময় শেষ বা engine রিস্টার্ট)',
                  finished_at = now(), updated_at = now()
                  WHERE status = 'running' AND started_at < now() - make_interval(mins => %s)""", (STALE_MINUTES,))
    routers = db.all_rows("SELECT * FROM mikrotik_routers WHERE enabled AND (%s::int IS NULL OR id = %s) ORDER BY company_id, id",
                          (only, only))
    for r in routers:  # one router at a time, gentle on the routers
        t0 = time.monotonic()
        try:
            res = run(r, "manual" if only else "auto")
            log.info("router %s (%s): %s in %.0fs %s", r["id"], r["identity"], res and res["status"] or "already running",
                     time.monotonic() - t0, "; ".join((res or {}).get("errors") or []))
        except Exception:
            log.exception("router %s (%s) failed", r["id"], r["identity"])
    if not only:
        log.info("pruned %s old backups", prune())
    usage = shutil.disk_usage(ROOT) if ROOT.exists() else None
    if usage and usage.free < 2 * 1024 ** 3:
        log.warning("only %.1f GB free on the backup disk", usage.free / 1024 ** 3)


if __name__ == "__main__":
    main()
