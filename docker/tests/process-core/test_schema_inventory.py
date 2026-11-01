"""Negative inventory examples; invokes the same PHP guard used by install/quality, without DB I/O."""
import copy
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]
INVENTORY = ROOT / "tests/Support/ProcessCore/schema-inventory.json"
BRIDGE = r"""
require $argv[1].'/tests/Support/ProcessCore/SchemaInventory.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$document = $input['document'] ?? file_get_contents($argv[1].'/docs/dev/process-schema.md');
echo json_encode(Tests\Support\ProcessCore\SchemaInventory::violations(
    $argv[1], $input['inventory'], $document, $input['actual'] ?? null
), JSON_THROW_ON_ERROR);
"""


class SchemaInventoryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.original = json.loads(INVENTORY.read_text())
        cls.rig = None
        if os.environ.get("PROCESS_CORE_SCHEMA_RIG") == "1":
            import rig
            rig.identity()  # Refuse stale/foreign mounts before any container command.
            cls.rig = rig

    def setUp(self):
        self.inventory = copy.deepcopy(self.original)

    def violations(self, *, document=None, actual=None):
        data = {"inventory": self.inventory}
        if document is not None:
            data["document"] = document
        if actual is not None:
            data["actual"] = actual
        if self.rig is not None:
            result = self.rig.compose("exec", "-T", "app", "php", "-r", BRIDGE,
                                      "/var/www/html", data=json.dumps(data))
        else:
            result = subprocess.run(["php", "-r", BRIDGE, str(ROOT)], input=json.dumps(data),
                                    capture_output=True, text=True, cwd=ROOT, check=True)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def assertRejected(self, text, **kwargs):
        errors = self.violations(**kwargs)
        self.assertTrue(any(text in error for error in errors), errors)

    def baseline_actual(self):
        tables = self.original["baseline"]["tables"]
        # A controlled catalog fixture for negative guard cases, not a real PostgreSQL pass.
        return copy.deepcopy({"schema": "\n".join(t["definition"] for t in tables.values()),
                "catalog": {"partitions": [],
                            "columns": [c for t in tables.values() for c in t["columns"]],
                            "constraints": [c for t in tables.values() for c in t["constraints"]],
                            "indexes": [c for t in tables.values() for c in t["indexes"]]}})

    def sql_projection_actual(self):
        actual = self.baseline_actual()
        projection = json.loads((Path(__file__).parent / "fixtures/catalog-map-order.json").read_text())
        for section, name_key in (("columns", "column_name"), ("constraints", "name"), ("indexes", "indexname")):
            row = projection[section][0]
            table_key = "tablename" if section == "indexes" else "table_name"
            for i, expected in enumerate(actual["catalog"][section]):
                if expected[table_key] == "cache" and expected[name_key] == row[name_key]:
                    self.assertEqual(expected, row)  # Authentic values and scalar types match the manifest.
                    self.assertEqual(list(expected), sorted(expected))
                    self.assertNotEqual(list(expected), list(row))
                    actual["catalog"][section][i] = copy.deepcopy(row)
                    break
            else:
                self.fail(f"missing authentic {section} fixture target")
        return actual

    def test_authentic_sql_projection_map_key_order_passes(self):
        self.assertEqual(self.violations(actual=self.sql_projection_actual()), [])

    def test_map_comparison_preserves_scalar_types_missing_extra_keys_and_values(self):
        sections = (("columns", "ordinal_position", "real column inventory differs"),
                    ("constraints", "definition", "real FK/CHECK/UNIQUE inventory differs"),
                    ("indexes", "indexdef", "real index inventory differs"))
        for section, field, error in sections:
            for mutation in ("scalar_type", "missing_key", "extra_key", "semantic_value"):
                with self.subTest(section=section, mutation=mutation):
                    actual = self.sql_projection_actual()
                    table_key = "tablename" if section == "indexes" else "table_name"
                    row = next(r for r in actual["catalog"][section] if r[table_key] == "cache")
                    if mutation == "scalar_type":
                        row[field] = str(row[field]) if section == "columns" else False
                    elif mutation == "missing_key":
                        del row[field]
                    elif mutation == "extra_key":
                        row["unexpected_key"] = None
                    else:
                        row[field] = 2 if section == "columns" else "changed physical definition"
                    self.assertRejected(error, actual=actual)

    def test_recursive_maps_preserve_nested_values_and_list_order(self):
        actual = self.sql_projection_actual()
        expected = next(c for c in self.inventory["baseline"]["tables"]["cache"]["columns"] if c["column_name"] == "key")
        row = next(c for c in actual["catalog"]["columns"] if c["table_name"] == "cache" and c["column_name"] == "key")
        # Test-only metadata exercises recursion; it is never written into the inventory or live schema.
        expected["comparison_fixture"] = {"metadata": {"a": True, "z": 1}, "columns": ["platform_id", "code"]}
        row["comparison_fixture"] = {"columns": ["platform_id", "code"], "metadata": {"z": 1, "a": True}}
        self.assertEqual(self.violations(actual=actual), [])
        row["comparison_fixture"]["metadata"]["a"] = 1
        self.assertRejected("real column inventory differs: cache", actual=actual)
        row["comparison_fixture"]["metadata"]["a"] = True
        row["comparison_fixture"]["columns"].reverse()
        self.assertRejected("real column inventory differs: cache", actual=actual)

    def test_catalog_row_lists_and_composite_column_order_remain_strict(self):
        for section, error in (("columns", "real column inventory differs"),
                               ("constraints", "real FK/CHECK/UNIQUE inventory differs"),
                               ("indexes", "real index inventory differs")):
            with self.subTest(section=section, mutation="row_order"):
                actual = self.sql_projection_actual()
                actual["catalog"][section].reverse()
                self.assertRejected(error, actual=actual)
        for section, field, error in (("constraints", "definition", "real FK/CHECK/UNIQUE inventory differs: prc_registries"),
                                      ("indexes", "indexdef", "real index inventory differs: prc_registries")):
            with self.subTest(section=section, mutation="column_tuple_order"):
                actual = self.sql_projection_actual()
                table_key = "tablename" if section == "indexes" else "table_name"
                row = next(r for r in actual["catalog"][section]
                           if r[table_key] == "prc_registries" and "(platform_id, code)" in r[field])
                row[field] = row[field].replace("(platform_id, code)", "(code, platform_id)")
                self.assertRejected(error, actual=actual)

    def test_complete_inventory_and_full_document_pass(self):
        # P3.1 adds immutable document storage while preserving legacy consumers.
        self.assertEqual(self.inventory["current_item"], "P3.1")
        self.assertEqual(len(self.inventory["migrations"]), 81)
        self.assertEqual(len(self.inventory["baseline"]["tables"]), 113)
        self.assertEqual(sum(len(t["columns"]) for t in self.inventory["baseline"]["tables"].values()), 1344)
        adapter_columns = {c["column_name"]: (c["udt_name"], c["is_nullable"])
                           for c in self.inventory["baseline"]["tables"]["prc_outbound_operations"]["columns"]
                           if c["column_name"] in ("adapter_health_id", "adapter_health_epoch")}
        self.assertEqual(adapter_columns, {"adapter_health_id": ("uuid", "YES"),
                                          "adapter_health_epoch": ("int8", "YES")})
        self.assertEqual(self.violations(document=(ROOT / "docs/dev/process-schema.md").read_text()), [])
        self.assertEqual(self.violations(actual=self.baseline_actual()), [])

    def test_missing_migration_and_hash_drift_fail(self):
        self.inventory["migrations"].pop()
        self.assertRejected("migration inventory differs")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["migrations"][0]["sha256"] = "0" * 64
        self.assertRejected("migration input drift")

    def test_created_carriers_installed_and_future_carriers_absent(self):
        del self.inventory["baseline"]["tables"]["prc_process_work"]
        self.assertRejected("created carrier missing from installed baseline: prc_process_work")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["baseline"]["tables"]["prc_analysis_windows"] = copy.deepcopy(self.original["baseline"]["tables"]["prc_process_outbox"])
        self.assertRejected("future carrier activated in P3.1 baseline: prc_analysis_windows")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["current_item"] = "P1.1"
        self.assertRejected("future carrier activated in P1.1 baseline: prc_process_work")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["current_item"] = "next"
        self.assertRejected("unknown current_item")

    def test_installed_carrier_and_change_drift_fail(self):
        work = self.inventory["baseline"]["tables"]["prc_process_work"]
        work["columns"] = list(reversed(work["columns"]))
        self.assertRejected("installed carrier columns differ from declaration: prc_process_work")
        self.inventory = copy.deepcopy(self.original)
        work = self.inventory["baseline"]["tables"]["prc_process_work"]
        work["indexes"] = [i for i in work["indexes"] if i["indexname"] != "prc_process_work_provider_recovery_due_idx"]
        self.assertRejected("installed carrier index missing: prc_process_work.prc_process_work_provider_recovery_due_idx")
        self.inventory = copy.deepcopy(self.original)
        work = self.inventory["baseline"]["tables"]["prc_process_work"]
        work["constraints"] = [c for c in work["constraints"] if c["name"] != "prc_process_work_owner_check"]
        self.assertRejected("installed carrier constraint missing: prc_process_work.prc_process_work_owner_check")
        self.inventory = copy.deepcopy(self.original)
        procurements = self.inventory["baseline"]["tables"]["prc_procurements"]
        procurements["columns"] = [c for c in procurements["columns"] if c["column_name"] != "head_generation"]
        self.assertRejected("installed change column missing from baseline: prc_procurements.head_generation")

    def test_missing_carrier_owner_creation_and_retention_fail(self):
        for field in ("owner", "create_at", "retention"):
            with self.subTest(field=field):
                self.inventory = copy.deepcopy(self.original)
                self.inventory["carriers"]["prc_analysis_windows"].pop(field)
                self.assertRejected("prc_analysis_windows")
        self.inventory = copy.deepcopy(self.original)
        del self.inventory["carriers"]["llm_budget_periods"]
        self.assertRejected("missing target carriers")

    def test_missing_target_column_and_type_nullability_fail(self):
        columns = self.inventory["carriers"]["prc_analysis_windows"]["columns"]
        del columns["checkpoint_version"]
        self.assertRejected("missing target columns: prc_analysis_windows.checkpoint_version")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["carriers"]["prc_process_work"]["columns"]["id"]["nullable"] = None
        self.assertRejected("column type/nullability missing")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["carriers"]["prc_process_work"]["columns"]["id"]["type"] = "bigint"
        self.assertRejected("column declaration/type mismatch")

    def test_work_state_tenth_value_and_cascade_expansion_fail(self):
        self.inventory["conventions"]["work_states"].append("partial")
        self.assertRejected("nine canonical values")
        self.inventory = copy.deepcopy(self.original)
        column = self.inventory["carriers"]["prc_process_work"]["columns"]["state"]
        column["definition"] = column["definition"].replace("'superseded'", "'superseded','partial'")
        self.assertRejected("work state CHECK differs")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["carriers"]["prc_network_permit_scopes"]["columns"]["budget_id"]["references"]["on_delete"] = "CASCADE"
        self.assertRejected("unauthorized CASCADE")

    def test_composition_child_cascade_and_budget_fk_are_mandatory(self):
        self.inventory["carriers"]["prc_network_permit_scopes"]["columns"]["permit_id"]["references"]["on_delete"] = "NO ACTION"
        self.assertRejected("composition-child CASCADE missing")
        self.inventory = copy.deepcopy(self.original)
        del self.inventory["carriers"]["prc_network_permit_scopes"]["columns"]["budget_id"]["references"]
        self.assertRejected("target FK contract differs")

    def test_placeholder_and_premature_subject_fk_fail(self):
        ref = self.inventory["carriers"]["prc_process_work"]["columns"]["analysis_window_id"]["references"]
        ref["table"] = "prc_fake_subjects"
        self.assertRejected("placeholder FK target")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["carriers"]["prc_process_work"]["columns"]["analysis_window_id"]["references"]["at"] = "P1.6"
        self.assertRejected("FK precedes real target creation")

    def test_missing_subject_ancestors_and_wrong_owner_type_fail(self):
        self.inventory["subjects"]["extraction"]["ancestors"] = ["prc_analysis_windows"]
        self.assertRejected("incomplete subject ancestors/fence")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["subjects"]["prepare"]["type"] = "uuid"
        self.assertRejected("stage owner tuple differs")

    def test_unsupported_stage_activation_and_early_schema_support_fail(self):
        self.inventory["staging"][1]["active"] = ["extraction"]
        self.assertRejected("activation of unsupported stage")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["staging"][1]["supported"].append("prepare")
        self.assertRejected("schema-supported stage set differs")
        self.inventory = copy.deepcopy(self.original)
        self.inventory["staging"][5]["fixture_only"].remove("screening")
        self.inventory["staging"][5]["active"].append("screening")
        self.assertRejected("real handler precedes owning cutover")
        self.inventory = copy.deepcopy(self.original)
        stage = self.inventory["carriers"]["prc_process_handler_holds"]["columns"]["stage"]
        stage["definition"] = stage["definition"].replace("'download'", "'download','extraction'")
        self.assertRejected("current stage CHECK differs")

    def test_missing_work_check_and_index_fail(self):
        work = self.inventory["carriers"]["prc_process_work"]
        work["constraints"] = [c for c in work["constraints"] if "prc_process_work_owner_check" not in c]
        self.assertRejected("missing work CHECK: owner_check")
        self.inventory = copy.deepcopy(self.original)
        work = self.inventory["carriers"]["prc_process_work"]
        work["indexes"] = [c for c in work["indexes"] if "provider_recovery_due_idx" not in c]
        self.assertRejected("missing work index: provider_recovery_due_idx")

    def test_early_legacy_reader_removal_fails(self):
        self.inventory["legacy"]["prc_source_incidents"]["drop_at"] = "P1.14"
        self.assertRejected("legacy reader/table cutover differs")

    def test_cutover_rejects_retained_circuits_and_missing_incidents(self):
        self.inventory["baseline"]["tables"]["prc_source_circuits"] = copy.deepcopy(
            self.original["baseline"]["tables"]["prc_source_incidents"])
        self.assertRejected("legacy reader/table cutover differs: prc_source_circuits")
        self.inventory = copy.deepcopy(self.original)
        del self.inventory["baseline"]["tables"]["prc_source_incidents"]
        self.assertRejected("legacy reader/table cutover differs: prc_source_incidents")

    def test_circuits_cannot_disappear_before_their_owning_cutover(self):
        self.inventory["current_item"] = "P2.16"
        self.assertRejected("legacy reader/table cutover differs: prc_source_circuits")

    def test_headings_only_document_and_removed_column_row_fail(self):
        self.assertRejected("docs/schema manifest mismatch", document="# Process schema\n## Versioned inventory\n")
        document = (ROOT / "docs/dev/process-schema.md").read_text()
        self.assertRejected("docs/schema manifest mismatch", document=document.replace("| head_generation | bigint |", "| omitted | bigint |", 1))

    def test_actual_catalog_column_constraint_index_and_extra_table_fail(self):
        actual = self.baseline_actual()
        actual["catalog"]["columns"][0]["is_nullable"] = "YES"
        self.assertRejected("real column inventory differs", actual=actual)
        actual = self.baseline_actual()
        actual["catalog"]["constraints"].pop()
        self.assertRejected("real FK/CHECK/UNIQUE inventory differs", actual=actual)
        actual = self.baseline_actual()
        actual["catalog"]["indexes"].pop()
        self.assertRejected("real index inventory differs", actual=actual)
        actual = self.baseline_actual()
        actual["schema"] += "\nCREATE TABLE public.prc_unexpected_catalog_fixture (\n id uuid NOT NULL\n);"
        self.assertRejected("real catalog root table set differs", actual=actual)

    def test_exact_ddl_precision_and_nullability_drift_fail(self):
        actual = self.baseline_actual()
        original = self.original["baseline"]["tables"]["prc_procurements"]["definition"]
        self.assertIn("numeric(18,2)", original)
        actual["schema"] = actual["schema"].replace(original, original.replace("numeric(18,2)", "numeric(18,1)"))
        self.assertRejected("real column/type/nullability/CHECK DDL differs: prc_procurements", actual=actual)


if __name__ == "__main__":
    unittest.main()
