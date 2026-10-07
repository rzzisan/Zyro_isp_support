"""Minimal SNMP v2c client (GET / GETBULK walk) over UDP, no dependencies. Read-only by design: no SET."""
import os
import socket

# --- BER ---------------------------------------------------------------------------------------


def _len(n: int) -> bytes:
    if n < 0x80:
        return bytes([n])
    b = n.to_bytes((n.bit_length() + 7) // 8, "big")
    return bytes([0x80 | len(b)]) + b


def _tlv(tag: int, value: bytes) -> bytes:
    return bytes([tag]) + _len(len(value)) + value


def _int(v: int) -> bytes:
    b = v.to_bytes(max(1, (v.bit_length() + 8) // 8), "big", signed=True)
    return _tlv(0x02, b)


def _oid(oid: str) -> bytes:
    parts = [int(x) for x in oid.strip(".").split(".")]
    out = bytearray([40 * parts[0] + parts[1]])
    for p in parts[2:]:
        chunk = [p & 0x7F]
        p >>= 7
        while p:
            chunk.append(0x80 | (p & 0x7F))
            p >>= 7
        out += bytes(reversed(chunk))
    return _tlv(0x06, bytes(out))


def _read(data: bytes, i: int) -> tuple[int, bytes, int]:
    tag = data[i]
    n = data[i + 1]
    i += 2
    if n & 0x80:
        k = n & 0x7F
        n = int.from_bytes(data[i:i + k], "big")
        i += k
    return tag, data[i:i + n], i + n


def _decode_oid(b: bytes) -> str:
    parts = [b[0] // 40, b[0] % 40]
    v = 0
    for x in b[1:]:
        v = (v << 7) | (x & 0x7F)
        if not x & 0x80:
            parts.append(v)
            v = 0
    return ".".join(map(str, parts))


def _value(tag: int, b: bytes):
    if tag == 0x02:
        return int.from_bytes(b, "big", signed=True) if b else 0
    if tag in (0x41, 0x42, 0x43, 0x46):  # Counter32, Gauge32, TimeTicks, Counter64
        return int.from_bytes(b, "big") if b else 0
    if tag == 0x04:
        return b
    if tag == 0x06:
        return _decode_oid(b)
    if tag == 0x40:
        return ".".join(map(str, b))
    if tag in (0x80, 0x81, 0x82):
        return None  # noSuchObject / noSuchInstance / endOfMibView
    return b


class SNMPError(RuntimeError):
    pass


class SNMP:
    def __init__(self, host: str, port: int = 161, community: str = "public", timeout: float = 3, retries: int = 2):
        self.addr = (host, int(port))
        self.community = community.encode()
        self.timeout = timeout
        self.retries = retries

    def _request(self, pdu_tag: int, oids: list[str], non_repeaters: int = 0, max_rep: int = 0) -> list[tuple[str, int, object]]:
        rid = int.from_bytes(os.urandom(3), "big")
        varbinds = b"".join(_tlv(0x30, _oid(o) + b"\x05\x00") for o in oids)
        if pdu_tag == 0xA5:
            fields = _int(rid) + _int(non_repeaters) + _int(max_rep)
        else:
            fields = _int(rid) + _int(0) + _int(0)
        pdu = _tlv(pdu_tag, fields + _tlv(0x30, varbinds))
        msg = _tlv(0x30, _int(1) + _tlv(0x04, self.community) + pdu)  # version 1 = v2c
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.settimeout(self.timeout)
        try:
            for _ in range(self.retries + 1):
                sock.sendto(msg, self.addr)
                try:
                    while True:
                        data, _a = sock.recvfrom(65535)
                        out = self._parse(data, rid)
                        if out is not None:
                            return out
                except socket.timeout:
                    continue
            raise SNMPError(f"no answer from {self.addr[0]}:{self.addr[1]} (timeout)")
        finally:
            sock.close()

    @staticmethod
    def _parse(data: bytes, rid: int):
        _t, msg, _ = _read(data, 0)
        i = 0
        _t, _ver, i = _read(msg, i)
        _t, _comm, i = _read(msg, i)
        _t, pdu, i = _read(msg, i)
        j = 0
        _t, r, j = _read(pdu, j)
        if int.from_bytes(r, "big", signed=True) != rid:
            return None
        _t, err, j = _read(pdu, j)
        _t, _idx, j = _read(pdu, j)
        if int.from_bytes(err, "big"):
            raise SNMPError(f"SNMP error status {int.from_bytes(err, 'big')}")
        _t, vbl, j = _read(pdu, j)
        out, k = [], 0
        while k < len(vbl):
            _t, vb, k = _read(vbl, k)
            _ot, ob, m = _read(vb, 0)
            vt, vv, _ = _read(vb, m)
            out.append((_decode_oid(ob), vt, _value(vt, vv)))
        return out

    def get(self, *oids: str) -> dict:
        return {o: v for o, _t, v in self._request(0xA0, list(oids))}

    def walk(self, root: str, max_rep: int = 25, limit: int = 200000) -> list[tuple[str, object]]:
        root = root.strip(".")
        prefix = root + "."
        cur, out = root, []
        while len(out) < limit:
            rows = self._request(0xA5, [cur], 0, max_rep)
            done = False
            for oid, tag, val in rows:
                if tag == 0x82 or not oid.startswith(prefix):
                    done = True
                    break
                out.append((oid, val))
                cur = oid
            if done or not rows:
                break
        return out


def text(v) -> str:
    if isinstance(v, bytes):
        try:
            s = v.decode()
            if s.isprintable():
                return s
        except UnicodeDecodeError:
            pass
        return v.hex(":")
    return str(v)
