"""Helpers shared by the SMS gateway adapters."""
import json
import re


class GatewayError(RuntimeError):
    pass


def short(text: str, keys: tuple = ()) -> str:
    """A gateway answer safe to store/show: keys blanked, at most 300 characters."""
    for k in keys:
        if k:
            text = text.replace(k, "***")
    return re.sub(r"\s+", " ", text).strip()[:300]


def as_json(text: str):
    try:
        return json.loads(text)
    except ValueError:
        return None


def number(v) -> float | None:
    try:
        return round(float(str(v).replace(",", "").strip()), 2)   # gateways send e.g. 1490.6500000000033
    except (TypeError, ValueError):
        return None


def secrets(g: dict) -> tuple:
    return (g.get("api_key"), g.get("secret_key"))


def failure(error: str, status: str | None = None, account: bool = False, unreachable: bool = False) -> dict:
    return {"ok": False, "account": account, "unreachable": unreachable, "status": status, "text": None,
            "description": None, "message_ids": [], "error": error}


def http_failure(e: Exception, g: dict) -> dict:
    """No answer. Connection refused / connect timeout = the request never reached the gateway (safe to try again or
    elsewhere); any other error (read timeout ...) may have reached it, so that SMS is not sent again."""
    import httpx
    return failure(short(f"{type(e).__name__}: {e}", secrets(g)),
                   unreachable=isinstance(e, (httpx.ConnectError, httpx.ConnectTimeout)))
