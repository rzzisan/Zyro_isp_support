"""engine/voice_ivr.py callback_replay: a call back after a missed reminder call hears the reminder once.
  PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_callback_replay
"""
import json
import types
import unittest
from unittest import mock

from engine import voice_ivr as vi

FLOW = {"flow": {"steps": [{"name": "শুরু", "templates": [7, 9]}, {"name": "মেনু", "templates": [9]}]},
        "audio": {"7": {"file": "a" * 20}, "9": {"file": "b" * 20}}}


class FakeDb:
    def __init__(self, settings, row, mark=True):
        self.settings, self.row, self.mark, self.sql = settings, row, mark, []

    def one(self, sql, args=()):
        self.sql.append((sql, args))
        if "from voice_reminder_settings" in sql:
            return self.settings
        if sql.startswith("select id, status, package"):
            return self.row
        if sql.startswith("update voice_reminder_calls set callback_played_at"):
            return {"id": 1} if self.mark else None
        return None


class ReplayIdsTest(unittest.TestCase):
    def test_menu_repeated_later_stays_out(self):
        self.assertEqual(vi.replay_ids(FLOW), ["7"])

    def test_all_repeated_gives_first(self):
        p = {"flow": {"steps": [{"name": "a", "templates": [9]}, {"name": "b", "templates": [9]}]}}
        self.assertEqual(vi.replay_ids(p), ["9"])

    def test_no_steps(self):
        self.assertEqual(vi.replay_ids({}), [])


class CallbackReplayTest(unittest.TestCase):
    def run_it(self, settings, row, mark=True):
        fake = FakeDb(settings, row, mark)
        v = types.SimpleNamespace(normalize=lambda n: "8801711000000" if n else None)
        with mock.patch.object(vi, "db", fake), mock.patch.object(vi, "vb", lambda: v), \
                mock.patch.object(vi, "_caller_line", lambda c, n: 55), \
                mock.patch.object(vi, "sound", lambda c, a: "/ivr/1/" + a["file"]):
            return vi.callback_replay(1, "01711000000"), fake

    ON = {"callback_on": True, "callback_hours": 24, "test_mode": False}

    def row(self, status="no_answer", played=None):
        return {"id": 1, "status": status, "package": json.dumps(FLOW), "callback_played_at": played}

    def test_missed_call_replays_and_marks(self):
        out, fake = self.run_it(self.ON, self.row())
        self.assertEqual(out, "replay:/ivr/1/" + "a" * 20)
        q = next(a for s, a in fake.sql if s.startswith("select id, status"))
        self.assertEqual(q, (1, "8801711000000", 55, 24))

    def test_only_once(self):
        self.assertIsNone(self.run_it(self.ON, self.row(played="2026-11-06"))[0])
        self.assertIsNone(self.run_it(self.ON, self.row(), mark=False)[0])

    def test_answered_since_or_none(self):
        self.assertIsNone(self.run_it(self.ON, self.row(status="done"))[0])
        self.assertIsNone(self.run_it(self.ON, None)[0])

    def test_switched_off(self):
        self.assertIsNone(self.run_it(dict(self.ON, callback_on=False), self.row())[0])

    def test_errors_never_block_the_call(self):
        with mock.patch.object(vi, "_callback_replay", side_effect=RuntimeError("db down")):
            self.assertIsNone(vi.callback_replay(1, "01711000000"))


if __name__ == "__main__":
    unittest.main()
