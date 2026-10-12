"""Billing's lists (Configuration menu + the support page's lists) copied into the desk's billing_options.

Kept until someone presses "Sync" on that list's desk page (billing config pages); there is no timer. Items that
vanish from billing stay with active = false, so old tickets/customers still show their names.
  set -a; . engine/.env; set +a; PYTHONPATH=. .venv/bin/python -m engine.billing_config [--company ID] [--kind zones]
"""
import argparse
import logging

from psycopg.types.json import Jsonb

from engine import db, tenant as tenants

log = logging.getLogger("zyro-billing-config")

SUPPORT_KINDS = ("employees", "departments", "categories", "priorities")  # one page: /ClientSupport/DailyComplainList
# Configuration menu lists kept as billing "flex values": kind -> (endpoint, flexSetId)
FLEX = {
    "zones": ("/Config/AjaxConfigDataClientSideProcessing", "3"),
    "connection_types": ("/Config/AjaxConfigDataClientSideProcessing", "1"),
    "client_types": ("/Config/AjaxConfigDataClientSideProcessing", "2"),
    "protocol_types": ("/Config/AjaxConfigData", "4"),
    "customer_statuses": ("/Config/AjaxConfigData", "10"),
    "packages": ("/Config/AjaxConfigDataObject", "11"),
    "districts": ("/Config/AjaxConfigData", "6"),
    "upazilas": ("/Config/AjaxConfigData", "7"),
}
KINDS = SUPPORT_KINDS + tuple(FLEX) + ("subzones", "boxes")
PAGE = 1000


def _text(v) -> str | None:
    s = str(v).strip() if v is not None else ""
    return s or None


def _flag(v) -> bool | None:
    return None if v is None else str(v).strip().lower() == "true"


def _flex_rows(api, path: str, set_id: str) -> list[dict]:
    """All rows of one flex set. The server-side endpoints page with start/length, the others return everything."""
    out, start = [], 0
    while True:
        d = api._get(path, {"flexSetId": set_id, "draw": 1, "start": start, "length": PAGE})
        rows = d.get("aaData") or []
        out += rows
        start += PAGE
        if len(rows) < PAGE or start >= int(d.get("iTotalDisplayRecords") or d.get("iTotalRecords") or 0):
            return out


def _item(r) -> dict:
    if isinstance(r, list):  # AjaxConfigData: [id, set id, value, short value, details, status]
        r = dict(zip(("FlexValueId", "FlexValueSetId", "FlexValue", "FlexShortValue", "Attribute2", "Status"), r))
    item = {"option_id": str(r.get("FlexValueId")), "name": _text(r.get("FlexValue")) or "",
            "short_value": _text(r.get("FlexShortValue")), "billing_enabled": _flag(r.get("Status")), "extra": None}
    if r.get("FlexValueSetId") == "11":  # package: Attribute4 = bandwidth (Mbps), Attribute5 = price, Attribute3 = description
        item["extra"] = {"mbps": _text(r.get("Attribute4")), "price": _text(r.get("Attribute5")),
                         "description": _text(r.get("Attribute3")),
                         "vas": ", ".join(v.get("VAS") or "" for v in r.get("VASList") or []) or None}
    elif _text(r.get("Attribute2")):
        item["extra"] = {"details": _text(r.get("Attribute2"))}
    return item


def fetch(api, kind: str) -> list[dict]:
    """One list from billing as [{option_id, name, ...}]."""
    if kind in FLEX:
        return [_item(r) for r in _flex_rows(api, *FLEX[kind])]
    if kind in ("subzones", "boxes"):
        d = api._get("/Config/AjaxSubZoneList" if kind == "subzones" else "/Config/AjaxBoxList")
        rows = d.get("data") or d.get("aaData") or []
        return [{**_item(r), "parent_id": _text(r.get("ParentFlexValueId")), "parent_name": _text(r.get("ParentFlexValueName")),
                 "zone_id": _text(r.get("ZoneIdForBox")), "zone_name": _text(r.get("ZoneNameForBox"))} for r in rows]
    raise ValueError(f"unknown list {kind}")


def save(company_id: int, kind: str, items: list[dict]) -> dict:
    """Upsert one list; ones billing no longer has -> active = false. An empty answer keeps what we have."""
    if not items:
        return {"total": 0, "inactive": 0, "kept": True}
    for i in items:
        db.execute(
            """INSERT INTO billing_options (company_id, kind, option_id, name, short_value, parent_id, parent_name, zone_id,
                                            zone_name, billing_enabled, extra, active, synced_at, created_at, updated_at)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, true, now(), now(), now())
               ON CONFLICT (company_id, kind, option_id) DO UPDATE
                 SET name = EXCLUDED.name, short_value = EXCLUDED.short_value, parent_id = EXCLUDED.parent_id,
                     parent_name = EXCLUDED.parent_name, zone_id = EXCLUDED.zone_id, zone_name = EXCLUDED.zone_name,
                     billing_enabled = EXCLUDED.billing_enabled, extra = EXCLUDED.extra, active = true,
                     synced_at = now(), updated_at = now()""",
            (company_id, kind, i["option_id"], i["name"], i.get("short_value"), i.get("parent_id"), i.get("parent_name"),
             i.get("zone_id"), i.get("zone_name"), i.get("billing_enabled"),
             Jsonb(i["extra"]) if i.get("extra") else None))
    gone = db.execute(
        """WITH u AS (UPDATE billing_options SET active = false, updated_at = now()
                      WHERE company_id = %s AND kind = %s AND active AND NOT (option_id = ANY(%s)) RETURNING 1)
           SELECT count(*) AS n FROM u""", (company_id, kind, [i["option_id"] for i in items]))
    return {"total": len(items), "inactive": gone["n"]}


def save_support(company_id: int, options: dict) -> dict:
    """The support page's lists ({kind: {id: name}}, from ISPDigital.support_options) into billing_options."""
    return {kind: save(company_id, kind, [{"option_id": str(k), "name": v} for k, v in items.items()])
            for kind, items in options.items()}


def sync(api, company_id: int, kinds: list[str] | None = None) -> dict:
    kinds = kinds or list(KINDS)
    bad = [k for k in kinds if k not in KINDS]
    if bad:
        raise ValueError(f"unknown list {', '.join(bad)}")
    out = {}
    if any(k in SUPPORT_KINDS for k in kinds):
        support = api.support_options()
        out.update(save_support(company_id, {k: v for k, v in support.items() if k in kinds}))
    for kind in kinds:
        if kind not in SUPPORT_KINDS:
            out[kind] = save(company_id, kind, fetch(api, kind))
    return out


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    p = argparse.ArgumentParser()
    p.add_argument("--company", type=int)
    p.add_argument("--kind", action="append")
    a = p.parse_args()
    from engine.ticket_sync import companies
    for t in companies(a.company):
        log.info("company %s: %s", t.company_id, sync(tenants.billing(t), t.company_id, a.kind))


if __name__ == "__main__":
    main()
