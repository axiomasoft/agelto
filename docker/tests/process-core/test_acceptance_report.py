"""Synthetic evidence checks, not acceptance or load runs."""
import copy
import json
from pathlib import Path
import tempfile
import unittest
import xml.etree.ElementTree as ET

import acceptance_report as report


class EvidenceFixture:
    def __init__(self, root):
        self.root = Path(root)
        self.reports = self.root / "reports"
        self.folder = self.reports / "current"
        self.folder.mkdir(parents=True)
        self.inputs = {"config/process.php": "original"}
        self.contract = {"rig_reuse": {}, "install_independent_test_inputs": [],
                         "gate_cases": {}, "test_cases": {}, "scenarios": {}, "scenario_group_names": {},
                         "permanent_role_tests": [], "requirements": {"R03": ["T08"]},
                         "accepted_contracts": {"D9": ["T09"]},
                         "normative_sources": {"execution": {"sha256": "supplied-contract-hash"}},
                         "quality_limits": {"paid_holdout": "not_executed", "deployment_approval": False}}
        self.cases = []
        for n in range(1, 49):
            scenario = f"T{n:02}"
            path = f"tests/Feature/ProcessCore/{scenario}Test.php"
            self.write_text(self.root / path, "<?php test('case', function () {})->group('" + scenario.lower() + "');")
            self.contract["scenarios"][scenario] = [{"file": path, "group": scenario.lower(),
                                                     "target": "core-test-regression"}]
            self.contract["scenario_group_names"][scenario] = scenario.lower()
            case = {"class": "Tests.Feature.ProcessCore." + scenario + "Test", "file": path,
                    "name": "case", "status": "passed"}
            self.cases.append(case)
            self.contract["test_cases"][path] = report.case_inventory([case])
        self.save_contract()
        self.write(self.folder / "inputs.json", self.inputs)
        self.candidate = {"head": "a" * 40, "input_tree_sha256": report.digest(self.inputs)}
        for validation, (command, width) in report.GATES.items():
            folder = self.reports / validation
            folder.mkdir()
            self.write(folder / "inputs.json", self.inputs)
            result = {"command": command, "exit": 0, "candidate": self.candidate}
            if width:
                result["width"] = width
                self.write(folder / "load.json", {"width": width, **{key: 0 for key in
                           ("resources", "attempt_seconds", "cohort_wait_seconds",
                            "throughput_documents_per_second", "history_rows", "requests", "server_receipts",
                            "active_reservations_after_drain", "pending_outbox_after_drain",
                            "unaccounted_work", "fifo_grant_inversions")}})
            if validation == "V1":
                measured = None
                for name in ("install-1", "install-2"):
                    snapshot = folder / name
                    snapshot.mkdir()
                    self.write_text(snapshot / "schema.sql", "schema\n")
                    self.write(snapshot / "normalized-catalog.json", {"partman": ["present"]})
                    self.write(snapshot / "seeds.json", {"seed": 1})
                    measured = report.snapshot(snapshot)
                self.write(folder / "install-comparison.json", {"first": measured, "second": measured})
                result["manifests"] = measured
            elif validation == "V7":
                result["quality"] = {k: 0 for k in ("pint", "phpstan", "architecture", "schema-docs", "schema-tests",
                                                    "docs-integrity", "composer-audit")}
            else:
                self.save_cases(folder, result, self.cases)
                if validation in {"V2", "V3", "V4", "V5"}:
                    self.contract["gate_cases"][validation] = copy.deepcopy(self.contract["test_cases"])
                if validation == "V6":
                    comparison = {"missing": [], "duplicates": [], "nonpassing": [], "preserved_cases": 48}
                    self.write(folder / "scenario-comparison.json", comparison)
                    result["tests"]["scenario_comparison_sha256"] = report.sha(folder / "scenario-comparison.json")
            self.write(folder / "result.json", result)
        self.save_contract()

    @staticmethod
    def write(path, value):
        EvidenceFixture.write_text(path, json.dumps(value))

    @staticmethod
    def write_text(path, text):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def save_contract(self):
        self.write(self.root / report.CONTRACT, self.contract)

    def save_cases(self, folder, result, cases):
        self.write(folder / "executed-scenarios.json", cases)
        suite = ET.Element("testsuite")
        for case in cases:
            node = ET.SubElement(suite, "testcase", classname=case["class"], name=case["name"],
                                 file=case["file"], assertions="1")
            if case["status"] != "passed":
                ET.SubElement(node, "skipped" if case["status"] == "skipped" else "failure")
        ET.ElementTree(suite).write(folder / "junit.xml")
        result["tests"] = {**result.get("tests", {}), "executed": len(cases), "exit": 0}

    def build(self):
        return report.build(self.root, self.reports, self.folder,
                            lambda load, width: {"width": width, "verdict": "pass"}, self.candidate)

    def mutate_result(self, validation, action):
        path = self.reports / validation / "result.json"
        result = json.loads(path.read_text())
        action(result)
        self.write(path, result)


class AcceptanceReportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.f = EvidenceFixture(self.temp.name)

    def test_complete_manifest_is_candidate_bound_and_limits_are_explicit(self):
        result = self.f.build()
        self.assertEqual([], result["errors"])
        self.assertEqual("ready_for_owner_review", result["verdict"])
        self.assertEqual(48, len(result["scenario_traceability"]))
        self.assertEqual(self.f.candidate, result["candidate"])
        self.assertEqual(self.f.contract["normative_sources"], result["normative_sources"])
        self.assertEqual("not_executed", result["quality_limits"]["paid_holdout"])
        self.assertEqual("original", result["input_hashes"]["config"]["config/process.php"])
        self.assertEqual(10, result["validations"]["V4"]["width"])
        self.assertEqual(20, result["validations"]["V5"]["width"])

    def test_missing_gate_and_latest_red_width_cannot_reuse_old_green(self):
        (self.f.reports / "V2/result.json").unlink()
        self.f.write(self.f.reports / "before-current/result.json", {"command": "core-test-load", "width": 10, "exit": 1})
        errors = self.f.build()["errors"]
        self.assertTrue(any(e["id"] == "V2" for e in errors))
        self.assertTrue(any(e["id"] == "V4" for e in errors))
        self.assertFalse(any(e["id"] == "V5" for e in errors))

    def test_future_red_cannot_replace_evidence_at_the_collected_checkpoint(self):
        self.f.write(self.f.reports / "zzz/result.json", {"command": "core-test-load", "width": 10, "exit": 1})
        self.assertEqual([], self.f.build()["errors"])

    def test_changed_configuration_is_stale_but_report_docs_are_recorded_for_reuse(self):
        previous = {"config/process.php": "original", "docs/dev/process-core-acceptance.md": "before"}
        current = {**previous, "docs/dev/process-core-acceptance.md": "after"}
        self.assertEqual(["docs/dev/process-core-acceptance.md"],
                         report.changed_inputs(self.f.root, previous, current, self.f.contract))
        current["config/process.php"] = "changed"
        with self.assertRaisesRegex(ValueError, "stale scenario inputs"):
            report.changed_inputs(self.f.root, previous, current, self.f.contract)

    def test_collector_only_rig_edit_does_not_mask_execution_change(self):
        source = "def collect(folder):\n    return 1\ndef suite(folder):\n    return 2\n"
        changed = source.replace("return 1", "return 3")
        signature = report.rig_signature(source)
        self.assertEqual(signature, report.rig_signature(changed))
        self.assertNotEqual(signature, report.rig_signature(changed.replace("return 2", "return 4")))

    def test_install_reuse_cannot_ignore_schema_config_or_seed_changes(self):
        for path in ("database/migrations/core.php", "database/seeders/CoreSeeder.php", "config/process.php"):
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, "stale scenario inputs"):
                report.changed_inputs(self.f.root, {path: "old"}, {path: "new"}, self.f.contract, install=True)

    def test_missing_named_gate_inventory_is_not_silently_optional(self):
        self.f.contract["gate_cases"].pop("V3")
        self.f.save_contract()
        with self.assertRaisesRegex(ValueError, "incomplete named gate case inventory"):
            self.f.build()

    def test_install_rig_reuse_also_pins_suite_dispatch(self):
        source = "ROOT = 1\ndef reset(folder):\n    return 1\ndef execute(command, name):\n    return 2\n"
        changed = source.replace("return 2", "return 3")
        self.f.contract["rig_reuse"] = {
            "install": {"sha256": "old", "ast_sha256": report.rig_signature(source, True)},
            "runtime": {"ast_sha256": report.rig_signature(source)}}
        path = "docker/tests/process-core/rig.py"
        self.f.write_text(self.f.root / path, changed)
        with self.assertRaisesRegex(ValueError, "stale scenario inputs"):
            report.changed_inputs(self.f.root, {path: "old"}, {path: "new"}, self.f.contract, install=True)

    def test_unrelated_documentation_is_not_an_execution_input_exception(self):
        path = "docs/dev/process-schema.md"
        with self.assertRaisesRegex(ValueError, "stale scenario inputs"):
            report.changed_inputs(self.f.root, {path: "old"}, {path: "new"}, self.f.contract)

    def test_changing_a_real_fixture_cannot_hide_behind_report_manifest_entry(self):
        fixture = {"contract_fixtures": [{"id": "real-input", "sha256": "before"}]}
        self.f.contract["fixture_reuse"] = {"sha256": "old", "without_report_contract_sha256": report.digest(fixture)}
        fixture["contract_fixtures"].append({"id": "p319-acceptance-report", "path": report.CONTRACT,
                                             "sha256": report.sha(self.f.root / report.CONTRACT)})
        path = "tests/Fixtures/ProcessCore/manifest.json"
        self.f.write(self.f.root / path, fixture)
        report.changed_inputs(self.f.root, {path: "old"}, {path: "new"}, self.f.contract)
        fixture["contract_fixtures"][0]["sha256"] = "after"
        self.f.write(self.f.root / path, fixture)
        with self.assertRaisesRegex(ValueError, "stale scenario inputs"):
            report.changed_inputs(self.f.root, {path: "old"}, {path: "new"}, self.f.contract)

    def test_forged_input_digest_is_rejected(self):
        self.f.mutate_result("V3", lambda r: r["candidate"].update(input_tree_sha256="bad"))
        self.assertTrue(any(e["id"] == "V3" for e in self.f.build()["errors"]))

    def test_missing_individual_case_is_rejected_even_with_nonempty_file(self):
        path = self.f.contract["scenarios"]["T22"][0]["file"]
        self.f.contract["test_cases"][path]["names_sha256"] = report.digest(["case", "required late receipt"])
        self.f.save_contract()
        self.assertTrue(any(e["id"] == "T22" for e in self.f.build()["errors"]))

    def test_missing_file_group_and_gate_case_are_rejected(self):
        (self.f.root / self.f.contract["scenarios"]["T09"][0]["file"]).unlink()
        self.f.contract["scenarios"]["T11"][0]["group"] = "absent"
        self.f.contract["gate_cases"]["V3"] = {"tests/Feature/ProcessCore/T15Test.php": {"count": 2}}
        self.f.save_contract()
        ids = {e["id"] for e in self.f.build()["errors"]}
        self.assertTrue({"T09", "T11", "V3"}.issubset(ids))

    def test_incomplete_scenario_group_mapping_is_rejected(self):
        del self.f.contract["scenario_group_names"]["T11"]
        self.f.save_contract()
        with self.assertRaisesRegex(ValueError, "incomplete scenario group mapping"):
            self.f.build()

    def test_junit_and_machine_case_list_must_agree(self):
        self.f.write(self.f.reports / "V2/executed-scenarios.json", [])
        self.assertTrue(any(e["id"] == "V2" for e in self.f.build()["errors"]))

    def test_skipped_case_is_not_success(self):
        result = json.loads((self.f.reports / "V6/result.json").read_text())
        cases = copy.deepcopy(self.f.cases)
        cases[0]["status"] = "skipped"
        self.f.save_cases(self.f.reports / "V6", result, cases)
        self.assertTrue(any(e["id"] == "V6" for e in self.f.build()["errors"]))

    def test_schema_partman_and_comparison_tampering_are_rejected(self):
        self.f.write(self.f.reports / "V1/install-2/normalized-catalog.json", {"partman": []})
        self.f.write(self.f.reports / "V6/scenario-comparison.json", {})
        ids = {e["id"] for e in self.f.build()["errors"]}
        self.assertTrue({"V1", "V6"}.issubset(ids))

    def test_missing_quality_subcheck_and_legacy_baseline_are_rejected(self):
        self.f.mutate_result("V7", lambda r: r["quality"].pop("phpstan"))
        self.f.write_text(self.f.root / "tests/Unit/Architecture/platforms-dependency-baseline.php", "<?php")
        self.assertTrue({"V7", "V9"}.issubset({e["id"] for e in self.f.build()["errors"]}))

    def test_missing_permanent_role_test_is_rejected(self):
        self.f.contract["permanent_role_tests"] = ["tests/Unit/Architecture/MissingTest.php"]
        self.f.save_contract()
        self.assertTrue(any(e["id"] == "V9" for e in self.f.build()["errors"]))

    def test_symlink_evidence_is_rejected(self):
        path = self.f.reports / "V3/executed-scenarios.json"
        path.unlink()
        path.symlink_to(self.f.reports / "V2/executed-scenarios.json")
        self.assertTrue(any(e["id"] == "V3" for e in self.f.build()["errors"]))


if __name__ == "__main__":
    unittest.main()
