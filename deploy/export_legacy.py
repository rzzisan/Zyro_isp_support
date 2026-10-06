"""Print the legacy bot's settings as JSON on stdout (piped straight into `php artisan zyro:import-legacy`)."""
import json

from app import store, whatsapp
from app.ispdigital import config

base, user, password = config()
with store.connect() as conn:
    keys = [{"provider": r["provider"], "label": r["label"], "api_key": store.decrypt(r["api_key_enc"]), "model": r["model"]}
            for r in conn.execute("SELECT * FROM ai_keys ORDER BY id")]

bot = {
    "ai_provider": store.get_setting("ai_provider", "groq"),
    "ai_model": store.get_setting("ai_model"),
    "bot_mode": store.get_setting("bot_mode", "shadow"),
    "live_allowlist": store.get_setting("live_allowlist"),
    "auto_ticket": store.get_setting("auto_ticket", "on") == "on",
    "reply_signature": store.get_setting("reply_signature", "- Zyro"),
    "extra_prompt": store.get_setting("ai_extra_prompt"),
}

# only the numbers the bot serves for Century Link (not Zareen's)
token = store.get_setting("wa_access_token")
waba = store.get_setting("wa_waba_id")
served = [x for x in (store.get_setting("bot_phone_ids") or "").split(",") if x]
numbers = {n["id"]: n for n in whatsapp.waba_numbers()}
wa_accounts = [{
    "waba_id": waba, "phone_number_id": pid,
    "display_phone_number": numbers.get(pid, {}).get("display_phone_number"),
    "verified_name": numbers.get(pid, {}).get("verified_name"),
    "access_token": token, "bot_enabled": True,
} for pid in served]

print(json.dumps({"billing": {"base_url": base, "username": user, "password": password},
                  "ai_keys": keys, "bot": bot, "wa_accounts": wa_accounts}, ensure_ascii=False))
