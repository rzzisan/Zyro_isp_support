"""Minimal RouterOS API client (classic binary API, ports 8728 / 8729-TLS), no dependencies.

Plain login (RouterOS 6.43+ / 7.x). Used to read identity and PPPoE sessions; nothing here changes a router.
"""
import socket
import ssl


class RouterOSError(RuntimeError):
    pass


def _enc_len(n: int) -> bytes:
    if n < 0x80:
        return bytes([n])
    if n < 0x4000:
        return (n | 0x8000).to_bytes(2, "big")
    if n < 0x200000:
        return (n | 0xC00000).to_bytes(3, "big")
    if n < 0x10000000:
        return (n | 0xE0000000).to_bytes(4, "big")
    return b"\xF0" + n.to_bytes(4, "big")


class RouterOS:
    def __init__(self, host: str, port: int = 8728, username: str = "", password: str = "", timeout: float = 8):
        raw = socket.create_connection((host, int(port)), timeout=timeout)
        if int(port) == 8729:
            ctx = ssl.create_default_context()
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
            raw = ctx.wrap_socket(raw, server_hostname=host)
        self.sock = raw
        self.sock.settimeout(timeout)
        self.talk("/login", {"name": username, "password": password})

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    # --- wire format ---
    def _recv(self, n: int) -> bytes:
        buf = b""
        while len(buf) < n:
            chunk = self.sock.recv(n - len(buf))
            if not chunk:
                raise RouterOSError("router closed the connection")
            buf += chunk
        return buf

    def _read_len(self) -> int:
        b = self._recv(1)[0]
        if b < 0x80:
            return b
        if b < 0xC0:
            return ((b & 0x3F) << 8) | self._recv(1)[0]
        if b < 0xE0:
            return ((b & 0x1F) << 16) | int.from_bytes(self._recv(2), "big")
        if b < 0xF0:
            return ((b & 0x0F) << 24) | int.from_bytes(self._recv(3), "big")
        return int.from_bytes(self._recv(4), "big")

    def _send_sentence(self, words: list[str]) -> None:
        data = b"".join(_enc_len(len(w.encode())) + w.encode() for w in words) + b"\x00"
        self.sock.sendall(data)

    def _read_sentence(self) -> list[str]:
        words = []
        while True:
            n = self._read_len()
            if n == 0:
                return words
            words.append(self._recv(n).decode(errors="replace"))

    def talk(self, command: str, attrs: dict | None = None, query: dict | None = None) -> list[dict]:
        words = [command] + [f"={k}={v}" for k, v in (attrs or {}).items()] + [f"?{k}={v}" for k, v in (query or {}).items()]
        self._send_sentence(words)
        rows: list[dict] = []
        while True:
            sentence = self._read_sentence()
            if not sentence:
                continue
            kind, fields = sentence[0], {}
            for w in sentence[1:]:
                if w.startswith("="):
                    k, _, v = w[1:].partition("=")
                    fields[k] = v
            if kind == "!re":
                rows.append(fields)
            elif kind == "!trap":
                raise RouterOSError(fields.get("message", "router refused the command"))
            elif kind == "!fatal":
                raise RouterOSError(" ".join(sentence[1:]) or "fatal error")
            elif kind == "!done":
                return rows


def check(host: str, port: int, username: str, password: str) -> dict:
    """Connect and read identity, version and how many PPPoE sessions are up."""
    with RouterOS(host, port, username, password) as r:
        identity = (r.talk("/system/identity/print") or [{}])[0].get("name")
        res = (r.talk("/system/resource/print") or [{}])[0]
        active = r.talk("/ppp/active/print", {".proplist": "name"})
    return {"identity": identity, "version": res.get("version"), "board": res.get("board-name"), "ppp_active": len(active)}


def active_session(host: str, port: int, username: str, password: str, pppoe_user: str) -> dict | None:
    """The customer's PPPoE session on this router, or None when offline."""
    with RouterOS(host, port, username, password) as r:
        rows = r.talk("/ppp/active/print", query={"name": pppoe_user})
    return rows[0] if rows else None
