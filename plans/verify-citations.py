#!/usr/bin/env python3
"""Verify ``path:line``-style citations in live plan Markdown using only stdlib."""

from __future__ import annotations

import argparse
import json
import re
import sys
import tempfile
import unittest
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


LINE_SPEC = re.compile(r"^(?P<path>.+?):(?P<lines>\d+(?:-\d+|,\d+)*)$")
SYMBOL_SPEC = re.compile(r"^(?P<path>.+?)::(?P<symbol>[A-Za-z_][A-Za-z0-9_]*)$")
JSON_SPEC = re.compile(r"^(?P<path>.+?\.json)#(?P<key>[A-Za-z0-9_.-]+)$")
BACKTICKS = re.compile(r"`([^`]+)`")


@dataclass(frozen=True)
class Finding:
    source: Path
    source_line: int
    citation: str
    reason: str


def parse_exceptions(path: Path) -> dict[str, str]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise ValueError(f"cannot read exceptions {path}: {error}") from error
    if not isinstance(data, dict) or set(data) != {"exceptions"} or not isinstance(data["exceptions"], list):
        raise ValueError("exceptions must be an object with exactly an exceptions list")
    exceptions: dict[str, str] = {}
    for entry in data["exceptions"]:
        if not isinstance(entry, dict) or set(entry) != {"citation", "reason"}:
            raise ValueError("each exception must contain exactly citation and reason")
        citation, reason = entry["citation"], entry["reason"]
        if not isinstance(citation, str) or not citation or not isinstance(reason, str) or not reason.strip():
            raise ValueError("exception citation and non-empty reason must be strings")
        if citation in exceptions:
            raise ValueError(f"duplicate exception: {citation}")
        exceptions[citation] = reason
    return exceptions


def resolve(path_text: str, repo_root: Path, plan_root: Path) -> Path | None:
    candidate = Path(path_text)
    if candidate.is_absolute() or ".." in candidate.parts:
        return None
    for base in (repo_root, plan_root):
        resolved = base / candidate
        if resolved.is_file():
            return resolved
    return None


def check_citation(citation: str, repo_root: Path, plan_root: Path) -> str | None:
    match = LINE_SPEC.fullmatch(citation)
    if match:
        if "/" not in match["path"]:
            return None
        target = resolve(match["path"], repo_root, plan_root)
        if target is None:
            return "target file does not exist"
        line_count = len(target.read_text(encoding="utf-8").splitlines())
        requested: list[int] = []
        for part in match["lines"].split(","):
            if "-" in part:
                start, end = map(int, part.split("-", 1))
                if start > end:
                    return "line range is reversed"
                requested.extend(range(start, end + 1))
            else:
                requested.append(int(part))
        if any(line < 1 or line > line_count for line in requested):
            return f"line outside 1-{line_count}"
        return None
    match = SYMBOL_SPEC.fullmatch(citation)
    if match:
        if "/" not in match["path"]:
            return None
        target = resolve(match["path"], repo_root, plan_root)
        if target is None:
            return "target file does not exist"
        if not re.search(rf"\b{re.escape(match['symbol'])}\b", target.read_text(encoding="utf-8")):
            return "symbol text does not exist"
        return None
    match = JSON_SPEC.fullmatch(citation)
    if match:
        if "/" not in match["path"]:
            return None
        target = resolve(match["path"], repo_root, plan_root)
        if target is None:
            return "target file does not exist"
        try:
            value = json.loads(target.read_text(encoding="utf-8"))
        except json.JSONDecodeError as error:
            return f"invalid JSON: {error.msg}"
        for key in match["key"].split("."):
            if not isinstance(value, dict) or key not in value:
                return f"JSON key does not exist: {match['key']}"
            value = value[key]
        return None
    return None


def markdown_files(plan_root: Path, include_archive: bool) -> Iterable[Path]:
    allowed = ("plan.md", "handoff.md", "roadmap.md")
    for name in allowed:
        path = plan_root / name
        if path.is_file():
            yield path
    for directory in ("phases", "findings", "research", "brief"):
        root = plan_root / directory
        if root.is_dir():
            yield from sorted(root.rglob("*.md"))
    if include_archive:
        archive = plan_root / "archive"
        if archive.is_dir():
            yield from sorted(archive.rglob("*.md"))


def scan(plan_root: Path, repo_root: Path, exceptions: dict[str, str], include_archive: bool) -> list[Finding]:
    findings: list[Finding] = []
    for source in markdown_files(plan_root, include_archive):
        try:
            text = source.read_text(encoding="utf-8")
        except OSError as error:
            findings.append(Finding(source, 0, "", f"cannot read source: {error}"))
            continue
        for number, line in enumerate(text.splitlines(), 1):
            for citation in BACKTICKS.findall(line):
                reason = check_citation(citation, repo_root, plan_root)
                if reason and citation not in exceptions:
                    findings.append(Finding(source, number, citation, reason))
    return findings


def discover_plans(repo_root: Path, target: str | None) -> list[Path]:
    plans_root = repo_root / "plans"
    if target is None:
        return sorted(path for path in plans_root.iterdir() if (path / "plan.md").is_file())
    path = Path(target)
    candidates = (path, repo_root / path, plans_root / target)
    for candidate in candidates:
        if (candidate / "plan.md").is_file():
            return [candidate.resolve()]
    raise ValueError(f"plan not found: {target}")


class CitationVerifierSelfTest(unittest.TestCase):
    def test_all_supported_forms_and_two_base_precedence(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            repo = Path(temporary)
            plan = repo / "plans" / "demo"
            plan.mkdir(parents=True)
            (repo / "docs").mkdir()
            (plan / "local").mkdir()
            (repo / "docs" / "shared.md").write_text("one\ntwo\nthree\n", encoding="utf-8")
            (plan / "local" / "local.md").write_text("one\ntwo\nthree\n", encoding="utf-8")
            (plan / "local" / "code.py").write_text("def Symbol(): pass\n", encoding="utf-8")
            (plan / "local" / "data.json").write_text('{"outer": {"inner": true}}', encoding="utf-8")
            for citation in ("docs/shared.md:1", "local/local.md:1-3", "local/local.md:1,3", "local/code.py::Symbol", "local/data.json#outer.inner"):
                self.assertIsNone(check_citation(citation, repo, plan))
            self.assertEqual(check_citation("local/local.md:4", repo, plan), "line outside 1-3")
            self.assertEqual(check_citation("local/missing.md:1", repo, plan), "target file does not exist")

    def test_strict_and_exception_boundaries(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            repo = Path(temporary)
            plan = repo / "plans" / "demo"
            (plan / "phases").mkdir(parents=True)
            (plan / "plan.md").write_text("`missing/path.md:1`\n", encoding="utf-8")
            findings = scan(plan, repo, {}, False)
            self.assertEqual(len(findings), 1)
            self.assertEqual(scan(plan, repo, {"missing/path.md:1": "legacy"}, False), [])

    def test_archive_is_opt_in(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            repo = Path(temporary)
            plan = repo / "plans" / "demo"
            (plan / "archive").mkdir(parents=True)
            (plan / "archive" / "old.md").write_text("`missing/path.md:1`\n", encoding="utf-8")
            self.assertEqual(scan(plan, repo, {}, False), [])
            self.assertEqual(len(scan(plan, repo, {}, True)), 1)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("plan", nargs="?", help="plan directory or plan ID; defaults to every live plan")
    parser.add_argument("--strict", action="store_true", help="return 1 when unresolved citations exist")
    parser.add_argument("--include-archive", action="store_true", help="scan archive Markdown too")
    parser.add_argument("--selftest", action="store_true", help="run embedded regression tests")
    args = parser.parse_args(argv)
    if args.selftest:
        return 0 if unittest.main(argv=[sys.argv[0]], exit=False).result.wasSuccessful() else 1
    repo_root = Path(__file__).resolve().parent.parent
    try:
        exceptions = parse_exceptions(Path(__file__).with_name("verify-citations-known-exceptions.json"))
        plan_roots = discover_plans(repo_root, args.plan)
    except ValueError as error:
        print(f"error: {error}", file=sys.stderr)
        return 2
    findings = [finding for plan_root in plan_roots for finding in scan(plan_root, repo_root, exceptions, args.include_archive)]
    for finding in findings:
        relative = finding.source.relative_to(repo_root)
        print(f"{relative}:{finding.source_line}: unresolved `{finding.citation}` — {finding.reason}")
    print(f"citation verification: {len(findings)} unresolved across {len(plan_roots)} plan(s)")
    return 1 if args.strict and findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
