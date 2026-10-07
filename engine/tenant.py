"""Everything the engine needs about one company, loaded from the panel's tables."""
import threading
import time
from dataclasses import dataclass, field

from engine import db
from engine.ispdigital import ISPDigital


@dataclass
class Tenant:
    company_id: int
    name: str
    status: str
    wa_account_id: int
    phone_number_id: str
    waba_id: str
    wa_token: str
    bot_enabled: bool
    billing: tuple[str, str, str] | None          # base_url, username, password
    bot: dict = field(default_factory=dict)        # bot_settings row
    max_bot_replies: int | None = None

    @property
    def active(self) -> bool:
        return self.status in ("trial", "active") and self.bot_enabled


_cache: dict[str, tuple[float, Tenant | None]] = {}
_billing_clients: dict[int, tuple[tuple, ISPDigital]] = {}
_lock = threading.Lock()
TTL = 60  # seconds; settings changed in the panel apply within a minute


def by_phone_number_id(phone_number_id: str) -> Tenant | None:
    now = time.time()
    with _lock:
        hit = _cache.get(phone_number_id)
        if hit and now - hit[0] < TTL:
            return hit[1]
    row = db.one(
        """SELECT w.id AS wa_id, w.phone_number_id, w.waba_id, w.access_token, w.bot_enabled,
                  c.id AS company_id, c.name, c.status, p.max_bot_replies
           FROM wa_accounts w JOIN companies c ON c.id = w.company_id
           LEFT JOIN plans p ON p.id = c.plan_id
           WHERE w.phone_number_id = %s""",
        (phone_number_id,),
    )
    tenant = None
    if row:
        b = db.one("SELECT base_url, username, password FROM billing_connections WHERE company_id = %s",
                   (row["company_id"],))
        bot = db.one("SELECT * FROM bot_settings WHERE company_id = %s", (row["company_id"],)) or {}
        tenant = Tenant(
            company_id=row["company_id"], name=row["name"], status=row["status"],
            wa_account_id=row["wa_id"], phone_number_id=row["phone_number_id"], waba_id=row["waba_id"],
            wa_token=db.decrypt(row["access_token"]), bot_enabled=row["bot_enabled"],
            billing=(b["base_url"], b["username"], db.decrypt(b["password"])) if b else None,
            bot=dict(bot), max_bot_replies=row["max_bot_replies"],
        )
    with _lock:
        _cache[phone_number_id] = (now, tenant)
    return tenant


def billing(t: Tenant) -> ISPDigital:
    """One logged-in billing client per company, rebuilt when its credentials change."""
    if not t.billing:
        raise RuntimeError("billing connection not set up for this company")
    with _lock:
        hit = _billing_clients.get(t.company_id)
        if hit and hit[0] == t.billing:
            return hit[1]
        client = ISPDigital(*t.billing)
        _billing_clients[t.company_id] = (t.billing, client)
        return client


_user_clients: dict[tuple[int, int], tuple[tuple, ISPDigital]] = {}


class NoBillingLogin(RuntimeError):
    pass


def billing_for_user(t: Tenant, user_id: int) -> ISPDigital:
    """The member's own billing login (tickets they open/assign show their name in the billing software).
    An owner without a personal login falls back to the company login they configured."""
    if not t.billing:
        raise RuntimeError("billing connection not set up for this company")
    row = db.one("SELECT role, billing_username, billing_password FROM company_user WHERE company_id = %s AND user_id = %s",
                 (t.company_id, user_id))
    if not row:
        raise NoBillingLogin("আপনি এই কোম্পানির সদস্য নন")
    if not (row["billing_username"] and row["billing_password"]):
        if row["role"] == "owner":
            return billing(t)
        raise NoBillingLogin("আপনার বিলিং সফটওয়্যারের লগইন দেওয়া নেই। সেটিংস → \"আমার বিলিং লগইন\" পেজে দিন।")
    creds = (t.billing[0], row["billing_username"], db.decrypt(row["billing_password"]))
    with _lock:
        hit = _user_clients.get((t.company_id, user_id))
        if hit and hit[0] == creds:
            return hit[1]
        client = ISPDigital(*creds)
        _user_clients[(t.company_id, user_id)] = (creds, client)
        return client


def bot_replies_this_month(t: Tenant) -> int:
    row = db.one(
        """SELECT COUNT(*) AS n FROM wa_drafts
           WHERE company_id = %s AND mode = 'sent' AND created_at >= date_trunc('month', now())""",
        (t.company_id,),
    )
    return row["n"] if row else 0
