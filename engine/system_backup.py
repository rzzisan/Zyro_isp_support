"""Whole-Desk backups to the super admin's Google Drive (/super → সিস্টেম ব্যাকআপ).

Runs as root from zyro-system-backup.timer every minute (root because it reads /etc/letsencrypt, /etc/zyro-desk and dumps
the database as postgres). Each tick takes one job, under a file lock so two never overlap:
  1. queued rows from the panel (এখনই ব্যাকআপ, restore test, download), oldest first;
  2. the schedule: a full run every night at full_at (Dhaka), a database-only dump every db_every_hours, a restore test
     every Sunday after the full run;
  3. retention, expired downloads, and the "no good backup for 26 hours" alert.

Everything goes through rclone: remote "gd" is the Drive folder the panel created (OAuth, drive.file scope), remote "zb"
is rclone crypt on top of it, password = the recovery key, salt = sha256("zyrodesk:" + key)[:32]. Without that key
nothing on Drive can be read; deploy/restore-server.sh derives the same config from it.

Drive layout (names encrypted on Drive, shown here decrypted):
  runs/2026-10-22_0330-full/  zyro_support.dump, globals.sql, secrets.tar.gz, config.tar.gz, code.txt, [code.bundle], manifest.json
  runs/2026-10-22_0930-db/    zyro_support.dump, globals.sql, manifest.json
  files/current/              mirror of panel/storage/app (media, call recordings, MikroTik backups ...)
  files/apk/                  panel/public/apk: only the current zyro-desk.apk and index.html (versioned builds are skipped)
  files/deleted/2026-10-22/   what the nightly sync removed or replaced, kept keep_deleted_files_days

  sudo -E .venv/bin/python -m engine.system_backup            # one tick (what the timer runs)
  sudo -E .venv/bin/python -m engine.system_backup run full   # queue + run a full backup now
  sudo -E .venv/bin/python -m engine.system_backup restore-db ID --yes   # replace the live database (old one kept)
  sudo -E .venv/bin/python -m engine.system_backup restore-files [path]  # copy panel/storage/app back from Drive
"""
import fcntl
import hashlib
import json
import logging
import os
import random
import re
import shutil
import subprocess
import sys
import tarfile
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

from engine import db

log = logging.getLogger("zyro-system-backup")

APP = Path(__file__).resolve().parent.parent                     # /var/www/zyro_isp_support
STORAGE = APP / "panel" / "storage" / "app"
APK = APP / "panel" / "public" / "apk"
DOWNLOADS = "private/system-backup-downloads"                    # on the panel's local disk, served to super admins
WORK = Path(os.environ.get("SYSTEM_BACKUP_WORK") or "/var/backups/zyro-desk")
DB_NAME = os.environ.get("SYSTEM_BACKUP_DB") or "zyro_support"
RCLONE = os.environ.get("RCLONE_BIN") or "rclone"
DHAKA = timezone(timedelta(hours=6))
DRY_RUN = os.environ.get("ENGINE_DRY_RUN", "1") == "1"            # alerts only; backups always run

SECRETS = [APP / "panel" / ".env", APP / "engine" / ".env", Path("/etc/zyro-desk")]
CONFIG = [Path("/etc/nginx"), Path("/etc/letsencrypt"), Path("/etc/asterisk"), Path("/etc/php/8.3/fpm/pool.d"),
          Path("/var/spool/cron/crontabs")]
EXCLUDE_FILES = ["private/livewire-tmp/**", f"{DOWNLOADS}/**", "**/*.part"]
EXCLUDE_APK = ["zyro-desk-*.apk"]                                 # old versioned builds; zyro-desk.apk is the latest one
CHECK_TABLES = ["companies", "users", "billing_customers", "billing_customer_bills", "billing_tickets", "wa_contacts",
                "wa_messages", "bill_collections", "onu_assignments", "mikrotik_routers"]
GOOGLE_REVOKED = "Google Drive-এর অনুমতি বাতিল হয়েছে; /super → সিস্টেম ব্যাকআপে আবার সংযোগ দিন"


class BackupError(Exception):
    pass


# ---------------------------------------------------------------- helpers

def now_dhaka() -> datetime:
    return datetime.now(DHAKA)


def settings() -> dict:
    return db.one("SELECT * FROM system_backup_settings ORDER BY id LIMIT 1") or {}


def _run(cmd: list[str], *, env=None, stdout=None, stdin=None, timeout=3 * 3600, check=True) -> subprocess.CompletedProcess:
    log.info("$ %s", " ".join(cmd[:6]) + (" …" if len(cmd) > 6 else ""))
    p = subprocess.run(cmd, env=env, stdin=stdin, stdout=stdout if stdout is not None else subprocess.PIPE,
                       stderr=subprocess.PIPE, timeout=timeout)
    if check and p.returncode != 0:
        err = (p.stderr or b"").decode(errors="replace").strip()
        raise BackupError(f"{Path(cmd[0]).name} {cmd[1] if len(cmd) > 1 else ''}: {err[-600:] or p.returncode}")
    return p


def _as_postgres(cmd: list[str]) -> list[str]:
    return ["runuser", "-u", "postgres", "--", *cmd]


def _sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def salt_for(key: str) -> str:
    return hashlib.sha256(f"zyrodesk:{key}".encode()).hexdigest()[:32]


class Drive:
    """rclone with a throwaway config (root only, removed afterwards) built from the panel's settings."""

    def __init__(self, s: dict):
        if not (s.get("google_refresh_token") and s.get("google_folder_id") and s.get("crypt_key")):
            raise BackupError("Google Drive সংযোগ দেওয়া হয়নি")
        self.s = s
        self.key = db.decrypt(s["crypt_key"])
        self.bwlimit = f"{int(s.get('bwlimit_mb') or 0)}M" if int(s.get("bwlimit_mb") or 0) else "off"
        WORK.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.conf = WORK / f"rclone-{os.getpid()}.conf"
        # an already-expired placeholder access token: rclone then refreshes it; an empty one counts as "no token" (rclone 1.75)
        token = json.dumps({"access_token": "expired", "token_type": "Bearer", "refresh_token": db.decrypt(s["google_refresh_token"]),
                            "expiry": "2000-01-01T00:00:00Z"})
        local = os.environ.get("SYSTEM_BACKUP_LOCAL_REMOTE")  # tests and drills: a folder instead of Google Drive
        gd = ["[gd]", "type = local"] if local else [
            "[gd]", "type = drive", f"client_id = {s['google_client_id']}",
            f"client_secret = {db.decrypt(s['google_client_secret'])}", "scope = drive.file",
            f"root_folder_id = {s['google_folder_id']}", f"token = {token}"]
        text = "\n".join([
            *gd, "",
            "[zb]", "type = crypt", f"remote = gd:{local or ''}", "filename_encryption = standard", "directory_name_encryption = true",
            f"password = {self.obscure(self.key)}", f"password2 = {self.obscure(salt_for(self.key))}", ""])
        fd = os.open(self.conf, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w") as f:
            f.write(text)

    @staticmethod
    def obscure(value: str) -> str:
        return subprocess.run([RCLONE, "obscure", "-"], input=value.encode(), capture_output=True, check=True,
                              timeout=30).stdout.decode().strip()

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.conf.unlink(missing_ok=True)

    def rc(self, *args: str, timeout=3 * 3600, check=True) -> subprocess.CompletedProcess:
        try:
            return _run([RCLONE, "--config", str(self.conf), "--retries", "3", "--low-level-retries", "10", *args],
                        timeout=timeout, check=check)
        except BackupError as e:
            if "invalid_grant" in str(e) or "token has been expired or revoked" in str(e).lower():
                db.execute("UPDATE system_backup_settings SET google_error = %s, updated_at = now()", (GOOGLE_REVOKED,))
                raise BackupError(GOOGLE_REVOKED) from e
            raise

    def about(self) -> None:
        p = self.rc("about", "gd:", "--json", timeout=120, check=False)
        if p.returncode == 0:
            a = json.loads(p.stdout or b"{}")
            db.execute("""UPDATE system_backup_settings SET drive_total = %s, drive_used = %s, drive_checked_at = now(),
                          google_error = NULL, updated_at = now()""", (a.get("total"), a.get("used")))


def _tar(target: Path, paths: list[Path | tuple[Path, str]]) -> list[str]:
    """tar.gz of what exists; returns what went in. Absolute paths keep their place (restore with tar -C /);
    a (path, name) pair goes in under that name."""
    got = []
    with tarfile.open(target, "w:gz") as t:
        for item in paths:
            p, name = item if isinstance(item, tuple) else (item, str(item).lstrip("/"))
            if p.exists():
                t.add(p, arcname=name)
                got.append(name)
    os.chmod(target, 0o600)
    return got


# ---------------------------------------------------------------- parts of a run

def dump_db(stage: Path) -> dict:
    out = stage / f"{DB_NAME}.dump"
    with open(out, "wb") as f:
        _run(["nice", "-n", "10", "ionice", "-c3", *_as_postgres(["pg_dump", "-Fc", "-Z", "6", DB_NAME])], stdout=f)
    with open(stage / "globals.sql", "wb") as f:  # roles (with password hashes) the dump's owners refer to
        _run(_as_postgres(["pg_dumpall", "--globals-only"]), stdout=f)
    os.chmod(out, 0o600)
    os.chmod(stage / "globals.sql", 0o600)
    if out.stat().st_size < 1024:
        raise BackupError("ডাটাবেস dump খালি এসেছে")
    return {"db": out.stat().st_size, "tables": _counts(DB_NAME)}


def _counts(dbname: str) -> dict:
    sql = " UNION ALL ".join(f"SELECT '{t}', count(*) FROM {t}" for t in CHECK_TABLES)
    p = _run(_as_postgres(["psql", "-X", "-At", "-F", "\t", "-d", dbname, "-c", sql]), timeout=600)
    return {k: int(v) for k, v in (line.split("\t") for line in p.stdout.decode().splitlines() if "\t" in line)}


def secrets_part(stage: Path) -> dict:
    got = _tar(stage / "secrets.tar.gz", SECRETS)
    return {"secrets": (stage / "secrets.tar.gz").stat().st_size, "secret_paths": got}


def config_part(stage: Path) -> dict:
    extra = sorted(Path("/etc/systemd/system").glob("zyro-*"))
    lists = stage / "system"
    lists.mkdir()
    try:
        for name, cmd in (("dpkg-selections.txt", ["dpkg", "--get-selections"]),
                          ("pip-freeze.txt", [str(APP / ".venv" / "bin" / "pip"), "freeze"]),
                          ("timers.txt", ["systemctl", "list-unit-files", "zyro-*"])):
            try:
                (lists / name).write_bytes(_run(cmd, check=False, timeout=120).stdout or b"")
            except OSError as e:  # a missing tool only leaves its list out
                (lists / name).write_text(f"unavailable: {e}\n")
        got = _tar(stage / "config.tar.gz", [*CONFIG, *extra, (lists, "zyrodesk-system")])
    finally:
        shutil.rmtree(lists, ignore_errors=True)
    return {"config": (stage / "config.tar.gz").stat().st_size, "config_paths": got}


def code_part(stage: Path, bundle: bool) -> dict:
    # --no-optional-locks: as root, `git status` would otherwise rewrite .git/index root-owned and break the next deploy
    git = ["git", "--no-optional-locks", "-c", "safe.directory=*", "-C", str(APP)]
    head = _run([*git, "rev-parse", "HEAD"], check=False).stdout.decode().strip()
    status = _run([*git, "status", "--porcelain"], check=False).stdout.decode()
    diff = _run([*git, "diff", "HEAD"], check=False).stdout.decode(errors="replace")
    (stage / "code.txt").write_text(f"commit {head}\n\n# git status\n{status}\n# git diff HEAD\n{diff}")
    out = {"commit": head, "dirty": bool(status.strip())}
    if bundle:
        _run([*git, "bundle", "create", str(stage / "code.bundle"), "--all"], timeout=900)
        out["bundle"] = (stage / "code.bundle").stat().st_size
    return out


def sync_files(drive: Drive, stamp: str) -> dict:
    out = {}
    for src, dst, excludes in ((STORAGE, "files/current", EXCLUDE_FILES), (APK, "files/apk", EXCLUDE_APK)):
        if not src.is_dir():
            continue
        args = ["sync", str(src), f"zb:{dst}", "--backup-dir", f"zb:files/deleted/{stamp}/{dst.split('/')[-1]}",
                "--bwlimit", drive.bwlimit, "--transfers", "2", "--fast-list"]
        for ex in excludes:
            args += ["--exclude", ex]
        if src == APK:
            args.append("--delete-excluded")                     # builds already on Drive move to files/deleted and expire
        drive.rc(*args)
        files = [p for p in src.rglob("*") if p.is_file() and "livewire-tmp" not in p.parts and "system-backup-downloads" not in p.parts
                 and not (src == APK and p.name.startswith("zyro-desk-") and p.suffix == ".apk")]
        out[dst] = {"files": len(files), "bytes": sum(p.stat().st_size for p in files)}
    return out


# ---------------------------------------------------------------- jobs

def tier_for(kind: str, trigger: str, at: datetime) -> str:
    if trigger == "manual":
        return "manual"
    if kind == "db":
        return "hourly"
    return "monthly" if at.day == 1 else ("weekly" if at.weekday() == 6 else "daily")


def run_backup(job: dict, s: dict) -> None:
    kind, at = job["kind"], now_dhaka()
    tier = tier_for(kind, job["trigger"], at)
    remote = f"runs/{at:%Y-%m-%d_%H%M}-{kind}" + ("" if job["trigger"] == "auto" else f"-{job['id']}")
    stage = WORK / "stage" / str(job["id"])
    shutil.rmtree(stage, ignore_errors=True)
    stage.mkdir(parents=True, mode=0o700)
    parts, errors = {}, []
    db.execute("UPDATE system_backups SET tier = %s, remote_dir = %s, updated_at = now() WHERE id = %s", (tier, remote, job["id"]))
    try:
        with Drive(s) as drive:
            parts.update(dump_db(stage))
            if kind == "full":
                for name, fn, on in (("secrets", secrets_part, s.get("include_secrets")), ("config", config_part, s.get("include_config")),
                                     ("code", lambda st: code_part(st, tier in ("weekly", "monthly", "manual")), True)):
                    if not on:
                        continue
                    try:
                        parts.update(fn(stage))
                    except Exception as e:
                        errors.append(f"{name}: {str(e)[:300]}")
            manifest = {"made_at": at.isoformat(), "kind": kind, "tier": tier, "database": DB_NAME,
                        "files": {p.name: {"size": p.stat().st_size, "sha256": _sha256(p)} for p in sorted(stage.iterdir()) if p.is_file()},
                        "parts": parts, "errors": errors}
            (stage / "manifest.json").write_text(json.dumps(manifest, indent=1, ensure_ascii=False))
            drive.rc("copy", str(stage), f"zb:{remote}", "--bwlimit", drive.bwlimit)
            drive.rc("cryptcheck", str(stage), f"zb:{remote}", "--one-way")   # what is on Drive matches, byte for byte
            size = sum(p.stat().st_size for p in stage.iterdir() if p.is_file())
            if kind == "full" and s.get("include_files"):
                try:
                    parts["synced"] = sync_files(drive, f"{at:%Y-%m-%d}")
                except Exception as e:
                    errors.append(f"files: {str(e)[:300]}")
            # newest database dump also stays on this server (fast restore without Drive)
            latest = WORK / "latest"
            latest.mkdir(exist_ok=True, mode=0o700)
            shutil.copy2(stage / f"{DB_NAME}.dump", latest / f"{DB_NAME}.dump")
            if kind == "full":  # retention once a night, after the new backup is safely there
                try:
                    parts["pruned"] = prune(s, drive)
                except Exception as e:
                    log.warning("prune failed: %s", e)
            drive.about()
        status = "partial" if errors else "ok"
        _finish(job["id"], status, size=size, parts=parts, error="; ".join(errors) or None)
        if errors:
            alert(s, f"ব্যাকআপ আংশিক হয়েছে ({kind}): " + "; ".join(errors)[:300])
    finally:
        shutil.rmtree(stage, ignore_errors=True)


def restore_test(job: dict, s: dict) -> None:
    """Latest (or chosen) dump from Drive into a scratch database; compare key tables with live; sample files."""
    src = _source(job)
    test_db = f"{DB_NAME}_restore_test"
    tmp = WORK / "restore" / str(job["id"])
    shutil.rmtree(tmp, ignore_errors=True)
    tmp.mkdir(parents=True, mode=0o700)
    parts, errors = {"source": src["id"], "source_at": str(src["started_at"])}, []
    try:
        with Drive(s) as drive:
            drive.rc("copy", f"zb:{src['remote_dir']}/{DB_NAME}.dump", str(tmp))
            dump = tmp / f"{DB_NAME}.dump"
            _run(_as_postgres(["dropdb", "--if-exists", test_db]))
            _run(_as_postgres(["createdb", test_db]))
            t0 = time.monotonic()
            with open(dump, "rb") as f:
                _run(_as_postgres(["pg_restore", "--no-owner", "--no-acl", "-d", test_db]), stdin=f)
            parts["restore_seconds"] = round(time.monotonic() - t0)
            live, restored = _counts(DB_NAME), _counts(test_db)
            parts["tables"] = {t: {"live": live.get(t), "backup": restored.get(t)} for t in CHECK_TABLES}
            empty = [t for t in CHECK_TABLES if (live.get(t) or 0) > 0 and not restored.get(t)]
            if empty:
                errors.append("খালি টেবিল: " + ", ".join(empty))
            # a few random files: what Drive holds decrypts to the same bytes as here
            files = [p for p in STORAGE.rglob("*") if p.is_file() and "livewire-tmp" not in p.parts and "system-backup-downloads" not in p.parts]
            sample = random.sample(files, min(20, len(files)))
            if sample:
                lst = tmp / "sample.txt"
                lst.write_text("\n".join(str(p.relative_to(STORAGE)) for p in sample))
                p = drive.rc("cryptcheck", str(STORAGE), "zb:files/current", "--files-from", str(lst), "--one-way", check=False)
                parts["files_checked"] = len(sample)
                if p.returncode != 0:
                    errors.append("ফাইল মেলেনি: " + (p.stderr or b"").decode(errors="replace")[-300:])
    finally:
        _run(_as_postgres(["dropdb", "--if-exists", test_db]), check=False)
        shutil.rmtree(tmp, ignore_errors=True)
    _finish(job["id"], "failed" if errors else "ok", parts=parts, error="; ".join(errors) or None)
    if errors:
        alert(s, "রিস্টোর টেস্ট ফেল করেছে: " + "; ".join(errors)[:300])


def prepare_download(job: dict, s: dict) -> None:
    """Decrypted copy of one run as a .tar on the panel's private disk, for two hours."""
    src = _source(job)
    tmp = WORK / "download" / str(job["id"])
    shutil.rmtree(tmp, ignore_errors=True)
    tmp.mkdir(parents=True, mode=0o700)
    try:
        with Drive(s) as drive:
            drive.rc("copy", f"zb:{src['remote_dir']}", str(tmp))
        name = src["remote_dir"].split("/")[-1]
        folder = STORAGE / DOWNLOADS / str(job["id"])
        folder.mkdir(parents=True, exist_ok=True)
        target = folder / f"zyrodesk-{name}.tar"
        with tarfile.open(target, "w") as t:
            t.add(tmp, arcname=name)
        _panel_readable(folder, target)
        rel = str(target.relative_to(STORAGE / "private"))  # the panel's local disk root is storage/app/private
        db.execute("UPDATE system_backups SET download_path = %s, size = %s, expires_at = now() + interval '2 hours' WHERE id = %s",
                   (rel, target.stat().st_size, job["id"]))
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    _finish(job["id"], "ok")


def _panel_readable(folder: Path, file: Path) -> None:
    """claude-dev:www-data, group read only (the same as the MikroTik backup files)."""
    for p, mode in ((folder.parent, 0o2750), (folder, 0o2750), (file, 0o640)):
        shutil.chown(p, "claude-dev", "www-data")
        os.chmod(p, mode)


def _source(job: dict) -> dict:
    src = db.one("SELECT * FROM system_backups WHERE id = %s", (job["source_id"],)) if job.get("source_id") else \
        db.one("""SELECT * FROM system_backups WHERE kind IN ('db', 'full') AND status IN ('ok', 'partial') AND NOT pruned
                  AND remote_dir IS NOT NULL ORDER BY started_at DESC LIMIT 1""")
    if not src or not src.get("remote_dir") or src.get("pruned"):
        raise BackupError("কোনো ব্যবহারযোগ্য ব্যাকআপ পাওয়া যায়নি")
    if not job.get("source_id"):
        db.execute("UPDATE system_backups SET source_id = %s WHERE id = %s", (src["id"], job["id"]))
    return src


def _finish(job_id: int, status: str, size=None, parts=None, error=None) -> None:
    db.execute("""UPDATE system_backups SET status = %s, size = COALESCE(%s, size), parts = COALESCE(%s::json, parts),
                  error = %s, finished_at = now(), updated_at = now() WHERE id = %s""",
               (status, size, json.dumps(parts, ensure_ascii=False, default=str) if parts is not None else None, error, job_id))


JOBS = {"db": run_backup, "full": run_backup, "restore_test": restore_test, "download": prepare_download}


def execute(job: dict, s: dict) -> None:
    db.execute("UPDATE system_backups SET status = 'running', started_at = now(), updated_at = now() WHERE id = %s", (job["id"],))
    t0 = time.monotonic()
    try:
        JOBS[job["kind"]](job, s)
    except Exception as e:
        log.exception("job %s (%s) failed", job["id"], job["kind"])
        _finish(job["id"], "failed", error=str(e)[:1000])
        if job["kind"] != "download":
            alert(s, f"ব্যাকআপ হয়নি ({job['kind']}): {str(e)[:300]}")
    log.info("job %s (%s) done in %.0fs", job["id"], job["kind"], time.monotonic() - t0)


def queue(kind: str, trigger: str = "auto", source_id: int | None = None) -> dict:
    return db.execute("""INSERT INTO system_backups (kind, trigger, status, source_id, created_at, updated_at)
                         VALUES (%s, %s, 'queued', %s, now(), now()) RETURNING *""", (kind, trigger, source_id))


# ---------------------------------------------------------------- schedule

def _last(where: str, params=()) -> dict | None:
    return db.one(f"SELECT * FROM system_backups WHERE {where} ORDER BY created_at DESC LIMIT 1", params)


def due(s: dict, at: datetime) -> str | None:
    """Which scheduled job should start now, if any."""
    if not s.get("enabled") or not s.get("google_refresh_token") or not s.get("crypt_key_confirmed_at") or s.get("google_error"):
        return None
    hh, mm = (int(x) for x in (s.get("full_at") or "03:30").split(":"))
    slot = at.replace(hour=hh, minute=mm, second=0, microsecond=0)
    if at >= slot:
        today = slot.astimezone(timezone.utc).replace(tzinfo=None)
        tries = db.all_rows("""SELECT status, finished_at FROM system_backups WHERE kind = 'full' AND trigger = 'auto'
                               AND created_at >= %s ORDER BY created_at DESC""", (today,))
        if not any(t["status"] in ("ok", "partial") for t in tries) and len(tries) < 3:
            # a failed try is repeated once an hour, three tries a night at most
            if not tries or db.one("SELECT 1 WHERE %s < now() - interval '1 hour'", (tries[0]["finished_at"],)):
                return "full"
        if (s.get("weekly_restore_test") and at.weekday() == 6 and any(t["status"] in ("ok", "partial") for t in tries)
                and not _last("kind = 'restore_test' AND created_at > now() - interval '6 days'")):
            return "restore_test"
    hours = int(s.get("db_every_hours") or 0)
    if hours and not _last("kind IN ('db', 'full') AND status IN ('ok', 'partial', 'running', 'queued') "
                           "AND created_at > now() - make_interval(mins => %s)", (hours * 60 - 10,)):
        last_try = _last("kind = 'db' AND trigger = 'auto'")
        if not last_try or last_try["status"] != "failed" or \
                db.one("SELECT 1 WHERE %s < now() - interval '1 hour'", (last_try["finished_at"],)):
            return "db"
    return None


def prune(s: dict, drive: Drive) -> int:
    """Drop runs past their tier's retention (Drive folder, then mark the row), and old deleted-file folders."""
    keep = {"hourly": timedelta(days=s.get("keep_hourly_days") or 7), "daily": timedelta(days=s.get("keep_daily_days") or 30),
            "manual": timedelta(days=s.get("keep_daily_days") or 30), "weekly": timedelta(weeks=s.get("keep_weekly") or 12),
            "monthly": timedelta(days=31 * (s.get("keep_monthly") or 12))}
    rows = db.all_rows("""SELECT id, tier, remote_dir, status, started_at FROM system_backups
                          WHERE kind IN ('db', 'full') AND NOT pruned AND remote_dir IS NOT NULL AND status NOT IN ('queued', 'running')""")
    utc_now = datetime.now(timezone.utc).replace(tzinfo=None)
    old = [r for r in rows if r["started_at"] and utc_now - r["started_at"] > (keep.get(r["tier"] or "daily") or keep["daily"])
           or (r["status"] == "failed" and r["started_at"] and utc_now - r["started_at"] > timedelta(days=2))]
    # never drop the newest good backup, whatever its age
    newest = db.one("SELECT id FROM system_backups WHERE kind IN ('db','full') AND status IN ('ok','partial') AND NOT pruned "
                    "ORDER BY started_at DESC LIMIT 1")
    old = [r for r in old if not newest or r["id"] != newest["id"]]
    gone = 0
    for r in old:
        if r["remote_dir"].startswith("runs/"):
            drive.rc("purge", f"zb:{r['remote_dir']}", check=False, timeout=600)
        db.execute("UPDATE system_backups SET pruned = true, updated_at = now() WHERE id = %s", (r["id"],))
        gone += 1
    p = drive.rc("lsf", "zb:files/deleted", "--dirs-only", check=False, timeout=300)
    cutoff = (now_dhaka() - timedelta(days=s.get("keep_deleted_files_days") or 30)).strftime("%Y-%m-%d")
    for d in (p.stdout or b"").decode().split():
        d = d.strip("/")
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", d) and d < cutoff:
            drive.rc("purge", f"zb:files/deleted/{d}", check=False, timeout=600)
    return gone


def cleanup_downloads() -> None:
    for r in db.all_rows("""SELECT id, download_path FROM system_backups WHERE kind = 'download' AND download_path IS NOT NULL
                            AND expires_at < now()"""):
        shutil.rmtree(STORAGE / DOWNLOADS / str(r["id"]), ignore_errors=True)
        db.execute("UPDATE system_backups SET download_path = NULL, updated_at = now() WHERE id = %s", (r["id"],))


def stale_alert(s: dict) -> None:
    if not s.get("enabled"):
        return
    if _last("kind IN ('db','full') AND status IN ('ok','partial') AND started_at > now() - interval '26 hours'"):
        return
    if not db.one("SELECT 1 FROM system_backups WHERE kind IN ('db','full')"):
        return  # nothing has ever run (just switched on)
    if s.get("stale_alert_at") and db.one("SELECT 1 WHERE %s > now() - interval '24 hours'", (s["stale_alert_at"],)):
        return
    db.execute("UPDATE system_backup_settings SET stale_alert_at = now()")
    alert(s, "২৬ ঘণ্টার বেশি কোনো সফল ব্যাকআপ হয়নি। " + (s.get("google_error") or ""))


# ---------------------------------------------------------------- alerts

ALERT_TEMPLATE = "system_backup_alert"
ALERT_BODY = "ZyroDesk সিস্টেম ব্যাকআপ সতর্কতা: {{1}}\nসময়: {{2}}\nবিস্তারিত দেখুন Desk সুপার অ্যাডমিন প্যানেলে।"
ALERT_EXAMPLE = ["ডাটাবেস ব্যাকআপ হয়নি (Google Drive সংযোগ বাতিল)", "22 Oct 2026, 03:30 AM"]


def alert(s: dict, text: str) -> None:
    """WhatsApp to the super admin's numbers from one company's number: a text inside 24 hours, else the template."""
    log.warning("ALERT: %s", text)
    numbers = [n for n in re.split(r"[\s,]+", s.get("alert_numbers") or "") if n]
    acc = db.one("SELECT phone_number_id FROM wa_accounts WHERE id = %s", (s["alert_wa_account_id"],)) \
        if s.get("alert_wa_account_id") else None
    if not numbers or not acc or DRY_RUN:
        return
    import httpx
    from engine import tenant as tenants
    from engine.ticket_notify import _in_window, _one_line
    from engine.whatsapp import GRAPH, _check, send_text
    t = tenants.by_phone_number_id(acc["phone_number_id"])
    if not t:
        return
    when = now_dhaka().strftime("%d %b %Y, %I:%M %p")
    for n in numbers:
        try:
            if _in_window(t, n):
                send_text(t, n, f"⚠️ ZyroDesk ব্যাকআপ: {text}\nসময়: {when}")
                continue
            auth = {"Authorization": f"Bearer {t.wa_token}"}
            rows = _check(httpx.get(f"{GRAPH}/{t.waba_id}/message_templates", params={"name": ALERT_TEMPLATE},
                                    headers=auth, timeout=30)).get("data") or []
            row = next((r for r in rows if r.get("name") == ALERT_TEMPLATE), None)
            if not row:
                _check(httpx.post(f"{GRAPH}/{t.waba_id}/message_templates", headers=auth, timeout=30, json={
                    "name": ALERT_TEMPLATE, "language": "bn", "category": "UTILITY",
                    "components": [{"type": "BODY", "text": ALERT_BODY, "example": {"body_text": [ALERT_EXAMPLE]}}]}))
                log.info("alert template submitted; WhatsApp alerts start once Meta approves it")
                return
            if row.get("status") != "APPROVED":
                return
            _check(httpx.post(f"{GRAPH}/{t.phone_number_id}/messages", headers=auth, timeout=30, json={
                "messaging_product": "whatsapp", "to": n, "type": "template",
                "template": {"name": ALERT_TEMPLATE, "language": {"code": "bn"}, "components": [{"type": "body", "parameters": [
                    {"type": "text", "text": _one_line(text, 300)}, {"type": "text", "text": when}]}]}}))
        except Exception:
            log.warning("backup alert to %s failed", n, exc_info=True)


# ---------------------------------------------------------------- restore (command line; see docs/restore.md)

def restore_db(backup_id: int) -> None:
    """Replace the live database with backup `backup_id`. The current one is renamed, not dropped, and dumped first."""
    s = settings()
    src = db.one("SELECT * FROM system_backups WHERE id = %s", (backup_id,))
    if not src or not src.get("remote_dir"):
        raise BackupError("ব্যাকআপ পাওয়া যায়নি")
    ts = now_dhaka().strftime("%Y%m%d_%H%M")
    tmp = WORK / "restore-live"
    tmp.mkdir(parents=True, exist_ok=True, mode=0o700)
    with open(tmp / f"before-restore-{ts}.dump", "wb") as f:  # safety copy of what is live now
        _run(_as_postgres(["pg_dump", "-Fc", DB_NAME]), stdout=f)
    with Drive(s) as drive:
        drive.rc("copy", f"zb:{src['remote_dir']}/{DB_NAME}.dump", str(tmp))
    owner = _run(_as_postgres(["psql", "-X", "-At", "-c",
                               f"SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '{DB_NAME}'"])).stdout.decode().strip()
    new = f"{DB_NAME}_restoring"
    _run(_as_postgres(["dropdb", "--if-exists", new]))
    _run(_as_postgres(["createdb", "-O", owner, new]))
    with open(tmp / f"{DB_NAME}.dump", "rb") as f:
        _run(_as_postgres(["pg_restore", "-d", new]), stdin=f)
    services = ["zyro-engine", "zyro-sip", "zyro-voice", "php8.3-fpm"]
    _run(["systemctl", "stop", *services], check=False)
    try:
        _run(_as_postgres(["psql", "-X", "-v", "ON_ERROR_STOP=1", "-c",
                           f"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{DB_NAME}' AND pid <> pg_backend_pid();",
                           "-c", f"ALTER DATABASE {DB_NAME} RENAME TO {DB_NAME}_before_{ts};",
                           "-c", f"ALTER DATABASE {new} RENAME TO {DB_NAME};"]))
    finally:
        _run(["systemctl", "start", *services], check=False)
    print(f"done: live database is now backup {backup_id} ({src['remote_dir']}); old one kept as {DB_NAME}_before_{ts}, "
          f"dump at {tmp / f'before-restore-{ts}.dump'}. Run the panel migrations if the backup is older than the code.")


def restore_files(path: str = "") -> None:
    """Copy panel/storage/app (or one path in it) back from Drive. Never deletes local files."""
    s = settings()
    path = path.strip("/")
    if ".." in path.split("/"):
        raise BackupError("bad path")
    with Drive(s) as drive:
        drive.rc("copy", f"zb:files/current/{path}".rstrip("/"), str(STORAGE / path) if path else str(STORAGE))
    print("files copied back")


# ---------------------------------------------------------------- entry

def tick() -> None:
    WORK.mkdir(parents=True, exist_ok=True, mode=0o700)
    with open(WORK / ".lock", "w") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return  # a job is still running
        # we hold the lock, so nothing is really running: a "running" row was cut short
        db.execute("""UPDATE system_backups SET status = 'failed', error = 'বন্ধ হয়ে গেছে (সার্ভার রিস্টার্ট বা টাইমআউট)',
                      finished_at = now(), updated_at = now() WHERE status = 'running'""")
        s = settings()
        if not s:
            return
        job = db.one("SELECT * FROM system_backups WHERE status = 'queued' ORDER BY created_at LIMIT 1")
        if not job:
            kind = due(s, now_dhaka())
            if kind:
                job = queue(kind)
        if job:
            execute(job, settings())
            return
        cleanup_downloads()
        stale_alert(s)
        if s.get("google_refresh_token") and not db.one("SELECT 1 WHERE %s > now() - interval '6 hours'", (s.get("drive_checked_at"),)):
            try:  # keeps the space figures fresh and notices a revoked token even while backups are off
                with Drive(s) as drive:
                    drive.about()
            except Exception:
                log.warning("Drive check failed", exc_info=True)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    args = sys.argv[1:]
    if not args:
        tick()
    elif args[0] == "run" and len(args) > 1 and args[1] in ("db", "full", "restore_test"):
        queue(args[1], "manual")
        tick()
    elif args[0] == "restore-db" and len(args) > 1:
        if "--yes" not in args:
            sys.exit("this replaces the LIVE database; add --yes")
        restore_db(int(args[1]))
    elif args[0] == "restore-files":
        restore_files(args[1] if len(args) > 1 else "")
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
