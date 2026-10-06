"""Admin dashboard: billing credentials, AI providers/keys, default provider, drafts."""
import hmac
import json
import os
import time
from collections import defaultdict
from urllib.parse import urlparse

from fastapi import APIRouter, Form, HTTPException, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates

from app import store
from app.agent import PROVIDERS, active_config, generate, list_models

router = APIRouter(prefix="/admin")
templates = Jinja2Templates(directory=str(store.BASE_DIR / "templates"))
templates.env.filters["fromjson"] = json.loads

SETUP_TOKEN = os.environ.get("ADMIN_SETUP_TOKEN", "")
_failed_logins: dict[str, list[float]] = defaultdict(list)


# --- helpers ---------------------------------------------------------------------
def client_ip(request: Request) -> str:
    return request.headers.get("x-forwarded-for", request.client.host if request.client else "").split(",")[0].strip()


def check_origin(request: Request) -> None:
    """CSRF guard for POSTs: the form must come from this site."""
    origin = request.headers.get("origin") or request.headers.get("referer") or ""
    if urlparse(origin).netloc != request.headers.get("host"):
        raise HTTPException(status_code=403, detail="bad origin")


def current_user(request: Request) -> str | None:
    return request.session.get("user")


def flash(request: Request, text: str, kind: str = "ok") -> None:
    request.session.setdefault("flash", []).append({"text": text, "kind": kind})


def render(request: Request, template: str, **ctx) -> HTMLResponse:
    messages = request.session.pop("flash", [])
    return templates.TemplateResponse(request, template, {"user": current_user(request), "flash": messages, **ctx})


def require_login(request: Request) -> RedirectResponse | None:
    if not current_user(request):
        return RedirectResponse("/admin/setup" if store.admin_count() == 0 else "/admin/login", status_code=302)
    return None


def back(path: str) -> RedirectResponse:
    return RedirectResponse(path, status_code=303)


# large one-shot results live server-side; the (cookie) session only holds an id
_scratch: dict[str, object] = {}


def stash(request: Request, name: str, value) -> None:
    sid = os.urandom(8).hex()
    if len(_scratch) > 200:
        _scratch.clear()
    _scratch[sid] = value
    request.session[name] = sid


def unstash(request: Request, name: str):
    sid = request.session.pop(name, None)
    return _scratch.pop(sid, None) if sid else None


# --- setup / login -----------------------------------------------------------------
@router.get("/setup", response_class=HTMLResponse)
def setup_form(request: Request):
    if store.admin_count() > 0:
        return back("/admin/login")
    return render(request, "setup.html")


@router.post("/setup")
def setup(request: Request, token: str = Form(...), username: str = Form(...),
          password: str = Form(...), password2: str = Form(...)):
    check_origin(request)
    if store.admin_count() > 0:
        return back("/admin/login")
    if not SETUP_TOKEN or not hmac.compare_digest(token.strip(), SETUP_TOKEN):
        flash(request, "সেটআপ টোকেন ভুল।", "err")
        return back("/admin/setup")
    if len(password) < 10 or password != password2:
        flash(request, "পাসওয়ার্ড কমপক্ষে ১০ অক্ষরের হতে হবে এবং দুইবার একই হতে হবে।", "err")
        return back("/admin/setup")
    with store.connect() as conn:
        conn.execute("INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)",
                     (username.strip(), store.hash_password(password), store.now()))
    request.session["user"] = username.strip()
    flash(request, "অ্যাডমিন অ্যাকাউন্ট তৈরি হয়েছে।")
    return back("/admin")


@router.get("/login", response_class=HTMLResponse)
def login_form(request: Request):
    if store.admin_count() == 0:
        return back("/admin/setup")
    return render(request, "login.html")


@router.post("/login")
def login(request: Request, username: str = Form(...), password: str = Form(...)):
    check_origin(request)
    ip = client_ip(request)
    recent = [t for t in _failed_logins[ip] if t > time.time() - 600]
    _failed_logins[ip] = recent
    if len(recent) >= 5:
        flash(request, "অনেকবার ভুল চেষ্টা হয়েছে, ১০ মিনিট পরে আবার চেষ্টা করুন।", "err")
        return back("/admin/login")
    with store.connect() as conn:
        row = conn.execute("SELECT * FROM admin_users WHERE username = ?", (username.strip(),)).fetchone()
    if not row or not store.verify_password(password, row["password_hash"]):
        _failed_logins[ip].append(time.time())
        flash(request, "ইউজারনেম বা পাসওয়ার্ড ভুল।", "err")
        return back("/admin/login")
    request.session.clear()
    request.session["user"] = row["username"]
    return back("/admin")


@router.post("/logout")
def logout(request: Request):
    check_origin(request)
    request.session.clear()
    return back("/admin/login")


# --- overview ----------------------------------------------------------------------
@router.get("", response_class=HTMLResponse)
def overview(request: Request):
    if (r := require_login(request)):
        return r
    provider, model = active_config()
    with store.connect() as conn:
        stats = {
            "messages": conn.execute("SELECT COUNT(*) FROM messages WHERE echo = 0").fetchone()[0],
            "drafts": conn.execute("SELECT COUNT(*) FROM drafts").fetchone()[0],
            "errors": conn.execute("SELECT COUNT(*) FROM drafts WHERE error IS NOT NULL").fetchone()[0],
            "ai_keys": conn.execute("SELECT COUNT(*) FROM ai_keys WHERE provider = ?", (provider,)).fetchone()[0],
        }
        drafts = conn.execute(
            """SELECT d.*, m.body AS customer_text, m.contact_name FROM drafts d
               LEFT JOIN messages m ON m.wa_message_id = d.wa_message_id
               ORDER BY d.id DESC LIMIT 30"""
        ).fetchall()
    from app.ispdigital import config
    _, billing_user, billing_pass = config()
    return render(request, "overview.html", stats=stats, drafts=drafts,
                  provider=PROVIDERS.get(provider, {}).get("name", provider), model=model,
                  bot_mode=store.get_setting("bot_mode", "shadow"),
                  billing_ok=bool(billing_user and billing_pass))


# --- conversations, grouped by WhatsApp number ------------------------------------------
@router.get("/chats", response_class=HTMLResponse)
def chats(request: Request, q: str = ""):
    if (r := require_login(request)):
        return r
    with store.connect() as conn:
        rows = conn.execute(
            """SELECT from_number,
                      MAX(received_at) AS last_at,
                      SUM(CASE WHEN echo = 0 THEN 1 ELSE 0 END) AS incoming,
                      SUM(CASE WHEN echo = 1 THEN 1 ELSE 0 END) AS outgoing,
                      MAX(contact_name) AS name
               FROM messages WHERE from_number IS NOT NULL
               GROUP BY from_number ORDER BY last_at DESC LIMIT 300"""
        ).fetchall()
        last = {r["from_number"]: r["body"] for r in conn.execute(
            """SELECT m.from_number, m.body FROM messages m
               JOIN (SELECT from_number, MAX(received_at) t FROM messages GROUP BY from_number) x
                 ON x.from_number = m.from_number AND x.t = m.received_at""")}
        tickets = {r["from_number"]: r["n"] for r in conn.execute(
            "SELECT from_number, COUNT(*) n FROM drafts WHERE ticket_note IS NOT NULL GROUP BY from_number")}
        links = {r["key"][5:]: r["value"] for r in conn.execute("SELECT key, value FROM settings WHERE key LIKE 'link:%'")}
    items = [dict(r, last=last.get(r["from_number"]), tickets=tickets.get(r["from_number"], 0),
                  customer_id=links.get(r["from_number"])) for r in rows]
    if q.strip():
        qq = q.strip().lower()
        items = [i for i in items if qq in (i["from_number"] or "") or qq in (i["name"] or "").lower()
                 or qq == (i["customer_id"] or "").lstrip("0") or qq == (i["customer_id"] or "")]
    return render(request, "chats.html", items=items, q=q)


@router.get("/chats/{number}", response_class=HTMLResponse)
def chat_thread(request: Request, number: str):
    if (r := require_login(request)):
        return r
    with store.connect() as conn:
        msgs = conn.execute(
            "SELECT * FROM messages WHERE from_number = ? ORDER BY received_at", (number,)).fetchall()
        drafts = {d["wa_message_id"]: d for d in conn.execute(
            "SELECT * FROM drafts WHERE from_number = ?", (number,))}
        link = conn.execute("SELECT value FROM settings WHERE key = ?", (f"link:{number}",)).fetchone()
    items = []
    for m in msgs:
        d = drafts.get(m["wa_message_id"])
        items.append({
            "at": m["received_at"], "body": m["body"], "type": m["msg_type"],
            "id": m["wa_message_id"], "has_media": bool(m["media_id"]),
            "who": "customer" if not m["echo"] else ("staff" if m["phone_number_id"] else "bot"),
            "draft": d["draft"] if d and d["mode"] != "sent" else None,
            "draft_mode": d["mode"] if d else None,
            "error": d["error"] if d else None,
            "ticket": d["ticket_note"] if d else None,
            "ctx": json.loads(d["context"]) if d and d["context"] else None,
        })
    name = next((m["contact_name"] for m in msgs if m["contact_name"]), None)
    from app.main import bot_paused
    pause = store.get_setting(f"pause:{number}")
    last_in = next((m["received_at"] for m in reversed(msgs) if not m["echo"]), None)
    from datetime import datetime, timedelta, timezone
    window_open = bool(last_in) and datetime.fromisoformat(last_in) > datetime.now(timezone.utc) - timedelta(hours=24)
    for it, m in zip(items, msgs):
        pid = m["phone_number_id"] or ""
        it["staff_name"] = pid.split(":", 1)[1] if pid.startswith("dashboard:") else None
    return render(request, "chat_thread.html", number=number, name=name, items=items,
                  customer_id=link["value"] if link else None, paused=bot_paused(number),
                  pause_value=pause, window_open=window_open)


@router.get("/media/{wa_message_id}")
def media(request: Request, wa_message_id: str):
    """Voice notes / images customers sent, fetched from WhatsApp once and cached on disk."""
    if not current_user(request):
        raise HTTPException(status_code=401)
    from fastapi.responses import FileResponse
    from app import whatsapp
    with store.connect() as conn:
        row = conn.execute("SELECT media_id, media_mime FROM messages WHERE wa_message_id = ?",
                           (wa_message_id,)).fetchone()
    if not row or not row["media_id"]:
        raise HTTPException(status_code=404)
    cache = store.DATA_DIR / "media"
    cache.mkdir(exist_ok=True)
    path = cache / row["media_id"]
    mime = (row["media_mime"] or "application/octet-stream").split(";")[0]
    if not path.exists():
        try:
            data, mime_dl = whatsapp.download_media(row["media_id"])
        except Exception:
            raise HTTPException(status_code=410, detail="media expired or unavailable")
        path.write_bytes(data)
        mime = mime_dl.split(";")[0] or mime
    return FileResponse(path, media_type=mime, headers={"Cache-Control": "private, max-age=86400"})


@router.post("/chats/{number}/reply")
def chat_reply(request: Request, number: str, text: str = Form(...), pause_hours: int = Form(3)):
    """A staff member answers from the dashboard; the bot pauses on this number for a while."""
    if (r := require_login(request)):
        return r
    check_origin(request)
    from datetime import datetime, timedelta, timezone
    from app import whatsapp
    with store.connect() as conn:
        last_in = conn.execute(
            "SELECT phone_number_id FROM messages WHERE from_number = ? AND echo = 0 ORDER BY received_at DESC LIMIT 1",
            (number,)).fetchone()
    try:
        res = whatsapp.send_text(number, text.strip(), last_in["phone_number_id"] if last_in else None)
        with store.connect() as conn:
            conn.execute("INSERT OR IGNORE INTO messages (wa_message_id, received_at, phone_number_id, from_number, contact_name, msg_type, body, echo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                         (res.get("messages", [{}])[0].get("id"), store.now(), f"dashboard:{current_user(request)}",
                          number, None, "text", text.strip(), 1))
        if pause_hours > 0:
            store.set_setting(f"pause:{number}",
                              (datetime.now(timezone.utc) + timedelta(hours=pause_hours)).isoformat())
        flash(request, "পাঠানো হয়েছে।" + (f" এই নম্বরে বট {pause_hours} ঘণ্টা চুপ থাকবে।" if pause_hours > 0 else ""))
    except Exception as e:
        msg = str(e)
        if "131047" in msg or "24 hours" in msg or "re-engagement" in msg.lower():
            msg = "২৪ ঘণ্টার বেশি আগে কাস্টমার মেসেজ দিয়েছে, তাই সাধারণ মেসেজ যাবে না; অনুমোদিত template লাগবে।"
        flash(request, f"ব্যর্থ: {msg[:300]}", "err")
    return back(f"/admin/chats/{number}")


@router.post("/chats/{number}/pause")
def chat_pause(request: Request, number: str, action: str = Form(...)):
    if (r := require_login(request)):
        return r
    check_origin(request)
    store.set_setting(f"pause:{number}", "on" if action == "pause" else None)
    if action != "pause":
        store.set_setting(f"state:{number}", None)  # identification starts fresh
    flash(request, "এই নম্বরে বট বন্ধ করা হয়েছে, এখন শুধু মানুষ উত্তর দেবে।" if action == "pause"
          else "এই নম্বরে বট আবার চালু।")
    return back(f"/admin/chats/{number}")


# --- billing -------------------------------------------------------------------------
@router.get("/billing", response_class=HTMLResponse)
def billing_form(request: Request):
    if (r := require_login(request)):
        return r
    from app.ispdigital import config
    base, user, password = config()
    return render(request, "billing.html", base_url=base, username=user,
                  password_mask=store.mask(password) if password else "")


@router.post("/billing")
def billing_save(request: Request, base_url: str = Form(...), username: str = Form(...),
                 password: str = Form("")):
    if (r := require_login(request)):
        return r
    check_origin(request)
    store.set_setting("billing_base_url", base_url.strip().rstrip("/"))
    store.set_setting("billing_username", username.strip())
    if password:
        store.set_setting("billing_password", password)
    from app.main import reset_billing
    reset_billing()
    flash(request, "বিলিং ক্রেডেনশিয়াল সেভ হয়েছে।")
    return back("/admin/billing")


@router.post("/billing/test")
def billing_test(request: Request, query: str = Form("")):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app.ispdigital import ISPDigital
    try:
        api = ISPDigital()
        api.login()
        msg = "লগইন সফল।"
        if query.strip():
            rows = api.search_customers(query.strip(), limit=3)
            msg += f" '{query.strip()}' দিয়ে {len(rows)}টি কাস্টমার পাওয়া গেছে" + (
                ": " + ", ".join(f"{x.get('CustomerName')} ({x.get('CustomerId')})" for x in rows) if rows else "।")
        flash(request, msg)
    except Exception as e:
        flash(request, f"ব্যর্থ: {e}", "err")
    return back("/admin/billing")


# --- AI providers ----------------------------------------------------------------------
@router.get("/ai", response_class=HTMLResponse)
def ai_page(request: Request):
    if (r := require_login(request)):
        return r
    provider, model = active_config()
    with store.connect() as conn:
        rows = conn.execute("SELECT * FROM ai_keys ORDER BY provider, id").fetchall()
    keys = defaultdict(list)
    for row in rows:
        keys[row["provider"]].append({
            "id": row["id"], "label": row["label"], "model": row["model"],
            "preview": store.mask(store.decrypt(row["api_key_enc"])),
            "cooldown": row["rate_limited_until"],
        })
    models = unstash(request, "models")
    return render(request, "ai.html", providers=PROVIDERS, keys=keys, active_provider=provider,
                  active_model=model, bot_mode=store.get_setting("bot_mode", "shadow"),
                  extra_prompt=store.get_setting("ai_extra_prompt", ""), models=models,
                  live_allowlist=store.get_setting("live_allowlist", ""),
                  auto_ticket=store.get_setting("auto_ticket", "on"),
                  reply_signature=store.get_setting("reply_signature", "- Zyro"),
                  suggested=json.dumps({k: v["suggested"] for k, v in PROVIDERS.items()}))


@router.post("/ai/keys")
def ai_add_key(request: Request, provider: str = Form(...), api_key: str = Form(...),
               label: str = Form(""), model: str = Form("")):
    if (r := require_login(request)):
        return r
    check_origin(request)
    if provider not in PROVIDERS or not api_key.strip():
        flash(request, "প্রোভাইডার বা key ঠিক নেই।", "err")
        return back("/admin/ai")
    with store.connect() as conn:
        conn.execute("INSERT INTO ai_keys (provider, label, api_key_enc, model, created_at) VALUES (?, ?, ?, ?, ?)",
                     (provider, label.strip() or None, store.encrypt(api_key.strip()), model.strip() or None, store.now()))
    flash(request, f"{PROVIDERS[provider]['name']}-এর key যোগ হয়েছে।")
    return back("/admin/ai")


@router.post("/ai/keys/{key_id}/delete")
def ai_delete_key(request: Request, key_id: int):
    if (r := require_login(request)):
        return r
    check_origin(request)
    with store.connect() as conn:
        conn.execute("DELETE FROM ai_keys WHERE id = ?", (key_id,))
    flash(request, "key মুছে ফেলা হয়েছে।")
    return back("/admin/ai")


@router.post("/ai/keys/{key_id}/test")
def ai_test_key(request: Request, key_id: int):
    if (r := require_login(request)):
        return r
    check_origin(request)
    with store.connect() as conn:
        row = conn.execute("SELECT * FROM ai_keys WHERE id = ?", (key_id,)).fetchone()
    if not row:
        return back("/admin/ai")
    try:
        text, prov, model = generate("সংক্ষেপে উত্তর দাও।", [{"role": "user", "content": "শুধু 'ঠিক আছে' লিখুন।"}],
                                     provider=row["provider"], model=row["model"], key_id=key_id)
        flash(request, f"কাজ করছে ({PROVIDERS[prov]['name']} / {model}): {text}")
    except Exception as e:
        flash(request, f"ব্যর্থ: {str(e)[:300]}", "err")
    return back("/admin/ai")


@router.post("/ai/keys/{key_id}/models")
def ai_key_models(request: Request, key_id: int):
    if (r := require_login(request)):
        return r
    check_origin(request)
    with store.connect() as conn:
        row = conn.execute("SELECT * FROM ai_keys WHERE id = ?", (key_id,)).fetchone()
    if not row:
        return back("/admin/ai")
    try:
        names = list_models(row["provider"], store.decrypt(row["api_key_enc"]))
        stash(request, "models", {"provider": PROVIDERS[row["provider"]]["name"], "names": names[:300]})
    except Exception as e:
        flash(request, f"মডেল তালিকা আনা যায়নি: {str(e)[:300]}", "err")
    return back("/admin/ai")


@router.post("/ai/settings")
def ai_settings(request: Request, provider: str = Form(...), model: str = Form(""),
                bot_mode: str = Form("shadow"), extra_prompt: str = Form(""), live_allowlist: str = Form(""),
                auto_ticket: str = Form("off"), reply_signature: str = Form("- Zyro")):
    if (r := require_login(request)):
        return r
    check_origin(request)
    store.set_setting("live_allowlist", live_allowlist.strip() or None)
    store.set_setting("auto_ticket", "on" if auto_ticket == "on" else "off")
    store.set_setting("reply_signature", reply_signature.strip())
    if provider not in PROVIDERS or bot_mode not in ("shadow", "off", "live"):
        flash(request, "ভুল মান।", "err")
        return back("/admin/ai")
    store.set_setting("ai_provider", provider)
    store.set_setting("ai_model", model.strip() or PROVIDERS[provider]["suggested"])
    store.set_setting("bot_mode", bot_mode)
    store.set_setting("ai_extra_prompt", extra_prompt.strip() or None)
    flash(request, f"ডিফল্ট AI: {PROVIDERS[provider]['name']} / {model.strip() or PROVIDERS[provider]['suggested']}")
    return back("/admin/ai")


# --- WhatsApp connection (Embedded Signup with Coexistence) ----------------------------------
@router.get("/whatsapp", response_class=HTMLResponse)
def whatsapp_page(request: Request):
    if (r := require_login(request)):
        return r
    from app import whatsapp
    status = None
    pnid = store.get_setting("wa_phone_number_id")
    if pnid and store.get_setting("wa_access_token"):
        try:
            status = whatsapp.phone_status(pnid)
        except Exception as e:
            status = {"error": str(e)[:300]}
    templates_list = numbers = None
    if store.get_setting("wa_waba_id") and store.get_setting("wa_access_token"):
        try:
            templates_list = whatsapp.list_templates()
        except Exception:
            templates_list = None
        try:
            numbers = whatsapp.waba_numbers()
        except Exception:
            numbers = None
    bot_ids = [x for x in (store.get_setting("bot_phone_ids") or "").split(",") if x]
    return render(request, "whatsapp.html", app_id=whatsapp.APP_ID, config_id=whatsapp.CONFIG_ID,
                  templates_list=templates_list, has_token=bool(store.get_setting("wa_access_token")),
                  numbers=numbers, bot_ids=bot_ids, new_number_id=store.get_setting("wa_new_number_id"),
                  waba_id=store.get_setting("wa_waba_id"), phone_number_id=pnid,
                  onboarded_at=store.get_setting("wa_onboarded_at"), status=status,
                  last=store.get_setting("wa_last_onboarding"))


@router.post("/whatsapp/complete")
async def whatsapp_complete(request: Request):
    if not current_user(request):
        raise HTTPException(status_code=401)
    check_origin(request)
    body = await request.json()
    if not body.get("code") or not body.get("waba_id"):
        raise HTTPException(status_code=400, detail="code / waba_id missing")
    from app import whatsapp
    try:
        result = whatsapp.complete_onboarding(body["code"], str(body["waba_id"]), body.get("phone_number_id"))
        return {"ok": True, "result": result}
    except Exception as e:
        return {"ok": False, "error": str(e)[:500]}


@router.post("/whatsapp/manual")
def whatsapp_manual(request: Request, waba_id: str = Form(...), phone_number_id: str = Form(...),
                    access_token: str = Form("")):
    """Use a number that is already on Cloud API (e.g. from WhatsApp Manager) with a system-user token."""
    if (r := require_login(request)):
        return r
    check_origin(request)
    store.set_setting("wa_waba_id", waba_id.strip())
    store.set_setting("wa_phone_number_id", phone_number_id.strip())
    if access_token.strip():
        store.set_setting("wa_access_token", access_token.strip())
    store.set_setting("wa_onboarded_at", store.now())
    flash(request, "WhatsApp সেটিংস সেভ হয়েছে।")
    return back("/admin/whatsapp")


@router.post("/whatsapp/send")
def whatsapp_send(request: Request, to: str = Form(...), text: str = Form(""), template: str = Form(""),
                  language: str = Form("en_US")):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app import whatsapp
    to_num = "".join(ch for ch in to if ch.isdigit())
    if to_num.startswith("01") and len(to_num) == 11:
        to_num = "88" + to_num
    try:
        res = whatsapp.send_template(to_num, template.strip(), language.strip()) if template.strip() \
            else whatsapp.send_text(to_num, text)
        flash(request, f"পাঠানো হয়েছে: {to_num} · message id {res.get('messages', [{}])[0].get('id')}")
    except Exception as e:
        flash(request, f"ব্যর্থ: {str(e)[:300]}", "err")
    return back("/admin/whatsapp")


@router.post("/whatsapp/templates")
def whatsapp_create_template(request: Request, name: str = Form(...), language: str = Form("bn"),
                             category: str = Form("UTILITY"), body: str = Form(...)):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app import whatsapp
    try:
        res = whatsapp.create_template(name.strip().lower(), language.strip(), category, body)
        flash(request, f"Template জমা হয়েছে: id {res.get('id')} · status {res.get('status')}")
    except Exception as e:
        flash(request, f"ব্যর্থ: {str(e)[:300]}", "err")
    return back("/admin/whatsapp")


@router.post("/whatsapp/number/add")
def wa_number_add(request: Request, phone: str = Form(...), display_name: str = Form(...)):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app import whatsapp
    digits = "".join(ch for ch in phone if ch.isdigit())
    if digits.startswith("880"):
        digits = digits[3:]
    digits = digits.lstrip("0")
    try:
        res = whatsapp.add_phone_number("880", digits, display_name.strip())
        store.set_setting("wa_new_number_id", res.get("id"))
        flash(request, f"নম্বর যুক্ত হয়েছে (ID {res.get('id')})। এবার কোড পাঠান।")
    except Exception as e:
        flash(request, f"ব্যর্থ: {str(e)[:300]}", "err")
    return back("/admin/whatsapp")


@router.post("/whatsapp/number/request-code")
def wa_number_request_code(request: Request, number_id: str = Form(...), method: str = Form("SMS")):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app import whatsapp
    try:
        whatsapp.request_code(number_id.strip(), "VOICE" if method == "VOICE" else "SMS")
        store.set_setting("wa_new_number_id", number_id.strip())
        flash(request, "কোড পাঠানো হয়েছে। ফোনে আসা ৬ অঙ্কের কোড নিচে দিন।")
    except Exception as e:
        flash(request, f"ব্যর্থ: {str(e)[:300]}", "err")
    return back("/admin/whatsapp")


@router.post("/whatsapp/number/verify")
def wa_number_verify(request: Request, number_id: str = Form(...), code: str = Form(...), pin: str = Form(...)):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app import whatsapp
    if not (pin.isdigit() and len(pin) == 6):
        flash(request, "PIN ৬ অঙ্কের সংখ্যা হতে হবে।", "err")
        return back("/admin/whatsapp")
    try:
        whatsapp.verify_code(number_id.strip(), code.strip())
        whatsapp.register(number_id.strip(), pin)
        flash(request, "নম্বর যাচাই ও রেজিস্টার হয়েছে। PIN-টা নিরাপদে লিখে রাখুন (two-step verification)।")
    except Exception as e:
        flash(request, f"ব্যর্থ: {str(e)[:300]}", "err")
    return back("/admin/whatsapp")


@router.post("/whatsapp/bot-numbers")
def wa_bot_numbers(request: Request, bot_phone_ids: list[str] = Form([]), default_id: str = Form("")):
    if (r := require_login(request)):
        return r
    check_origin(request)
    store.set_setting("bot_phone_ids", ",".join(bot_phone_ids) or None)
    if default_id:
        store.set_setting("wa_phone_number_id", default_id)
    flash(request, "সেভ হয়েছে।")
    return back("/admin/whatsapp")


@router.post("/whatsapp/sync")
def whatsapp_sync(request: Request, kind: str = Form(...)):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app import whatsapp
    try:
        res = whatsapp.sync(store.get_setting("wa_phone_number_id") or "", kind)
        flash(request, f"{kind} সিঙ্ক অনুরোধ পাঠানো হয়েছে: {res}")
    except Exception as e:
        flash(request, f"ব্যর্থ: {str(e)[:300]}", "err")
    return back("/admin/whatsapp")


# --- try a customer message end to end (nothing is sent) ----------------------------------
@router.get("/try", response_class=HTMLResponse)
def try_form(request: Request):
    if (r := require_login(request)):
        return r
    return render(request, "try.html", result=unstash(request, "try_result"))


@router.post("/try")
def try_run(request: Request, phone: str = Form(...), text: str = Form(...)):
    if (r := require_login(request)):
        return r
    check_origin(request)
    from app.agent import draft_reply
    from app.ispdigital import diagnose, find_customer_by_whatsapp
    from app.main import billing
    result = {"phone": phone, "text": text}
    try:
        customer = find_customer_by_whatsapp(billing(), phone)
        context = diagnose(billing(), customer) if customer else None
        result["context"] = json.dumps(context, ensure_ascii=False, indent=2) if context else "কাস্টমার পাওয়া যায়নি"
        draft, provider, model = draft_reply([{"role": "user", "content": text}], context)
        result.update(draft=draft, provider=PROVIDERS[provider]["name"], model=model)
    except Exception as e:
        result["error"] = str(e)[:500]
    stash(request, "try_result", result)
    return back("/admin/try")
