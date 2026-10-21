"""Hotline caller ID. Runs baresip with every company's SIP extension (Desk → সেটিংস → হটলাইন (SIP)) and writes each
incoming call into call_logs while it rings, so the panel pops up the caller (public/js/zyro-calls.js).

baresip loads no UI or audio-device module, so it answers nothing: the PBX gets "180 Ringing" and the call is picked up
on the agents' phones as before. Events come over baresip's ctrl_tcp (netstring JSON). When a CANCEL arrives baresip
only says "Connection reset by peer", so we cannot tell "answered elsewhere" from "caller gave up": a call is "ended".
Settings are re-read every 30 s; any change restarts baresip with the new accounts file.
"""
import hashlib
import json
import logging
import os
import re
import socket
import subprocess
import time

from engine import db

log = logging.getLogger("sip_calls")

DIR = os.environ.get("SIP_DIR", "/var/lib/zyro-sip")
LISTEN = os.environ.get("SIP_LISTEN", "0.0.0.0:5070")     # baresip also takes the next port for TLS
CTRL_PORT = int(os.environ.get("SIP_CTRL_PORT", "4455"))
CHECK_EVERY = 30


def normalize(raw: str) -> str | None:
    """Same as the panel's Sms::mobile: 8801XXXXXXXXX or None."""
    d = re.sub(r"\D", "", raw or "")
    if d.startswith("00880"):
        d = d[2:]
    if re.fullmatch(r"01\d{9}", d):
        d = "88" + d
    elif re.fullmatch(r"1\d{9}", d):
        d = "880" + d
    return d if re.fullmatch(r"8801[3-9]\d{8}", d) else None


def load_accounts() -> list[dict]:
    rows = db.all_rows("select company_id, server, port, transport, username, auth_user, password from sip_settings"
                       " where enabled and server is not null and username is not null and password is not null order by company_id")
    out = []
    for r in rows:
        pw = db.decrypt(r["password"])
        if not pw:
            log.warning("company %s: SIP password could not be decrypted", r["company_id"])
            continue
        out.append({**r, "password": pw})
    return out


def fingerprint(accounts: list[dict]) -> str:
    return hashlib.sha256(json.dumps(accounts, sort_keys=True, default=str).encode()).hexdigest()


def account_line(a: dict) -> str:
    uri = f"sip:{a['username']}@{a['server']}:{a['port']};transport={a['transport']}"
    return (f"<{uri}>;auth_user={a['auth_user'] or a['username']};auth_pass={a['password']}"
            ";answermode=manual;regint=300;audio_codecs=PCMA,PCMU")


def write_config(accounts: list[dict]) -> None:
    os.makedirs(DIR, mode=0o700, exist_ok=True)
    config = "\n".join([
        f"sip_listen {LISTEN}",
        "call_max_calls 64",
        "module_path /usr/lib/baresip/modules",
        "module g711.so",
        "module_app account.so",
        "module_app ctrl_tcp.so",
        f"ctrl_tcp_listen 127.0.0.1:{CTRL_PORT}",
        "",
    ])
    for name, text in (("config", config), ("accounts", "\n".join(account_line(a) for a in accounts) + "\n")):
        path = os.path.join(DIR, name)
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w") as f:
            f.write(text)


def company_for(aor: str, accounts: list[dict]) -> int | None:
    """baresip's accountaor (sip:106@pbx.example.com:5060;...) → the company whose extension it is."""
    m = re.match(r"sips?:([^@]+)@([^:;>]+)", aor or "")
    if not m:
        return None
    for a in accounts:
        if a["username"] == m.group(1) and a["server"].lower() == m.group(2).lower():
            return a["company_id"]
    return None


def caller_of(event: dict) -> str:
    m = re.match(r"sips?:([^@;>]+)", event.get("peeruri") or "")
    return (m.group(1) if m else (event.get("peeruri") or "?"))[:80]


def handle(event: dict, accounts: list[dict]) -> None:
    kind = event.get("type")
    cid = company_for(event.get("accountaor", ""), accounts)
    if cid is None:
        return
    if kind in ("REGISTER_OK", "REGISTER_FAIL"):
        status = "registered" if kind == "REGISTER_OK" else (event.get("param") or "registration failed")[:250]
        db.execute("update sip_settings set status = %s, status_at = now() where company_id = %s", (status, cid))
    elif kind == "CALL_INCOMING" and event.get("id"):
        caller = caller_of(event)
        db.execute("insert into call_logs (company_id, call_ref, caller, caller_normalized, status, started_at, created_at, updated_at)"
                   " values (%s, %s, %s, %s, 'ringing', now(), now(), now()) on conflict (company_id, call_ref) do nothing",
                   (cid, event["id"][:120], caller, normalize(caller)))
        log.info("company %s: call from %s", cid, caller)
    elif kind == "CALL_CLOSED" and event.get("id"):
        db.execute("update call_logs set status = 'ended', ended_at = now(), end_reason = %s, updated_at = now()"
                   " where company_id = %s and call_ref = %s and status = 'ringing'",
                   ((event.get("param") or "")[:160], cid, event["id"][:120]))


def end_stale() -> None:
    """Calls baresip can no longer report on (restart, lost event) stop "ringing" in the panel."""
    db.execute("update call_logs set status = 'ended', ended_at = now(), end_reason = 'stale', updated_at = now()"
               " where status = 'ringing' and started_at < now() - interval '5 minutes'")


def netstrings(buf: bytes):
    """Split complete netstrings off the buffer: (messages, rest)."""
    out = []
    while True:
        colon = buf.find(b":")
        if colon < 0 or not buf[:colon].isdigit():
            return out, buf
        n = int(buf[:colon])
        if len(buf) < colon + 1 + n + 1:
            return out, buf
        out.append(buf[colon + 1:colon + 1 + n])
        buf = buf[colon + 1 + n + 1:]


def connect() -> socket.socket:
    for _ in range(50):
        try:
            s = socket.create_connection(("127.0.0.1", CTRL_PORT), timeout=2)
            s.settimeout(5)
            return s
        except OSError:
            time.sleep(0.2)
    raise RuntimeError("baresip ctrl_tcp did not come up")


def serve(accounts: list[dict], fp: str) -> None:
    write_config(accounts)
    proc = subprocess.Popen(["baresip", "-f", DIR], stdin=subprocess.DEVNULL)
    try:
        sock = connect()
        buf, last_check = b"", time.monotonic()
        while proc.poll() is None:
            try:
                data = sock.recv(65536)
                if not data:
                    break
                msgs, buf = netstrings(buf + data)
                for m in msgs:
                    try:
                        ev = json.loads(m)
                    except ValueError:
                        continue
                    if ev.get("event"):
                        handle(ev, accounts)
            except socket.timeout:
                pass
            if time.monotonic() - last_check > CHECK_EVERY:
                last_check = time.monotonic()
                end_stale()
                if fingerprint(load_accounts()) != fp:
                    log.info("SIP settings changed, restarting baresip")
                    return
    finally:
        proc.terminate()
        try:
            proc.wait(10)
        except subprocess.TimeoutExpired:
            proc.kill()


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    while True:
        try:
            db.execute("update call_logs set status = 'ended', ended_at = now(), end_reason = 'restart', updated_at = now()"
                       " where status = 'ringing'")
            accounts = load_accounts()
            if not accounts:
                time.sleep(CHECK_EVERY)
                continue
            serve(accounts, fingerprint(accounts))
        except Exception:
            log.exception("sip_calls loop")
            time.sleep(10)


if __name__ == "__main__":
    main()
