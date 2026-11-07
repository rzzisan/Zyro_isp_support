import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location(
    "kb_check", pathlib.Path(__file__).resolve().parents[2] / "docs" / "kb" / "kb_check.py")
kb_check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(kb_check)


class KbCheckTest(unittest.TestCase):
    cmap = {"engine/bills.py": ["docs/kb/billing/collect.md"], "engine/sms_gateways/": ["docs/kb/sms_whatsapp/sms.md"]}

    def test_code_changed_without_its_kb_is_an_error(self):
        errors, _ = kb_check.check(["engine/bills.py"], self.cmap)
        self.assertEqual(1, len(errors))

    def test_code_changed_with_its_kb_passes(self):
        errors, warnings = kb_check.check(["engine/bills.py", "docs/kb/billing/collect.md"], self.cmap)
        self.assertEqual(([], []), (errors, warnings))

    def test_folder_prefix_matches(self):
        errors, _ = kb_check.check(["engine/sms_gateways/khudebarta.py"], self.cmap)
        self.assertEqual(1, len(errors))

    def test_code_without_kb_only_warns(self):
        errors, warnings = kb_check.check(["engine/walkie.py"], self.cmap)
        self.assertEqual([], errors)
        self.assertEqual(1, len(warnings))

    def test_tests_and_docs_are_ignored(self):
        self.assertEqual(([], []), kb_check.check(["engine/tests/test_x.py", "README.md"], self.cmap))


if __name__ == "__main__":
    unittest.main()
