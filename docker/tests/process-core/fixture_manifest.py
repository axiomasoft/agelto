"""Validate versioned fixture bytes and their sanitized provenance before rig commands."""
import datetime
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import sys


BASELINE_MANIFEST = "tests/Fixtures/ProcessCore/manifest.json"
RUNTIME_MANIFEST = "tests/Fixtures/ProcessCore/runtime-inputs.json"


def fixture_path(root, relative, fixture_id):
    path = PurePosixPath(relative)
    if not relative or path.is_absolute() or ".." in path.parts or "\\" in relative:
        raise ValueError(f"fixture_manifest: unsafe path: {fixture_id}")
    fixture_roots = ("tests/Fixtures/", "docker/tests/process-core/fixtures/", "estimates/gold/")
    catalog_paths = {BASELINE_MANIFEST, RUNTIME_MANIFEST}
    if relative in catalog_paths or path.suffix == ".php" or not (
        relative.startswith(fixture_roots) or relative == "tests/Support/ProcessCore/contracts-v1.json"
    ):
        raise ValueError(f"fixture_manifest: not a contract fixture: {fixture_id}")
    target = root
    for part in path.parts:
        target = target / part
        if target.is_symlink():
            raise ValueError(f"fixture_manifest: symlink: {fixture_id}")
    if not target.is_file() or not target.resolve().is_relative_to(root):
        raise ValueError(f"fixture_manifest: missing/outside file: {fixture_id}")
    return target


def verify(root, *, sync=False):
    root = Path(root).resolve()
    manifest_path = root / BASELINE_MANIFEST
    manifest_bytes = manifest_path.read_bytes()
    manifest = json.loads(manifest_bytes)
    if not isinstance(manifest, dict):
        raise ValueError("fixture_manifest: expected object")
    for section in ("files", "migrations", "source_classes", "contract_references",
                    "retired_files", "retired_migrations", "retired_source_classes"):
        if any("sha256" in entry for entry in manifest.get(section, [])):
            raise ValueError(f"fixture_manifest: mutable pins in {section}")
    entries = manifest.get("contract_fixtures")
    if not isinstance(entries, list) or not entries:
        raise ValueError("fixture_manifest: empty contract_fixtures")
    seen = set()
    verified = []
    for entry in entries:
        if not isinstance(entry, dict):
            raise ValueError("fixture_manifest: expected fixture object")
        fixture_id = entry.get("id", "")
        if not re.fullmatch(r"[a-z][a-z0-9-]+", fixture_id) or fixture_id in seen:
            raise ValueError("fixture_manifest: invalid/duplicate id")
        seen.add(fixture_id)
        relative = entry.get("path", "")
        target = fixture_path(root, relative, fixture_id)
        actual_hash = hashlib.sha256(target.read_bytes()).hexdigest()
        if not sync and entry.get("sha256") != actual_hash:
            raise ValueError(f"fixture_manifest: hash mismatch: {fixture_id}")
        entry["sha256"] = actual_hash
        for key in ("kind", "provenance", "coverage"):
            if not isinstance(entry.get(key), str) or not entry[key].strip():
                raise ValueError(f"fixture_manifest: missing {key}: {fixture_id}")
        if "platform" not in entry or "adapter_version" not in entry:
            raise ValueError(f"fixture_manifest: missing adapter identity: {fixture_id}")
        for key in ("platform", "adapter_version"):
            if entry[key] is not None and (not isinstance(entry[key], str) or not entry[key].strip()):
                raise ValueError(f"fixture_manifest: invalid {key}: {fixture_id}")
        date = entry.get("captured_at", "")
        if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", date):
            raise ValueError(f"fixture_manifest: invalid date: {fixture_id}")
        datetime.date.fromisoformat(date)
        expected = entry.get("expected", {})
        if not isinstance(expected, dict):
            raise ValueError(f"fixture_manifest: invalid expected schema: {fixture_id}")
        version = expected.get("schema_version")
        assessment = expected.get("canonical_dto") or expected.get("assessment")
        if type(version) is not int or version < 1 or not isinstance(assessment, str) or not assessment.strip():
            raise ValueError(f"fixture_manifest: missing expected schema: {fixture_id}")
        scenarios = entry.get("scenario_ids")
        if not isinstance(scenarios, list) or not scenarios or any(not isinstance(s, str) or not re.fullmatch(r"T(?:0[1-9]|[1-3][0-9]|4[0-8])", s) for s in scenarios):
            raise ValueError(f"fixture_manifest: invalid scenarios: {fixture_id}")
        verified.append({"id": fixture_id, "path": relative, "sha256": actual_hash, "scenario_ids": scenarios, "coverage": entry["coverage"]})
    if sync:
        updated = (json.dumps(manifest, ensure_ascii=False, indent=4) + "\n").encode()
        if updated != manifest_bytes:
            manifest_path.write_bytes(updated)
            manifest_bytes = updated
    return {"manifest_sha256": hashlib.sha256(manifest_bytes).hexdigest(), "fixtures": verified}


def sync_gold(root):
    runtime_path = root / RUNTIME_MANIFEST
    updated = json.loads(runtime_path.read_bytes())
    gold = [entry for entry in updated["files"] if entry["source"].startswith("estimates/gold/")]
    changed = False
    for entry in gold:
        target = fixture_path(root, entry["source"], entry["source"])
        actual_hash = hashlib.sha256(target.read_bytes()).hexdigest()
        if entry.get("sha256") != actual_hash:
            entry["sha256"] = actual_hash
            changed = True
    if changed:
        runtime_path.write_text(json.dumps(updated, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return len(gold)


if __name__ == "__main__":
    try:
        sync = sys.argv[2:] == ["--sync"]
        proof = verify(sys.argv[1], sync=sync)
        if sync:
            proof = {"synced_contract_fixtures": len(proof["fixtures"]), "synced_gold_fixtures": sync_gold(Path(sys.argv[1]).resolve())}
        print(json.dumps(proof))
    except (ValueError, KeyError, TypeError, OSError) as error:
        print(json.dumps({"error": "fixture_manifest_invalid", "detail": str(error)}))
        sys.exit(1)
