"""engine/bkash_webhook.py without a database: real Amazon-SNS-style signatures made with a throwaway certificate.
  PYTHONPATH=. .venv/bin/python -m unittest engine.tests.test_bkash_webhook
"""
import base64
import datetime as dt
import json
import unittest
from unittest import mock

from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.x509.oid import NameOID

from engine import bkash_webhook as bw
from engine.online_payment_sync import code_of

CERT_URL = "https://sns.ap-southeast-1.amazonaws.com/SimpleNotificationService-abc.pem"
TOPIC = "arn:aws:sns:ap-southeast-1:354285753755:bpt_01700000000"


def _keypair():
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "sns.amazonaws.com")])
    now = dt.datetime.now(dt.timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
            .serial_number(1).not_valid_before(now).not_valid_after(now + dt.timedelta(days=1)).sign(key, hashes.SHA256()))
    return key, cert


KEY, CERT = _keypair()
OTHER_KEY, _ = _keypair()


def signed(m: dict, version: str = "1", key=KEY) -> dict:
    m = {**m, "SignatureVersion": version, "SigningCertURL": CERT_URL}
    algo = hashes.SHA1() if version == "1" else hashes.SHA256()
    m["Signature"] = base64.b64encode(key.sign(bw.string_to_sign(m).encode(), padding.PKCS1v15(), algo)).decode()
    return m


def notification(payment: dict | None = None, topic: str = TOPIC, **kw) -> dict:
    payment = payment or {"dateTime": "20261104122246", "debitMSISDN": "8801711000111", "creditShortCode": "01777858289",
                          "trxID": "BK4J420ANO", "transactionStatus": "Completed", "transactionType": "10002294",
                          "amount": "525", "currency": "BDT", "transactionReference": "42"}
    return signed({"Type": "Notification", "MessageId": "m-1", "TopicArn": topic, "Message": json.dumps(payment),
                   "Timestamp": "2026-11-04T06:22:46.236Z", **kw})


class SignatureTest(unittest.TestCase):
    def setUp(self):
        bw._certs[CERT_URL] = (9e18, CERT)  # never fetched in tests

    def test_good_signatures_pass(self):
        bw.verify(notification())
        bw.verify(signed({"Type": "SubscriptionConfirmation", "MessageId": "s-1", "Token": "t", "TopicArn": TOPIC,
                          "Message": "You have chosen to subscribe", "SubscribeURL": "https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription",
                          "Timestamp": "2026-11-04T06:00:00Z"}, version="2"))

    def test_changed_amount_fails(self):
        m = notification()
        m["Message"] = m["Message"].replace('"525"', '"5250"')
        with self.assertRaises(bw.Rejected):
            bw.verify(m)

    def test_other_key_fails(self):
        with self.assertRaises(bw.Rejected):
            bw.verify(signed({"Type": "Notification", "MessageId": "x", "TopicArn": TOPIC, "Message": "{}",
                              "Timestamp": "t"}, key=OTHER_KEY))

    def test_cert_must_be_amazon_sns(self):
        for url in ("https://evil.example.com/x.pem", "http://sns.ap-southeast-1.amazonaws.com/x.pem",
                    "https://sns.ap-southeast-1.amazonaws.com.evil.com/x.pem", "https://sns.ap-southeast-1.amazonaws.com/x.txt"):
            with self.assertRaises(bw.Rejected, msg=url):
                bw.cert(url)

    def test_object_message_cannot_be_checked(self):
        m = notification()
        m["Message"] = json.loads(m["Message"])
        with self.assertRaises(bw.Rejected):
            bw.verify(m)


class HelpersTest(unittest.TestCase):
    def test_customer_code_keeps_leading_zeros(self):
        self.assertEqual(code_of("0042"), "0042")
        self.assertEqual(code_of(" 42 "), "0042")
        self.assertEqual(code_of("0904"), "0904")
        self.assertEqual(code_of("12345"), "12345")
        self.assertIsNone(code_of("bill"))
        self.assertIsNone(code_of(None))

    def test_payload_and_time(self):
        self.assertEqual(bw.payload({"Message": '{"trxID": "A"}'})["trxID"], "A")
        self.assertEqual(bw.payload({"Message": {"trxID": "A"}})["trxID"], "A")
        self.assertEqual(bw.paid_at("20261104122246"), dt.datetime(2026, 11, 4, 6, 22, 46))  # Dhaka +6

    def test_wallets_and_mobiles(self):
        self.assertTrue(bw.same_wallet("01777-858289", "8801777858289"))
        self.assertFalse(bw.same_wallet("01777858289", "01777858280"))
        self.assertEqual(bw.mobile_of("8801711000111"), "8801711000111")
        self.assertEqual(bw.mobile_of("01711000111"), "8801711000111")
        self.assertIsNone(bw.mobile_of("12345"))


class HandleTest(unittest.TestCase):
    def setUp(self):
        bw._certs[CERT_URL] = (9e18, CERT)
        self.kept = []
        self.s = {"company_id": 1, "enabled": True, "topic_arn": TOPIC, "merchant_number": "01777858289"}
        patches = [mock.patch.object(bw, "company_by_slug", return_value={"id": 1, "slug": "cl"}),
                   mock.patch.object(bw, "settings", side_effect=lambda cid: self.s),
                   mock.patch.object(bw, "_keep", side_effect=lambda cid, m, v, r, e=None, p=None: self.kept.append((v, r, e))),
                   mock.patch.object(bw.db, "execute"),
                   mock.patch.object(bw, "_alert_admins"),
                   mock.patch.object(bw.threading, "Thread")]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)

    def post(self, m: dict, header: str | None = "Notification"):
        return bw.handle("cl", header, json.dumps(m).encode())

    def test_notification_of_our_topic_is_recorded(self):
        with mock.patch.object(bw, "record", return_value=(7, "recorded", None)) as rec:
            self.assertEqual(self.post(notification()), (200, "ok"))
        rec.assert_called_once()
        self.assertEqual(self.kept, [(True, "recorded", None)])

    def test_forged_message_is_rejected(self):
        m = notification()
        m["Message"] = m["Message"].replace("BK4J420ANO", "FAKE000001")
        with mock.patch.object(bw, "record") as rec:
            self.assertEqual(self.post(m)[0], 403)
        rec.assert_not_called()
        self.assertEqual(self.kept[0][:2], (False, "rejected"))

    def test_other_topic_is_rejected(self):
        with mock.patch.object(bw, "record") as rec:
            self.assertEqual(self.post(notification(topic="arn:aws:sns:us-east-1:1:someone-else"))[0], 403)
        rec.assert_not_called()

    def test_switched_off_keeps_but_does_not_record(self):
        self.s["enabled"] = False
        with mock.patch.object(bw, "record") as rec:
            self.assertEqual(self.post(notification()), (200, "ok"))
        rec.assert_not_called()
        self.assertEqual(self.kept[0][1], "ignored")

    def test_first_subscription_is_confirmed(self):
        self.s["topic_arn"] = None
        sub = signed({"Type": "SubscriptionConfirmation", "MessageId": "s-1", "Token": "t", "TopicArn": TOPIC,
                      "Message": "subscribe", "SubscribeURL": "https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription&Token=t",
                      "Timestamp": "2026-11-04T06:00:00Z"})
        with mock.patch.object(bw, "confirm_url") as confirm, mock.patch.object(bw, "_set_confirmed") as done:
            self.assertEqual(self.post(sub, "SubscriptionConfirmation"), (200, "ok"))
        confirm.assert_called_once()
        done.assert_called_once_with(1, TOPIC)

    def test_second_topic_waits_for_owner(self):
        sub = signed({"Type": "SubscriptionConfirmation", "MessageId": "s-2", "Token": "t", "TopicArn": "arn:aws:sns:x:1:new",
                      "Message": "subscribe", "SubscribeURL": "https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription",
                      "Timestamp": "2026-11-04T06:00:00Z"})
        with mock.patch.object(bw, "confirm_url") as confirm, mock.patch.object(bw, "_set_pending") as pending:
            self.assertEqual(self.post(sub, "SubscriptionConfirmation"), (200, "ok"))
        confirm.assert_not_called()
        pending.assert_called_once()

    def test_not_json(self):
        self.assertEqual(bw.handle("cl", None, b"hello"), (400, "not json"))


class RecordTest(unittest.TestCase):
    """record(): which notifications become a payment, without touching the database."""

    def setUp(self):
        self.s = {"merchant_number": "01777858289", "settle_in_billing": True}

    def test_failed_or_other_wallet_is_not_recorded(self):
        p = json.loads(notification()["Message"])
        with mock.patch.object(bw.db, "one", return_value=None), mock.patch.object(bw.db, "execute") as ins:
            self.assertEqual(bw.record(1, self.s, {"Message": json.dumps({**p, "transactionStatus": "Failed"})})[1], "ignored")
            self.assertEqual(bw.record(1, self.s, {"Message": json.dumps({**p, "creditShortCode": "01999999999"})})[1], "rejected")
        ins.assert_not_called()

    def test_same_trx_twice_is_a_duplicate(self):
        with mock.patch.object(bw.db, "one", return_value={"id": 5}), mock.patch.object(bw.db, "execute") as ins:
            self.assertEqual(bw.record(1, self.s, notification()), (5, "duplicate", None))
        ins.assert_not_called()

    def test_matched_payment_waits_for_settling(self):
        with mock.patch.object(bw.db, "one", return_value=None), \
                mock.patch.object(bw, "find_customer", return_value=({"header_id": 6798, "customer_id": "0042"}, "code")), \
                mock.patch.object(bw.db, "execute", return_value={"id": 9}) as ins:
            self.assertEqual(bw.record(1, self.s, notification()), (9, "recorded", None))
        args = ins.call_args.args[1]
        self.assertIn("BK4J420ANO", args)
        self.assertIn(525.0, args)
        self.assertIn("0042", args)
        self.assertEqual(args[-1], "pending")


if __name__ == "__main__":
    unittest.main()
