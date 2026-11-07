"""engine/desk_customer.py (billing phase 5, নতুন সংযোগ ও কাস্টমার): the Desk's rules for a new connection, a
package change and the MikroTik PPP secret. Pure functions, no database and no router, so they can be checked alone:
  PYTHONPATH=. python3 -m unittest engine.tests.test_desk_customer

engine/desk_connection.py uses them to send a new connection to billing (POST /Customer/Add) while billing is still
the main system (plan 2.4খ, "হস্তান্তরের আগে"); billing then makes the PPP secret on the router itself.
"""
import datetime as dt
import re

# Plan 2.4খ: Personal, Free and Left customers get no bill (Free keeps monthly bill 0 and expire 0).
NO_BILL_STATUSES = {"personal", "free", "left"}
REQUIRED = ("name", "mobile", "zone_id", "server_id", "connection_type_id", "package_id", "profile",
            "customer_type_id", "status_id", "username", "password", "joined", "bill_start")
BILLED_REQUIRED = ("monthly_bill", "expire_day")   # not for Personal / Free / Left
EXPIRE_MAX = 28
USERNAME_RE = re.compile(r"^[A-Za-z0-9._@-]{3,40}$")


def digits(value: str | None) -> str:
    return re.sub(r"\D", "", value or "")


def mobile_ok(value: str | None) -> bool:
    """11-13 digits, a +88 prefix allowed (plan 2.4খ)."""
    return 11 <= len(digits(value)) <= 13


def local_mobile(value: str | None) -> str:
    """01XXXXXXXXX, as billing stores it (the +88 / 88 country code taken off)."""
    d = digits(value)
    return d[2:] if d.startswith("880") and len(d) == 13 else d


def no_bill(status_name: str | None) -> bool:
    return (status_name or "").strip().lower() in NO_BILL_STATUSES


def username_suggestion(prefix: str | None, name: str | None, customer_id: int) -> str:
    """Proposal only: the area prefix (e.g. "bp.") + the first word of the name + the customer ID. The staff may type
    any other username; billing's own check says whether it is free."""
    first = re.sub(r"[^a-z0-9]", "", (name or "").split(" ")[0].lower())[:12]
    return f"{prefix or ''}{first}{customer_id}"


def default_expire_day(joined: dt.date) -> int:
    """Plan 2.4খ-5: connection day + 2, at most 28."""
    return min(joined.day + 2, EXPIRE_MAX)


def default_bill_start(joined: dt.date) -> str:
    """Plan 2.4খ-5: the bill starts the next month, as MM/YYYY (the billing form's Joining Date format)."""
    year, month = (joined.year + 1, 1) if joined.month == 12 else (joined.year, joined.month + 1)
    return f"{month:02d}/{year}"


def bill_for(status: str | None, package_price: int | float | str | None) -> int:
    """The monthly bill a status gets: Personal, Free and Left get none (Free keeps 0 as its monthly bill)."""
    if no_bill(status):
        return 0
    return int(float(package_price or 0))


def expire_for(status: str | None, expire_day: int | str | None) -> int:
    """Free and Personal lines have no expire day, so no bill is due on them."""
    if no_bill(status):
        return 0
    return int(expire_day or 0)


def validate_new(form: dict) -> list[str]:
    """Errors for a new connection form; an empty list means it may be sent."""
    keys = REQUIRED + (() if no_bill(form.get("status_name")) else BILLED_REQUIRED)
    errors = [f"{key}: লাগবে" for key in keys if not str(form.get(key) or "").strip()]
    if form.get("mobile") and not mobile_ok(form.get("mobile")):
        errors.append("mobile: ১১-১৩ অঙ্ক হতে হবে (+88 সহ)")
    if form.get("username") and not USERNAME_RE.match(str(form["username"]).strip()):
        errors.append("username: ৩-৪০ অক্ষর, শুধু ইংরেজি অক্ষর, সংখ্যা আর . _ - @")
    if not no_bill(form.get("status_name")) and str(form.get("expire_day") or "").strip():
        try:
            day = int(form["expire_day"])
        except (TypeError, ValueError):
            day = 0
        if not 1 <= day <= EXPIRE_MAX:
            errors.append(f"expire_day: ১ থেকে {EXPIRE_MAX} এর মধ্যে")
    if str(form.get("bill_start") or "").strip() and not re.match(r"^(0[1-9]|1[0-2])/\d{4}$", str(form["bill_start"]).strip()):
        errors.append("bill_start: MM/YYYY")
    if str(form.get("monthly_bill") or "").strip():
        try:
            if float(form["monthly_bill"]) < 0:
                errors.append("monthly_bill: ঋণাত্মক হবে না")
        except ValueError:
            errors.append("monthly_bill: সংখ্যা লাগবে")
    return errors


def billing_form(form: dict) -> dict:
    """The POST /Customer/Add fields for a validated new connection (the names billing's form uses; the tokens and
    ModulePermissionId are added by ISPDigital.create_customer). The password is in it: never log the result.
    Monthly bill and expire follow the status rule, Speed is the MikroTik profile, SecondaryJoiningDate is the
    connection day (dd/mm/YYYY) and JoiningDate the bill start month (MM/YYYY)."""
    status = form.get("status_name")
    joined = form["joined"] if isinstance(form["joined"], dt.date) else dt.date.fromisoformat(str(form["joined"])[:10])
    out = {
        "CustomerName": (form.get("name") or "").strip(),
        "MobileNumber": local_mobile(form.get("mobile")),
        "PhoneNumber": (form.get("phone") or "").strip(),
        "ServerId": str(form["server_id"]),
        "ProtocolId": str(form.get("protocol_id") or 30),
        "ZoneId": str(form["zone_id"]),
        "SubZoneId": str(form.get("sub_zone_id") or ""),
        "BoxId": str(form.get("box_id") or ""),
        "ConnectionTypeId": str(form["connection_type_id"]),
        "PackageId": str(form["package_id"]),
        "Speed": form["profile"],
        "MonthlyBillAmount": str(bill_for(status, form.get("monthly_bill"))),
        "CustomerTypeId": str(form["customer_type_id"]),
        "Status": str(form["status_id"]),
        "UserName": str(form["username"]).strip(),
        "Password": form["password"],
        "SecondaryJoiningDate": joined.strftime("%d/%m/%Y"),
        "JoiningDate": str(form["bill_start"]).strip(),
        "BillingLastDate": str(expire_for(status, form.get("expire_day"))),
        "Address": (form.get("address") or "").strip(),
        "DistrictId": str(form.get("district_id") or ""),
        "ThanaId": str(form.get("thana_id") or ""),
        "IsVIPClient": "true" if form.get("vip") else "false",
        "IsSmsSendable": "false",
        "Remarks": (form.get("remarks") or "").strip(),
    }
    return out


def safe_copy(form: dict) -> dict:
    """The form as the Desk keeps it (desk_connections.form): never the password."""
    return {k: v for k, v in form.items() if k.lower() not in ("password", "pass", "secret")}


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


def secret_matches(secret: dict | None, form: dict) -> str | None:
    """After billing saved the customer: why the router's secret is not as expected (None = fine).
    Billing makes the secret itself: present, the chosen profile, enabled unless Inactive; Left has none."""
    status = (form.get("status_name") or "").strip().lower()
    if status == "left":
        return "Left কাস্টমারের secret থাকার কথা নয়" if secret else None
    if not secret:
        return "রাউটারে PPP secret তৈরি হয়নি"
    if secret.get("profile") != form.get("profile"):
        return f"profile {secret.get('profile')} (চাওয়া হয়েছিল {form.get('profile')})"
    if status == "inactive" and not secret.get("disabled"):
        return "Inactive, কিন্তু secret চালু"
    if status != "inactive" and secret.get("disabled"):
        return "secret বন্ধ আছে"
    return None
