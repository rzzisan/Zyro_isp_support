"""engine/desk_customer.py (billing phase 5, নতুন সংযোগ ও কাস্টমার): the Desk's rules for a new connection, a
package change and the MikroTik PPP secret. Pure functions, no database and no router, so they can be checked alone:
  PYTHONPATH=. python3 -m unittest engine.tests.test_desk_customer

Nothing here writes to billing or to a router yet. Before the cutover billing is still the main system, so the
billing POST /Customer/Add and the secret changes are only switched on after a test customer is run through them
(plan 2.4খ, "হস্তান্তরের আগে"). The rules here decide what that write should contain.
"""
import datetime as dt
import re

# Plan 2.4খ: Personal, Free and Left customers get no bill (Free keeps monthly bill 0 and expire 0).
NO_BILL_STATUSES = {"personal", "free", "left"}
REQUIRED = ("name", "mobile", "zone", "router", "connection_type", "package", "monthly_bill", "customer_type",
            "status", "username", "password", "joined", "bill_start", "expire_day")
EXPIRE_MAX = 28


def digits(value: str | None) -> str:
    return re.sub(r"\D", "", value or "")


def mobile_ok(value: str | None) -> bool:
    """11-13 digits, a +88 prefix allowed (plan 2.4খ)."""
    return 11 <= len(digits(value)) <= 13


def username_suggestion(prefix: str | None, name: str | None, customer_id: int) -> str:
    """Proposal only: the area prefix (e.g. "bp.") + the first word of the name + the customer ID. The staff may type
    any other username; the Desk checks it is free on all three routers before saving."""
    first = re.sub(r"[^a-z0-9]", "", (name or "").split(" ")[0].lower())[:12]
    return f"{prefix or ''}{first}{customer_id}"


def default_expire_day(joined: dt.date) -> int:
    """Plan 2.4খ-5: connection day + 2, at most 28."""
    return min(joined.day + 2, EXPIRE_MAX)


def default_bill_start(joined: dt.date) -> str:
    """Plan 2.4খ-5: the bill starts the next month, as MM/YYYY (the billing form's Joining Date format)."""
    year, month = (joined.year + 1, 1) if joined.month == 12 else (joined.year, joined.month + 1)
    return f"{month:02d}/{year}"


def bill_for(status: str | None, package_price: int | float | None) -> int:
    """The monthly bill a status gets: Personal, Free and Left get none (Free keeps 0 as its monthly bill)."""
    if (status or "").strip().lower() in NO_BILL_STATUSES:
        return 0
    return int(package_price or 0)


def expire_for(status: str | None, expire_day: int | None) -> int:
    """Free and Personal lines have no expire day, so no bill is due on them."""
    if (status or "").strip().lower() in NO_BILL_STATUSES:
        return 0
    return int(expire_day or 0)


def validate_new(form: dict) -> list[str]:
    """Errors for a new connection form; an empty list means it may be saved."""
    errors = [f"{key}: লাগবে" for key in REQUIRED if not str(form.get(key) or "").strip()]
    if form.get("mobile") and not mobile_ok(form.get("mobile")):
        errors.append("mobile: ১১-১৩ অঙ্ক হতে হবে (+88 সহ)")
    try:
        day = int(form.get("expire_day") or 0)
    except (TypeError, ValueError):
        day = 0
    if form.get("expire_day") and not 1 <= day <= EXPIRE_MAX:
        errors.append(f"expire_day: ১ থেকে {EXPIRE_MAX} এর মধ্যে")
    return errors


def billing_form(form: dict) -> dict:
    """The POST /Customer/Add fields for a validated new connection (plan 2.4খ field names). Only the fields the
    billing form needs; the password is passed through and must never be logged by the caller.
    Monthly bill and expire follow the status rule, Speed is the package's MikroTik profile, and the joining date is
    the billing's own "Joining Date" (MM/YYYY), which is the bill start month."""
    status = form.get("status") or ""
    return {
        "CustomerName": (form.get("name") or "").strip(),
        "MobileNumber": digits(form.get("mobile")),
        "Zone": form.get("zone"),
        "SubZone": form.get("sub_zone") or "",
        "Server": form.get("router"),
        "Protocol": "PPPoE",
        "ConnectionType": form.get("connection_type"),
        "Package": form.get("package"),
        "MonthlyBill": bill_for(status, form.get("monthly_bill")),
        "Speed": form.get("profile") or "",
        "CustomerType": form.get("customer_type"),
        "Status": status,
        "UserName": (form.get("username") or "").strip(),
        "Password": form.get("password") or "",
        "JoiningDate": form.get("bill_start"),
        "BillingLastDate": expire_for(status, form.get("expire_day")),
    }


def secret_actions(current: dict | None, want: dict) -> list[str]:
    """What must change on a router's PPP secret to match the customer's status (plan 2.7).
    current: the secret as the router has it ({"profile", "disabled"}) or None when there is no secret.
    want:    {"status", "profile"} where profile is the package's MikroTik profile (or the block profile).
    Returns a list of steps: "create", "remove", "set_profile:<name>", "disable", "enable"."""
    status = (want.get("status") or "").strip().lower()
    if status == "left":
        return ["remove"] if current else []
    if current is None:
        steps = ["create"]
        if status == "inactive":
            steps.append("disable")
        return steps
    steps = []
    if want.get("profile") and current.get("profile") != want["profile"]:
        steps.append(f"set_profile:{want['profile']}")
    if status == "inactive" and not current.get("disabled"):
        steps.append("disable")
    if status != "inactive" and current.get("disabled"):
        steps.append("enable")
    return steps
