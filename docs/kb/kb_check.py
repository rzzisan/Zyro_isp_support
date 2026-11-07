"""KB check for a PR: a changed code file that a KB file lists under `code:` needs that KB file changed in the
same PR (docs/kb/README.md). Code with no KB file yet only gets a warning, until its `missing` row is written.

Usage: python3 docs/kb/kb_check.py <base-ref>   (e.g. origin/main). Skip for a pure refactor: put [kb-skip] in a
commit message of the PR, with the reason.
"""
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
KB = ROOT / "docs" / "kb"
CODE_DIRS = ("engine/", "panel/app/", "panel/resources/", "panel/routes/", "panel/database/migrations/", "android/app/src/")


def kb_code_map() -> dict[str, list[str]]:
    """code path (file or folder prefix) -> KB files that list it."""
    out: dict[str, list[str]] = {}
    for f in KB.rglob("*.md"):
        if f.name in ("README.md", "index.md"):
            continue
        text = f.read_text(encoding="utf-8")
        head = text.split("---", 2)[1] if text.startswith("---") else ""
        m = re.search(r"^code:\s*\[(.*?)\]", head, re.M)
        items = [x.strip().strip("'\"") for x in m.group(1).split(",")] if m else []
        if not m:   # block list form: code:\n  - path
            block = re.search(r"^code:\s*\n((?:\s+-\s+.+\n?)+)", head, re.M)
            items = re.findall(r"-\s+(\S+)", block.group(1)) if block else []
        for p in filter(None, items):
            out.setdefault(p, []).append(str(f.relative_to(ROOT)))
    return out


def owners(path: str, cmap: dict[str, list[str]]) -> set[str]:
    return {kb for p, kbs in cmap.items() if path == p or (p.endswith("/") and path.startswith(p)) for kb in kbs}


def check(changed: list[str], cmap: dict[str, list[str]]) -> tuple[list[str], list[str]]:
    """(errors, warnings)"""
    changed_set = set(changed)
    errors, warnings = [], []
    for path in changed:
        if not path.startswith(CODE_DIRS) or "/tests/" in path or path.startswith("panel/tests/"):
            continue
        kbs = owners(path, cmap)
        if not kbs:
            warnings.append(f"{path}: কোনো KB ফাইলের `code:`-এ নেই (index.md-এর missing সারি লিখে যোগ করুন)")
        elif not kbs & changed_set:
            errors.append(f"{path} বদলেছে, কিন্তু এর KB হালনাগাদ হয়নি: {', '.join(sorted(kbs))}")
    return errors, warnings


def main() -> int:
    base = sys.argv[1] if len(sys.argv) > 1 else "origin/main"
    run = lambda *a: subprocess.run(["git", *a], cwd=ROOT, capture_output=True, text=True, check=True).stdout
    if "[kb-skip]" in run("log", "--format=%B", f"{base}..HEAD"):
        print("[kb-skip] পাওয়া গেছে: KB চেক বাদ।")
        return 0
    changed = [l for l in run("diff", "--name-only", f"{base}...HEAD").splitlines() if l]
    errors, warnings = check(changed, kb_code_map())
    for w in warnings:
        print(f"::warning::{w}")
    for e in errors:
        print(f"::error::{e}")
    if errors:
        print("ফিচারের লজিক বা ধাপ বদলালে সেই KB ফাইল একই PR-এ হালনাগাদ করুন (docs/kb/README.md)।")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
