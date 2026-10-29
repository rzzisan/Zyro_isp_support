"""SMS gateway adapters for engine/sms.py, one module per provider (sms_gateways.provider).

Every adapter has:
- BULK: numbers per request when they get the same text (1 = one request per SMS)
- send(g, mobiles, text, client) -> {ok, account, status, text, description, message_ids, error}
  account=True: the gateway refused the account itself (keys, balance, IP, sender ID); nothing went out, the whole
  queue stops on this gateway and the fallback takes over. Any other failure belongs to these SMS only.
- balance(g, client) -> {balance, sms}; raises GatewayError
- validate(g, client) -> {client, balance}; raises GatewayError; never sends an SMS
- status(g, message_id, client) -> {state: delivered | failed | None, text, at, final, stop} or None (no answer)
  final: the gateway will not tell more about this SMS; stop: the delivery query itself is refused, stop for now.
`g` is a sms_gateways row with the keys decrypted.
"""
import httpx

from engine.sms_gateways import bulksmsdhaka, khudebarta
from engine.sms_gateways.common import GatewayError

PROVIDERS = {"khudebarta": khudebarta, "bulksmsdhaka": bulksmsdhaka}


def adapter(g: dict):
    try:
        return PROVIDERS[g["provider"]]
    except KeyError:
        raise GatewayError(f"অজানা SMS গেটওয়ে: {g.get('provider')}")


def configured(g: dict | None) -> bool:
    if not g or g.get("provider") not in PROVIDERS:
        return False
    need = ("endpoint_url", "api_key", "caller_id") + (("secret_key",) if PROVIDERS[g["provider"]].NEEDS_SECRET else ())
    return all(g.get(k) for k in need)


def client(g: dict) -> httpx.Client:
    return httpx.Client(timeout=30, verify=bool(g.get("verify_tls", True)), follow_redirects=True)


__all__ = ["GatewayError", "PROVIDERS", "adapter", "configured", "client"]
