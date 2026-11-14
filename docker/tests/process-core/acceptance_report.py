"""Candidate-bound acceptance manifest. Reads evidence; never starts tests or paid I/O."""
import ast
import hashlib
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET


CONTRACT = "tests/Fixtures/ProcessCore/acceptance-report-v1.json"
REPORT_ONLY = {CONTRACT, "docker/tests/process-core/acceptance_report.py",
               "docker/tests/process-core/test_acceptance_report.py",
               "docker/tests/process-core/test_rig.py"}
REPORT_DOCS = {"docs/dev/process-core-acceptance.md", "docs/dev/runbooks/process-recovery.md",
               "docs/dev/runbooks/process-test-rig.md", "docs/dev/runbooks/process-quality-limits.md"}
GATES = {"V1": ("core-test-install", None), "V2": ("core-test-e2e", None),
         "V3": ("core-test-faults", None), "V4": ("core-test-load", 10),
         "V5": ("core-test-load", 20), "V6": ("core-test-regression", None),
         "V7": ("core-quality", None)}


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def need(condition, message):
    if not condition:
        raise ValueError(message)


def read(path):
    need(path.is_file() and not path.is_symlink(), "missing/unsafe evidence: " + str(path))
    return json.loads(path.read_text())


def rig_signature(source, install=False):
    tree = ast.parse(source)
    if install:
        # Only installation/guard functions and fixed identities; suite dispatch is separate.
        excluded = {"collect", "suite", "execute", "main", "load_width", "validate_load_report"}
        tree.body = [node for node in tree.body if
                     isinstance(node, ast.FunctionDef) and node.name not in excluded or
                     isinstance(node, ast.Assign) and not any(isinstance(t, ast.Name) and
                     t.id in {"SUITE_PATHS", "IMPLEMENTED", "FUTURE"} for t in node.targets)]
    else:
        tree.body = [node for node in tree.body if not
                     (isinstance(node, ast.FunctionDef) and node.name == "collect")]
    return hashlib.sha256(ast.dump(tree, include_attributes=False).encode()).hexdigest()


def changed_inputs(root, previous, current, contract, install=False):
    changed = [p for p in sorted(set(previous) | set(current)) if previous.get(p) != current.get(p)]
    stale = []
    for path in changed:
        if path in REPORT_DOCS or path in REPORT_ONLY:
            continue
        if path == "tests/Fixtures/ProcessCore/manifest.json" and contract.get("fixture_reuse"):
            anchor = contract["fixture_reuse"]
            fixture = read(root / path)
            entries = fixture["contract_fixtures"]
            added = [entry for entry in entries if entry["id"] == "p319-acceptance-report"]
            fixture["contract_fixtures"] = [entry for entry in entries if entry not in added]
            if (previous.get(path) == anchor["sha256"] and len(added) == 1 and
                    added[0]["path"] == CONTRACT and added[0]["sha256"] == sha(root / CONTRACT) and
                    digest(fixture) == anchor["without_report_contract_sha256"]):
                continue
        if path == "docker/tests/process-core/rig.py":
            anchor = contract["rig_reuse"]["install" if install else "runtime"]
            if (previous.get(path) == anchor["sha256"] and
                    rig_signature((root / path).read_text(), install) == anchor["ast_sha256"] and
                    (not install or rig_signature((root / path).read_text()) ==
                     contract["rig_reuse"]["runtime"]["ast_sha256"])):
                continue
        if install and (path.startswith(("tests/Feature/", "tests/Integration/")) or
                        path in contract["install_independent_test_inputs"]):
            continue
        stale.append(path)
    need(not stale, "stale scenario inputs: " + ", ".join(stale[:12]))
    return changed


def cases(folder, result):
    executed = read(folder / "executed-scenarios.json")
    tree = ET.parse(folder / "junit.xml")
    actual = [{"class": c.get("classname"), "name": c.get("name"), "file": c.get("file"),
               "status": "failed" if c.find("failure") is not None or c.find("error") is not None
               else "skipped" if c.find("skipped") is not None else "passed"}
              for c in tree.iter("testcase")]
    need(executed == actual and actual and all(c["status"] == "passed" for c in actual),
         "missing/failed/skipped scenario evidence")
    need(sum(int(c.get("assertions", "0")) for c in tree.iter("testcase")) > 0,
         "no actual assertions")
    need(result.get("tests", {}).get("executed") == len(actual), "JUnit count mismatch")
    return actual


def snapshot(folder):
    schema = (folder / "schema.sql").read_text().removesuffix("\n")
    return {"schema": hashlib.sha256(schema.encode()).hexdigest(),
            "catalog": digest(read(folder / "normalized-catalog.json")),
            "seeds": digest(read(folder / "seeds.json"))}


def file_cases(executed, path):
    # Pest includes ::case in file; do not accept substring matches to another file.
    class_name = path.removesuffix(".php").replace("/", ".").lower()
    return [case for case in executed if (case.get("file") or "").split("::", 1)[0] == path or
            (case.get("class") or "").lower() == class_name]


def case_inventory(cases):
    names = sorted(case["name"] for case in cases)
    return {"count": len(names), "names_sha256": digest(names)}


def replay_binding(root, contract, binding):
    original = binding["replaces"]
    replacement = contract["replay_replacements"][original]
    need(binding["file"] in replacement["files"], "undeclared replay replacement")
    archive = root / replacement["archive"]
    need(sha(archive) == replacement["source_sha256"], "modified historical scenario source")
    need(binding["group"] in replacement["groups"] and
         re.search(r"->group\([^;]*['\"]" + re.escape(binding["group"]) + r"['\"]", archive.read_text()),
         "missing historical scenario group")
    return replacement


def build(root, reports, folder, validate_load, candidate):
    root, reports, folder = Path(root), Path(reports), Path(folder)
    contract = read(root / CONTRACT)
    need(set(contract["gate_cases"]) == {"V2", "V3", "V4", "V5"} and
         all(contract["gate_cases"].values()), "incomplete named gate case inventory")
    current = read(folder / "inputs.json")
    errors, gates, scenario_evidence = [], {}, {}
    latest = {}
    runtime = None
    for path in sorted(reports.glob("*/result.json")):
        if path.parent.name > folder.name:
            continue
        result = read(path)
        latest[(result.get("command"), result.get("width"))] = (path.parent, result)
    for validation, key in GATES.items():
        try:
            need(key in latest, "missing evidence: " + str(key))
            location, result = latest[key]
            need(type(result.get("exit")) is int and result["exit"] == 0, "latest result is red: " + str(key))
            previous = read(location / "inputs.json")
            need(digest(previous) == result["candidate"]["input_tree_sha256"], "evidence input hash mismatch")
            changed = changed_inputs(root, previous, current, contract, validation == "V1")
            if contract.get("installed_proxyma"):
                measured_runtime = result["runtime"]
                need(measured_runtime.get("images") and measured_runtime.get("config_sha256") and
                     measured_runtime.get("dependencies") == read(root /
                     "tests/Fixtures/ProcessCore/runtime-inputs.json")["repositories"],
                     "missing/stale installed runtime")
                if runtime is None:
                    runtime = measured_runtime
                need(runtime == measured_runtime, "mixed acceptance runtimes")
            proof = {"command": key[0], "width": key[1], "result": str((location / "result.json").relative_to(root)),
                     "original_candidate": result["candidate"], "changed_non_influencing_inputs": changed,
                     "reuse": "identical_inputs" if not changed else "identical_execution_inputs",
                     "exit": 0}
            if validation == "V1":
                comparison = read(location / "install-comparison.json")
                measured = snapshot(location / "install-1")
                need(measured == snapshot(location / "install-2") == comparison["first"] ==
                     comparison["second"] == result["manifests"], "clean install hash mismatch")
                proof["schema_seed_catalog"] = measured
            elif validation == "V7":
                need(result.get("quality") and all(type(v) is int and v == 0 for v in result["quality"].values()),
                     "missing quality subchecks")
                need(set(result["quality"]) == {"pint", "phpstan", "architecture", "schema-docs", "schema-tests"},
                     "incomplete quality subchecks")
                proof["quality"] = result["quality"]
            else:
                executed = cases(location, result)
                for path, expected in contract["gate_cases"].get(validation, {}).items():
                    observed = file_cases(executed, path)
                    need(case_inventory(observed) == expected,
                         "missing/unexpected gate scenarios: " + path)
                scenario_evidence[validation] = executed
                proof["executed_cases"] = len(executed)
                if validation in {"V4", "V5"}:
                    load = read(location / "load.json")
                    proof["load"] = validate_load(load, key[1])
                    proof["measurements"] = {k: load[k] for k in
                                             ("resources", "attempt_seconds", "cohort_wait_seconds",
                                              "throughput_documents_per_second", "history_rows",
                                              "requests", "server_receipts", "active_reservations_after_drain",
                                              "pending_outbox_after_drain", "unaccounted_work", "fifo_grant_inversions")}
                if validation == "V6":
                    for path, expected in contract.get("integration_cases", {}).items():
                        need(case_inventory(file_cases(executed, path)) == expected,
                             "missing installed integration scenarios: " + path)
                    comparison = read(location / "scenario-comparison.json")
                    need(sha(location / "scenario-comparison.json") ==
                         result["tests"].get("scenario_comparison_sha256"), "regression comparison hash mismatch")
                    need(not any(comparison[k] for k in ("missing", "nonpassing", "duplicates")) and
                         comparison["preserved_cases"] > 0, "missing original scenarios")
                    proof["original_preserved"] = comparison["preserved_cases"]
            proof["artifacts"] = {str(p.relative_to(root)): sha(p) for p in sorted(location.rglob("*"))
                                  if p.is_file() and not p.is_symlink()}
            gates[validation] = proof
        except (ValueError, KeyError, TypeError, OSError, ET.ParseError) as error:
            errors.append({"id": validation, "error": str(error)})

    trace = {}
    need(set(contract["scenarios"]) == {f"T{n:02}" for n in range(1, 49)}, "incomplete T01-T48 contract")
    need(set(contract["scenario_group_names"]) == set(contract["scenarios"]), "incomplete scenario group mapping")
    for scenario, bindings in contract["scenarios"].items():
        trace[scenario] = []
        for binding in bindings:
            try:
                path, group, target = binding["file"], binding["group"], binding["target"]
                need(target == "core-test-regression", "scenario evidence target mismatch")
                need(group == contract["scenario_group_names"][scenario], "scenario group mapping mismatch")
                if binding.get("group_mode") == "contract_replay":
                    replay_binding(root, contract, binding)
                    grouped = True
                else:
                    source = (root / path).read_text()
                    grouped = re.search(r"->group\([^;]*['\"]" + re.escape(group) + r"['\"]", source)
                    if binding.get("group_mode") == "case_prefix":
                        grouped = re.search(r"test\(['\"]" + scenario + r" ", source)
                need(grouped,
                     "missing declared group: " + path + ":" + group)
                observed = file_cases(scenario_evidence.get("V6", []), path)
                need(observed, "missing executed scenario file: " + path)
                need(case_inventory(observed) == contract["test_cases"][path],
                     "missing/unexpected scenario cases: " + path)
                trace[scenario].append({**binding, "sha256": sha(root / path),
                                        "evidence_validation": "V6", "cases": [c["name"] for c in observed]})
            except (ValueError, OSError, KeyError) as error:
                errors.append({"id": scenario, "error": str(error)})
    baseline = root / "tests/Unit/Architecture/platforms-dependency-baseline.php"
    if baseline.exists() or baseline.is_symlink():
        errors.append({"id": "V9", "error": "temporary dependency baseline exists"})
    for path in contract["permanent_role_tests"]:
        if not file_cases(scenario_evidence.get("V6", []), path):
            errors.append({"id": "V9", "error": "missing executed permanent role test: " + path})
    gates["V9"] = {"procedure": "baseline absent and permanent role assertions executed in V6",
                   "verdict": "fail" if any(e["id"] in {"V6", "V9"} for e in errors) else "pass",
                   "tests": contract["permanent_role_tests"]}
    gates["V8"] = {"procedure": "candidate/manifest and all required scenario evidence verified",
                   "verdict": "fail" if errors else "pass"}
    need(candidate["input_tree_sha256"] == digest(current), "current candidate input hash mismatch")
    return {"format": "process-core-acceptance/v1", "candidate": candidate,
            "verdict": "ready_for_owner_review" if not errors else "incomplete_evidence",
            "errors": errors, "validations": gates, "scenario_traceability": trace,
            "requirements": contract["requirements"], "accepted_contracts": contract["accepted_contracts"],
            "normative_sources": contract["normative_sources"],
            "quality_limits": contract["quality_limits"], "contract_sha256": sha(root / CONTRACT),
            "input_hashes": {"schema": {p: h for p, h in current.items() if p.startswith("database/")},
                             "config": {p: h for p, h in current.items() if p.startswith("config/")},
                             "fixtures": {p: h for p, h in current.items() if p.startswith("tests/Fixtures/")},
                             "recipes_prompts": {p: h for p, h in current.items() if p.startswith("resources/ai/")},
                             "versions": {p: h for p, h in current.items() if p in
                                          {"composer.lock", "pnpm-lock.yaml", "docker/compose.core-test.yml"}}}}
