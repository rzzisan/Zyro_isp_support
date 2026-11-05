"""The Desk's activity log (activity_logs) from the engine: bot, timer and engine actions. The panel writes the same
table through App\\Support\\Activity. Append-only; the database links every row to the one before it (hash chain).

Never put a password, token or key in `changes` or `note`: fields whose name looks secret are replaced by "changed".
"""
import logging
import re

from psycopg.types.json import Jsonb

from engine import db

log = logging.getLogger("zyro-activity")
SECRET = re.compile(r"pass|pwd|secret|token|api_?key|private", re.I)
CHANGED = "(বদলেছে)"


def redact(changes: dict | None) -> dict | None:
    if not changes:
        return None
    return {k: ([CHANGED, CHANGED] if SECRET.search(str(k)) else v) for k, v in changes.items()}


def record(company_id: int, action: str, *, actor: str = "system", source: str = "engine", user_id: int | None = None,
           subject_type: str | None = None, subject_id=None, customer_code: str | None = None,
           changes: dict | None = None, note: str | None = None, conn=None) -> None:
    """One row. Never raises: a failed log line must not stop the action it describes."""
    sql = """INSERT INTO activity_logs (company_id, user_id, actor, source, action, subject_type, subject_id,
                 customer_code, changes, note) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)"""
    params = (company_id, user_id, actor, source, action, subject_type,
              None if subject_id is None else str(subject_id), customer_code,
              Jsonb(redact(changes)) if changes else None, note)
    try:
        if conn is not None:
            conn.execute(sql, params)
        else:
            db.execute(sql, params)
    except Exception:
        log.exception("activity log failed: %s %s", company_id, action)


def verify(company_id: int) -> dict:
    """Walk the company's chain: every row's prev_hash must be the hash before it and its hash must recompute."""
    bad = db.all_rows(
        """SELECT (r).id, (r).seq FROM (
               SELECT a AS r, lag(a.hash) OVER w AS before, lag(a.seq) OVER w AS seq_before
               FROM activity_logs a WHERE a.company_id = %s WINDOW w AS (ORDER BY a.seq)) x
           WHERE (r).prev_hash IS DISTINCT FROM before OR (r).hash IS DISTINCT FROM activity_log_hash(r)
              OR (seq_before IS NOT NULL AND (r).seq <> seq_before + 1) OR (seq_before IS NULL AND (r).seq <> 1)
           ORDER BY (r).seq LIMIT 20""", (company_id,))
    total = db.one("SELECT count(*) AS n FROM activity_logs WHERE company_id = %s", (company_id,))["n"]
    return {"rows": total, "broken": [dict(r) for r in bad]}
