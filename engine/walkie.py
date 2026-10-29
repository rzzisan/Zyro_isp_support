"""Walkie-talkie service (zyro-walkie.service): records the Desk app's LiveKit rooms and turns the app on and off daily.

Rooms: c{company}-g{group} is a group channel (everyone listens, push-to-talk unmutes the microphone),
c{company}-p{call} a private call between two members. A hidden participant joins every room with people in it and
writes each transmission of a group (from unmute to mute) as one clip with who spoke, and a private call as one clip
with both sides mixed: storage/app/walkie/{company}/{day}/..mp3 and a walkie_clips row (the panel and app play them).
At the company's on time (bot_settings.walkie_on_at, default 10:00 Asia/Dhaka) every group member's phone gets a
"walkie_on" push, at the off time a "walkie_off"; recordings older than walkie_keep_days (90) are deleted.

Needs LIVEKIT_API_URL (http://127.0.0.1:7880), LIVEKIT_API_KEY, LIVEKIT_API_SECRET in engine/.env.
"""
import asyncio
import logging
import os
import re
import subprocess
import tempfile
import time
import uuid
import wave
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from livekit import api, rtc

from engine import db, fcm

log = logging.getLogger("zyro-walkie")
URL = os.environ.get("LIVEKIT_API_URL", "http://127.0.0.1:7880")
KEY = os.environ.get("LIVEKIT_API_KEY", "")
SECRET = os.environ.get("LIVEKIT_API_SECRET", "")
DIR = os.environ.get("WALKIE_REC_DIR", "/var/www/zyro_isp_support/panel/storage/app/walkie")
TZ = ZoneInfo("Asia/Dhaka")
RATE = 16000
ROOM = re.compile(r"^c(\d+)-([gp])(\d+)$")
MIN_SECONDS = 0.6     # a tap on the button, not a transmission
MAX_SECONDS = 300     # a stuck button: cut the clip here and start the next


def _user(identity: str) -> int | None:
    return int(identity[1:]) if re.fullmatch(r"u\d+", identity or "") else None


def _mp3(inputs: list[tuple[str, int]], out: str) -> bool:
    """Wav files (with their delay in ms) mixed into one mono mp3."""
    args = ["ffmpeg", "-loglevel", "error", "-y"]
    for path, _ in inputs:
        args += ["-i", path]
    if len(inputs) > 1:
        chains = "".join(f"[{i}]adelay={d}:all=1[a{i}];" for i, (_, d) in enumerate(inputs))
        mix = "".join(f"[a{i}]" for i in range(len(inputs)))
        args += ["-filter_complex", f"{chains}{mix}amix=inputs={len(inputs)}:normalize=0"]
    args += ["-ac", "1", "-b:a", "32k", out]
    r = subprocess.run(args, capture_output=True, text=True, timeout=300)
    if r.returncode != 0:
        log.error("ffmpeg: %s", r.stderr[:300])
    return r.returncode == 0


def _save(company_id: int, started: datetime, seconds: float, inputs: list[tuple[str, int]],
          group_id: int | None = None, call_id: int | None = None, user_id: int | None = None) -> None:
    day = started.astimezone(TZ).strftime("%Y-%m-%d")
    rel = f"{company_id}/{day}/{started.astimezone(TZ).strftime('%H%M%S')}-{uuid.uuid4().hex[:8]}.mp3"
    out = os.path.join(DIR, rel)
    os.makedirs(os.path.dirname(out), mode=0o2750, exist_ok=True)
    try:
        if not _mp3(inputs, out):
            return
        db.execute("""INSERT INTO walkie_clips (company_id, walkie_group_id, walkie_call_id, user_id, started_at, seconds, path)
                      VALUES (%s, %s, %s, %s, %s, %s, %s)""",
                   (company_id, group_id, call_id, user_id, started, round(seconds, 1), rel))
    finally:
        for path, _ in inputs:
            try:
                os.unlink(path)
            except OSError:
                pass


class Track:
    """One participant's audio in a room, written to a wav while it counts (unmuted in a group, always in a call)."""

    def __init__(self, rec: "RoomRecorder", identity: str):
        self.rec, self.identity = rec, identity
        self.wav: wave.Wave_write | None = None
        self.path = ""
        self.started: datetime | None = None
        self.t0 = 0.0          # monotonic time of the first sample
        self.samples = 0
        self.open = False      # unmuted (group) / joined (call)

    def start(self) -> None:
        if self.wav:
            return
        fd, self.path = tempfile.mkstemp(prefix="walkie-", suffix=".wav")
        os.close(fd)
        self.wav = wave.open(self.path, "wb")
        self.wav.setnchannels(1)
        self.wav.setsampwidth(2)
        self.wav.setframerate(RATE)
        self.started = datetime.now(timezone.utc)
        self.t0 = time.monotonic()
        self.samples = 0

    def write(self, frame: rtc.AudioFrame) -> None:
        if not self.open:
            return
        self.start()
        # keep the clock: a pause in the packets (mute in a call, a bad network) becomes silence, not a jump
        behind = int((time.monotonic() - self.t0) * RATE) - self.samples - frame.samples_per_channel
        if behind > RATE // 5:
            self.wav.writeframes(b"\x00\x00" * behind)
            self.samples += behind
        self.wav.writeframes(bytes(frame.data))
        self.samples += frame.samples_per_channel
        if self.rec.kind == "g" and self.samples > MAX_SECONDS * RATE:
            self.finish()
            self.start()

    def finish(self) -> tuple[str, datetime, float] | None:
        """Close the wav; a group transmission is saved now, a call's side is returned to be mixed."""
        if not self.wav:
            return None
        self.wav.close()
        self.wav = None
        seconds = self.samples / RATE
        part = (self.path, self.started, seconds)
        if self.rec.kind == "g":
            if seconds >= MIN_SECONDS:
                asyncio.get_running_loop().run_in_executor(None, lambda: _save(
                    self.rec.company_id, part[1], part[2], [(part[0], 0)], group_id=self.rec.ref, user_id=_user(self.identity)))
            else:
                os.unlink(part[0])
            return None
        return part


class RoomRecorder:
    def __init__(self, name: str):
        m = ROOM.match(name)
        self.name = name
        self.company_id, self.kind, self.ref = int(m[1]), m[2], int(m[3])
        self.room = rtc.Room()
        self.tracks: dict[str, Track] = {}
        self.parts: list[tuple[str, datetime, float]] = []
        self.done = asyncio.Event()

    def track(self, identity: str) -> Track:
        if identity not in self.tracks:
            self.tracks[identity] = Track(self, identity)
        return self.tracks[identity]

    async def run(self) -> None:
        token = (api.AccessToken(KEY, SECRET).with_identity("rec").with_name("rec")
                 .with_grants(api.VideoGrants(room_join=True, room=self.name, hidden=True, can_publish=False,
                                              can_publish_data=False, can_subscribe=True)).to_jwt())

        @self.room.on("track_subscribed")
        def subscribed(track: rtc.Track, pub: rtc.RemoteTrackPublication, p: rtc.RemoteParticipant):
            if track.kind != rtc.TrackKind.KIND_AUDIO:
                return
            t = self.track(p.identity)
            t.open = self.kind == "p" or not pub.muted
            asyncio.ensure_future(self.read(track, t))

        @self.room.on("track_muted")
        def muted(p: rtc.Participant, pub: rtc.TrackPublication):
            if self.kind == "g" and p.identity in self.tracks:
                t = self.tracks[p.identity]
                t.open = False
                t.finish()

        @self.room.on("track_unmuted")
        def unmuted(p: rtc.Participant, pub: rtc.TrackPublication):
            if self.kind == "g" and p.identity in self.tracks:
                self.tracks[p.identity].open = True

        @self.room.on("track_unsubscribed")
        def unsubscribed(track: rtc.Track, pub: rtc.RemoteTrackPublication, p: rtc.RemoteParticipant):
            self.close(p.identity)

        @self.room.on("participant_disconnected")
        def left(p: rtc.RemoteParticipant):
            self.close(p.identity)
            if not self.room.remote_participants:
                self.done.set()

        @self.room.on("disconnected")
        def gone(*_):
            self.done.set()

        await self.room.connect(URL.replace("http", "ws", 1), token, rtc.RoomOptions(auto_subscribe=True))
        log.info("recording %s", self.name)
        try:
            while not self.done.is_set():
                try:
                    await asyncio.wait_for(self.done.wait(), 15)
                except asyncio.TimeoutError:
                    if not self.room.remote_participants:
                        break
        finally:
            for identity in list(self.tracks):
                self.close(identity)
            await self.room.disconnect()
            if self.kind == "p":
                await asyncio.get_running_loop().run_in_executor(None, self.save_call)
            log.info("left %s", self.name)

    async def read(self, track: rtc.Track, t: Track) -> None:
        stream = rtc.AudioStream.from_track(track=track, sample_rate=RATE, num_channels=1)
        try:
            async for ev in stream:
                t.write(ev.frame)
        finally:
            await stream.aclose()

    def close(self, identity: str) -> None:
        t = self.tracks.get(identity)
        if t:
            t.open = False
            part = t.finish()
            if part:
                self.parts.append(part)

    def save_call(self) -> None:
        """Both sides of a private call in one clip; a call nobody answered may still be left talking: end it."""
        db.execute("UPDATE walkie_calls SET status = 'ended', ended_at = now(), updated_at = now() WHERE id = %s AND status = 'talking'",
                   (self.ref,))
        parts = [p for p in self.parts if p[2] >= MIN_SECONDS]
        for p in self.parts:
            if p not in parts:
                os.unlink(p[0])
        if not parts:
            return
        first = min(p[1] for p in parts)
        end = max(p[1] + timedelta(seconds=p[2]) for p in parts)
        inputs = [(p[0], int((p[1] - first).total_seconds() * 1000)) for p in parts]
        _save(self.company_id, first, (end - first).total_seconds(), inputs, call_id=self.ref)


async def recorder() -> None:
    """Join every walkie room that has people in it (looked up every 2 seconds)."""
    active: dict[str, asyncio.Task] = {}
    lk = api.LiveKitAPI(URL, KEY, SECRET)
    while True:
        try:
            rooms = (await lk.room.list_rooms(api.ListRoomsRequest())).rooms
            for r in rooms:
                if ROOM.match(r.name) and r.num_participants > 0 and (r.name not in active or active[r.name].done()):
                    active[r.name] = asyncio.create_task(RoomRecorder(r.name).run())
        except Exception:
            log.exception("room list failed")
        for name in [n for n, task in active.items() if task.done()]:
            if (e := active.pop(name).exception()) is not None:
                log.error("recorder %s: %r", name, e)
        await asyncio.sleep(2)


def _members(company_id: int) -> list[int]:
    return [r["user_id"] for r in db.all_rows(
        """SELECT DISTINCT cu.user_id FROM membership_walkie_group mg
           JOIN company_user cu ON cu.id = mg.membership_id JOIN walkie_groups g ON g.id = mg.walkie_group_id
           WHERE g.company_id = %s""", (company_id,))]


def daily() -> None:
    """At the on / off minute (Asia/Dhaka) push the members' phones; once a day delete old recordings."""
    sent: set[tuple[int, str, str]] = set()
    pruned = ""
    while True:
        try:
            now = datetime.now(TZ)
            hm, day = now.strftime("%H:%M"), now.strftime("%Y-%m-%d")
            for c in db.all_rows("""SELECT c.id, c.slug, COALESCE(s.walkie_on_at, '10:00') AS on_at, s.walkie_off_at AS off_at
                                    FROM companies c LEFT JOIN bot_settings s ON s.company_id = c.id
                                    WHERE EXISTS (SELECT 1 FROM walkie_groups g WHERE g.company_id = c.id)"""):
                for kind, at in (("walkie_on", c["on_at"]), ("walkie_off", c["off_at"])):
                    if at and at[:5] == hm and (c["id"], kind, day) not in sent:
                        sent.add((c["id"], kind, day))
                        users = _members(c["id"])
                        log.info("%s for company %s: %d members", kind, c["id"], len(users))
                        if users:
                            fcm.send_users(c["id"], users, {"type": kind, "company": c["slug"] or ""})
            if pruned != day and now.hour >= 3:
                pruned = day
                prune()
        except Exception:
            log.exception("walkie daily failed")
        time.sleep(20)


def prune() -> None:
    rows = db.all_rows("""DELETE FROM walkie_clips w USING companies c LEFT JOIN bot_settings s ON s.company_id = c.id
                          WHERE w.company_id = c.id AND w.started_at < now() - make_interval(days => COALESCE(s.walkie_keep_days, 90))
                          RETURNING w.path""")
    for r in rows:
        try:
            os.unlink(os.path.join(DIR, r["path"]))
        except OSError:
            pass
    if rows:
        log.info("deleted %d old walkie recordings", len(rows))


async def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    if not KEY or not SECRET:
        log.error("LIVEKIT_API_KEY / LIVEKIT_API_SECRET not set; nothing to do")
        return
    asyncio.get_running_loop().run_in_executor(None, daily)
    await recorder()


if __name__ == "__main__":
    asyncio.run(main())
