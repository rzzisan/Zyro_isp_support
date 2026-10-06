"""Zyro ISP Support — WhatsApp Cloud API webhook + admin dashboard."""
import hashlib
import hmac
import html
import json
import logging
import os
import re
import threading
from datetime import datetime, timedelta, timezone

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import HTMLResponse, PlainTextResponse, RedirectResponse
from starlette.middleware.sessions import SessionMiddleware

from app import store

VERIFY_TOKEN = os.environ.get("WA_VERIFY_TOKEN", "")
APP_SECRET = os.environ.get("META_APP_SECRET", "")

log = logging.getLogger("zyro-support")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
app.add_middleware(
    SessionMiddleware,
    secret_key=os.environ.get("SESSION_SECRET", ""),
    session_cookie="zyro_admin",
    max_age=12 * 3600,
    same_site="strict",
    https_only=True,
)

from app.admin import router as admin_router  # noqa: E402

app.include_router(admin_router)


@app.get("/")
def root():
    return RedirectResponse("/admin", status_code=302)


@app.get("/health")
def health():
    return {"ok": True}


@app.get("/meta/callback")
def meta_callback(request: Request):
    """Redirect target of the Meta-hosted Embedded Signup. The number is connected on Meta's side;
    we only note that the flow finished (the code itself is not needed for our own WABA)."""
    q = request.query_params
    log.info("embedded signup callback: code=%s error=%s", "yes" if q.get("code") else "no", q.get("error"))
    ok = not q.get("error")
    text = "WhatsApp সংযোগ সম্পন্ন হয়েছে। এই পেজ বন্ধ করে দিতে পারেন।" if ok else "সংযোগ সম্পন্ন হয়নি: " + q.get("error_description", q.get("error", ""))
    return HTMLResponse(f'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;padding:40px;text-align:center"><h2>{html.escape(text)}</h2></body>')


@app.get("/webhook")
def verify(request: Request):
    q = request.query_params
    if (
        q.get("hub.mode") == "subscribe"
        and VERIFY_TOKEN
        and hmac.compare_digest(q.get("hub.verify_token", ""), VERIFY_TOKEN)
    ):
        return PlainTextResponse(q.get("hub.challenge", ""))
    raise HTTPException(status_code=403)


def signature_ok(raw: bytes, header: str) -> bool:
    if not APP_SECRET or not header.startswith("sha256="):
        return False
    expected = hmac.new(APP_SECRET.encode(), raw, hashlib.sha256).hexdigest()
    return hmac.compare_digest(header[7:], expected)


@app.post("/webhook")
async def receive(request: Request):
    raw = await request.body()
    if not signature_ok(raw, request.headers.get("x-hub-signature-256", "")):
        log.warning("rejected webhook: bad or missing signature")
        raise HTTPException(status_code=403)

    payload = json.loads(raw)
    now = store.now()
    to_handle = []
    with store.connect() as conn:
        conn.execute("INSERT INTO events (received_at, payload) VALUES (?, ?)", (now, raw.decode()))
        for entry in payload.get("entry", []):
            for change in entry.get("changes", []):
                field = change.get("field")
                value = change.get("value", {})
                # "messages" = customer -> us; "smb_message_echoes" = sent from the Business app (Coexistence)
                if field not in ("messages", "smb_message_echoes"):
                    continue
                echo = int(field == "smb_message_echoes")
                phone_number_id = value.get("metadata", {}).get("phone_number_id")
                names = {c.get("wa_id"): c.get("profile", {}).get("name") for c in value.get("contacts", [])}
                for m in value.get("messages", []) + value.get("message_echoes", []):
                    body = (m.get("text") or {}).get("body")
                    # from_number column = the customer's number (echoes go business -> customer)
                    customer = m.get("to") if echo else m.get("from")
                    cur = conn.execute(
                        "INSERT OR IGNORE INTO messages VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        (m.get("id"), now, phone_number_id, customer,
                         names.get(customer), m.get("type"), body, echo),
                    )
                    if not echo and cur.rowcount:  # skip Meta retries of the same message
                        to_handle.append((m, names.get(customer), phone_number_id))
    # billing lookup + AI take seconds; answer Meta immediately
    for m, name, pnid in to_handle:
        threading.Thread(target=handle_message, args=(m, name, pnid), daemon=True).start()
    return {"ok": True}


_billing = None
_billing_lock = threading.Lock()


def billing():
    global _billing
    with _billing_lock:
        if _billing is None:
            from app.ispdigital import ISPDigital
            _billing = ISPDigital()
        return _billing


def reset_billing() -> None:
    """Called after the admin changes billing credentials."""
    global _billing
    with _billing_lock:
        _billing = None


def recent_history(conn, wa_number: str, limit: int = 12) -> list[dict]:
    rows = conn.execute(
        """SELECT echo, body FROM messages WHERE from_number = ? AND msg_type IN ('text', 'audio') AND body IS NOT NULL
           ORDER BY received_at DESC LIMIT ?""",
        (wa_number, limit),
    ).fetchall()
    history = [{"role": "assistant" if r["echo"] else "user", "content": r["body"]} for r in reversed(rows)]
    # the AI needs alternating turns starting with user; merge consecutive same-role messages
    merged: list[dict] = []
    for h in history:
        if merged and merged[-1]["role"] == h["role"]:
            merged[-1]["content"] += "\n" + h["content"]
        else:
            merged.append(dict(h))
    while merged and merged[0]["role"] != "user":
        merged.pop(0)
    return merged


def open_ticket(customer: dict, wa: str, note: str) -> tuple[str, str | None]:
    """Open a support ticket in ISP Digital (no SMS). Returns (dashboard note, line to add to the reply)."""
    from app.ispdigital import normalize_bd_mobile
    category, _, detail = note.partition("|")
    category, detail = category.strip(), (detail.strip() or category.strip())
    try:
        existing = billing().open_tickets_for(customer.get("UserName") or "")
        if existing:
            no = existing[0].get("ComplainId")
            return f"{note} → আগেই খোলা টিকেট আছে (#{no}), নতুন খোলা হয়নি", \
                f"আপনার আগের অভিযোগটা (নম্বর {no}) এখনো খোলা আছে, টিম সেটা দেখছে।"
        _, cats = billing().ticket_form()
        cid = cats.get(category) or next((v for k, v in cats.items() if category and category.lower() in k.lower()), None) \
            or cats.get("Others Support")
        mobile = normalize_bd_mobile(customer.get("MobileNumber") or "") or normalize_bd_mobile(wa)
        msg = billing().create_ticket(customer["CustomerHeaderId"], cid, 2, mobile,
                                      f"[WhatsApp বট] {detail}\nকাস্টমার WhatsApp: {wa}", send_sms=False)
        created = billing().open_tickets_for(customer.get("UserName") or "")
        no = created[0].get("ComplainId") if created else None
        return f"{note} → টিকেট খোলা হয়েছে #{no or '?'} ({msg})", (f"আপনার অভিযোগ নম্বর: {no}" if no else None)
    except Exception as e:
        log.exception("ticket failed")
        return f"{note} → টিকেট খোলা যায়নি: {str(e)[:200]}", None


def should_send(mode: str, wa: str) -> bool:
    """live = reply automatically, but only to allow-listed numbers when a list is set."""
    if mode != "live":
        return False
    allow = [n.strip() for n in (store.get_setting("live_allowlist") or "").replace("\n", ",").split(",") if n.strip()]
    if not allow:
        return True
    from app.ispdigital import normalize_bd_mobile
    return normalize_bd_mobile(wa) in {normalize_bd_mobile(n) for n in allow}


def bot_paused(wa: str) -> bool:
    """Per-number pause set from the conversation page (or automatically when staff replies)."""
    until = store.get_setting(f"pause:{wa}")
    if not until:
        return False
    if until == "on":
        return True
    try:
        return datetime.fromisoformat(until) > datetime.now(timezone.utc)
    except ValueError:
        return False


def bot_serves(phone_number_id: str | None) -> bool:
    """Only the business numbers chosen on the WhatsApp page get the bot (others are just logged)."""
    ids = [x.strip() for x in (store.get_setting("bot_phone_ids") or "").split(",") if x.strip()]
    return not ids or (phone_number_id or "") in ids


YES_WORDS = ("হ্যাঁ", "হ্যা", "হা", "জি", "জ্বি", "জী", "ঠিক", "yes", "ha", "hae", "hea", "hm", "hmm", "ji", "jee", "ok",
             "thik", "right", "correct", "y")
NO_WORDS = ("না", "নাহ", "no", "na", "nah", "not", "noi", "nai", "n")

ASK_ID_TEXT = ("আপনি লাইন নেওয়ার সময় যে ফোন নম্বর দিয়েছিলেন সেই নম্বরটা দিন, "
               "অথবা কাস্টমার ID জানা থাকলে বলুন।")
AGENT_TEXT = "ঠিক আছে ভাই, শিগগিরই আমাদের একজন সাপোর্ট এজেন্ট আপনার সাথে যোগাযোগ করবেন।"


def _yes_no(text: str) -> str | None:
    t = re.sub(r"[^\wঀ-৿ ]", " ", (text or "").lower()).split()
    if not t:
        return None
    if t[0] in NO_WORDS or "না" in t[:3]:
        return "no"
    if t[0] in YES_WORDS:
        return "yes"
    return None


def identify(wa: str, text: str) -> tuple[dict | None, str | None, str | None, str | None]:
    """Who is this customer? Returns (customer, fixed_reply, pending_question, agent_note).

    1. WhatsApp number matches a customer -> ask them to confirm that line by name.
    2. Not matched / said no -> ask for the phone number given at connection time or the customer ID.
    3. Still nothing after a second try -> tell them an agent will contact them (and pause the bot).
    An ID/phone typed at any point identifies the line directly.
    """
    from app.ispdigital import find_customer_by_text, find_customer_by_whatsapp

    key = f"state:{wa}"
    try:
        state = json.loads(store.get_setting(key) or "{}")
    except ValueError:
        state = {}
    # a conversation that went quiet for a day starts over
    if state.get("at") and datetime.fromisoformat(state["at"]) < datetime.now(timezone.utc) - timedelta(hours=24):
        state = {}
    stage = state.get("stage", "new")

    def save(**kw):
        store.set_setting(key, json.dumps({**kw, "at": datetime.now(timezone.utc).isoformat()}, ensure_ascii=False))

    def confirmed(customer, pending=None):
        save(stage="ok", customer_id=customer.get("CustomerId"))
        store.set_setting(f"link:{wa}", customer.get("CustomerId") or "")
        return customer, None, pending, None

    # an ID / phone / username in the message always wins (customer may ask about another line)
    typed = find_customer_by_text(billing(), text, allow_bare_id=stage in ("ask_id", "confirm"))
    if typed:
        return confirmed(typed, state.get("pending"))

    if stage == "ok" and state.get("customer_id"):
        customer = find_customer_by_text(billing(), state["customer_id"])
        if customer:
            return confirmed(customer)

    if stage == "confirm":
        answer = _yes_no(text)
        if answer == "yes":
            customer = find_customer_by_text(billing(), state.get("candidate", ""))
            if customer:
                return confirmed(customer, state.get("pending"))
        if answer is None and not state.get("reasked"):
            save(**{**state, "reasked": True})
            return None, f"জি ভাই, আপনি কি *{state.get('candidate_name')}* (ID {state.get('candidate')}) লাইনের বিষয়ে বলছেন? হ্যাঁ বা না লিখুন।", None, None
        save(stage="ask_id", tries=1, pending=state.get("pending"))
        return None, ASK_ID_TEXT, None, None

    if stage == "ask_id":
        tries = state.get("tries", 1)
        if tries >= 2:
            save(stage="agent", pending=state.get("pending"))
            return None, AGENT_TEXT, None, "কাস্টমার শনাক্ত করা যায়নি, এজেন্ট যোগাযোগ করবেন"
        save(stage="ask_id", tries=tries + 1, pending=state.get("pending"))
        return None, "দুঃখিত ভাই, এই তথ্য দিয়ে আপনার লাইন খুঁজে পাইনি। " + ASK_ID_TEXT, None, None

    if stage == "agent":
        return None, None, None, None  # bot is paused for this number; nothing to say

    # new conversation
    own = find_customer_by_whatsapp(billing(), wa)
    if own:
        save(stage="confirm", candidate=own.get("CustomerId"), candidate_name=own.get("CustomerName"), pending=text)
        return None, (f"আসসালামু আলাইকুম। আপনি কি *{own.get('CustomerName')}* (ID {own.get('CustomerId')}) "
                      "লাইনের বিষয়ে কথা বলছেন? হ্যাঁ বা না লিখুন।"), None, None
    save(stage="ask_id", tries=1, pending=text)
    return None, "আসসালামু আলাইকুম। " + ASK_ID_TEXT, None, None


def send_fixed(message: dict, wa: str, phone_number_id: str | None, mode: str, text: str, note: str | None) -> None:
    """A fixed reply (no AI), recorded like a draft."""
    signature = store.get_setting("reply_signature", "- Zyro")
    body = text + (f"\n\n{signature}" if signature else "")
    error = None
    try:
        if should_send(mode, wa):
            from app import whatsapp
            res = whatsapp.send_text(wa, body, phone_number_id)
            mode = "sent"
            with store.connect() as conn:
                conn.execute("INSERT OR IGNORE INTO messages VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                             (res.get("messages", [{}])[0].get("id"), store.now(), None, wa, None, "text", body, 1))
    except Exception as e:
        error = str(e)[:500]
    with store.connect() as conn:
        conn.execute(
            """INSERT INTO drafts (wa_message_id, created_at, from_number, context, draft, mode, provider, model, error, ticket_note)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (message.get("id"), store.now(), wa, None, body, mode, "flow", "fixed", error, note))


def handle_message(message: dict, contact_name: str | None, phone_number_id: str | None = None) -> None:
    """Identify customer, check billing/ONU/PPPoE, draft a reply (shadow mode stores it only)."""
    wa = message.get("from")
    log.info("message from %s (%s): type=%s", wa, contact_name, message.get("type"))
    mode = store.get_setting("bot_mode", "shadow")
    mtype = message.get("type")
    if mode == "off" or not bot_serves(phone_number_id) or bot_paused(wa):
        return
    if mtype not in ("text", "audio", "image", "video", "document"):
        return
    context = draft = error = provider = model = ticket_note = None
    if mtype in ("image", "video", "document"):
        caption = ((message.get(mtype) or {}).get("caption") or "").strip()
        if not caption:
            send_fixed(message, wa, phone_number_id, mode,
                       "পেয়েছি ভাই, আমাদের টিম দেখে নেবে। সমস্যাটা একটু লিখে জানালে দ্রুত সাহায্য করতে পারব।",
                       f"কাস্টমার {mtype} পাঠিয়েছে, টিম দেখবে")
            return
        message = {**message, "type": "text", "text": {"body": caption}}
    if mtype == "audio":
        try:
            from app import whatsapp
            from app.agent import transcribe
            audio, mime = whatsapp.download_media((message.get("audio") or {}).get("id", ""))
            spoken = transcribe(audio, mime)
        except Exception:
            log.exception("voice transcription failed")
            spoken = None
        if not spoken or len(spoken) < 2:
            send_fixed(message, wa, phone_number_id, mode,
                       "ভাই, ভয়েসটা ঠিক বুঝতে পারিনি। একটু লিখে জানাবেন?", None)
            return
        with store.connect() as conn:
            conn.execute("UPDATE messages SET body = ? WHERE wa_message_id = ?", (f"[ভয়েস] {spoken}", message.get("id")))
        message = {**message, "type": "text", "text": {"body": spoken}}
    try:
        from app.agent import draft_reply
        from app.ispdigital import diagnose, find_customer_by_text, find_customer_by_whatsapp

        with store.connect() as conn:
            history = recent_history(conn, wa)
        current_text = (message.get("text") or {}).get("body", "")
        customer, fixed, pending, ticket_note = identify(wa, current_text)
        if not customer and not fixed:
            return  # waiting for a human agent on this number
        if fixed:
            # identification step: a fixed message, no AI
            draft, provider, model = fixed, "flow", "identify"
            if ticket_note:
                store.set_setting(f"pause:{wa}", "on")  # hand over to a human agent
        else:
            context = diagnose(billing(), customer)
            if pending:
                # answer the question the customer asked before we confirmed who they are
                history = [{"role": "user", "content": pending + "\n" + current_text}]
            elif not history or history[-1]["role"] != "user":
                history.append({"role": "user", "content": current_text})
            draft, provider, model = draft_reply(history, context)
        if draft and not fixed:
            # [[TICKET: ...]] marker = the AI wants the technician team to follow up
            m = re.search(r"\[\[TICKET:\s*(.*?)\]\]", draft, re.S)
            if m:
                ticket_note = m.group(1).strip()[:300]
                draft = (draft[:m.start()] + draft[m.end():]).strip()
        if draft and not fixed and ticket_note and customer and should_send(mode, wa) \
                and store.get_setting("auto_ticket", "on") == "on":
            ticket_note, extra = open_ticket(customer, wa, ticket_note)
            if extra:
                draft = draft + "\n" + extra
        signature = store.get_setting("reply_signature", "- Zyro")
        if draft and signature and not draft.rstrip().endswith(signature):
            draft = draft.rstrip() + "\n\n" + signature
        if draft and should_send(mode, wa):
            from app import whatsapp
            res = whatsapp.send_text(wa, draft, phone_number_id)
            mode = "sent"
            with store.connect() as conn:
                # keep our reply in the history so the next turn has context
                conn.execute("INSERT OR IGNORE INTO messages VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                             (res.get("messages", [{}])[0].get("id"), store.now(), None, wa, None, "text", draft, 1))
    except Exception as e:
        log.exception("draft failed for %s", message.get("id"))
        error = str(e)[:500]
    with store.connect() as conn:
        conn.execute(
            """INSERT INTO drafts (wa_message_id, created_at, from_number, context, draft, mode, provider, model, error, ticket_note)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (message.get("id"), store.now(), wa, json.dumps(context, ensure_ascii=False) if context else None,
             draft, mode, provider, model, error, ticket_note),
        )
