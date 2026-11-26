"""Guard negatives run without starting or mutating a Docker service."""
import copy
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
import os
import subprocess
import sys
import json
import shutil
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("rig", Path(__file__).with_name("rig.py"))
rig = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rig)


def copy_synthetic_gsfx(root):
    source = Path(__file__).resolve().parents[3] / rig.GSFX_FIXTURE
    destination = root / rig.GSFX_FIXTURE
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, destination)
    return {"source": rig.GSFX_FIXTURE, "runtime": rig.GSFX_FIXTURE,
            "sha256": rig.hashlib.sha256(destination.read_bytes()).hexdigest()}


class RigGuardsTest(unittest.TestCase):
    def test_аудит_composer_повторяет_только_ошибку_транспорта(self):
        scenarios = [
            ([(100, "curl error 28 while downloading"), (0, "")], 0),
            ([(100, "curl error 28 while downloading"), (100, "curl error 56 while downloading"), (0, "")], 0),
            ([(100, "curl error 6 while downloading")] * 3, 100),
            ([(100, "curl error 56 while downloading")] * 3, 100),
            ([(1, "Found a security advisory")], 1),
        ]
        for attempts, expected in scenarios:
            with self.subTest(attempts=attempts), tempfile.TemporaryDirectory() as directory:
                folder = Path(directory)
                pending = iter(attempts)

                def audit(output, name, *args):
                    self.assertEqual(args, ("audit", "--locked", "--no-interaction"))
                    exit_code, message = next(pending)
                    (output / f"{name}.log").write_text(message)
                    return exit_code

                with patch.object(rig, "composer_on_bridge", side_effect=audit) as command:
                    self.assertEqual(rig.composer_audit(folder), expected)
                    self.assertEqual(command.call_count, len(attempts))

    def test_номер_шарда_ограничен_матрицей_из_четырёх(self):
        for index in range(1, 5):
            with patch.dict(os.environ, {"SHARD": f"{index}/4"}):
                self.assertEqual(rig.shard_selection(), f"{index}/4")
        for value in ("", "0/4", "5/4", "1/2", "--no-tia"):
            with self.subTest(value=value), patch.dict(os.environ, {"SHARD": value}), self.assertRaises(rig.GuardError):
                rig.shard_selection()

    def test_gsfx_отклоняет_локальный_архив_из_манифеста(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = root / "tests/Fixtures/ProcessCore/runtime-inputs.json"
            manifest.parent.mkdir(parents=True)
            local_archive = "estimates/files/developer.gsfx"
            manifest.write_text(json.dumps({"files": [{"source": local_archive, "runtime": local_archive}]}))
            with patch.object(rig, "ROOT", root), self.assertRaisesRegex(rig.GuardError, "hermetic synthetic fixture"):
                rig.verify_fixture_inputs()

    def test_экспорт_публикует_ровно_один_граф_tia(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(rig, "compose"):
            folder = Path(directory)
            recording = folder / "tia-recording" / "project"
            recording.mkdir(parents=True)
            with self.assertRaisesRegex(rig.GuardError, "exactly one graph"):
                rig.export_tia(folder, "/test")
            (recording / "graph.json").write_text("{}")
            (recording / "coverage.bin.gz").write_bytes(b"fixture")
            rig.export_tia(folder, "/test")
            self.assertEqual({p.name for p in (folder / "tia-baseline").iterdir()}, {"graph.json", "coverage.bin.gz"})

    def test_single_file_selection_rejects_directories_options_and_path_escape(self):
        valid = "tests/Feature/ProcessCore/Platforms/PlatformUiRegistryTest.php"
        with patch.dict(os.environ, {"TEST_FILE": valid}):
            self.assertEqual(rig.single_test_path(), valid)
        for value in ["", "tests/Feature", "--testsuite=Feature", "tests/../artisan", "tests/Feature/AbsentTest.php"]:
            with patch.dict(os.environ, {"TEST_FILE": value}), self.assertRaises(rig.GuardError):
                rig.single_test_path()

    @classmethod
    def setUpClass(cls):
        cls.config = rig.config_identity()  # compose config only; no containers or DB commands.

    def test_positive_resolved_compose_identity(self):
        self.assertEqual(rig.validate_config(self.config)["name"], "agelto_core_test")

    def test_vendor_mounts_require_both_sanitized_readonly_snapshots(self):
        for key in ("azguard", "proxyma"):
            target = "/var/www/html/local/vendors/" + key
            for mutation in ("missing", "writable", "foreign"):
                config = copy.deepcopy(self.config)
                mounts = config["services"]["app"]["volumes"]
                mount = next(m for m in mounts if m["target"] == target)
                if mutation == "missing":
                    mounts.remove(mount)
                elif mutation == "writable":
                    mount["read_only"] = False
                else:
                    mount["source"] = str(rig.ROOT / "docker/tests/process-core/fixtures")
                with self.subTest(key=key, mutation=mutation), self.assertRaises(rig.GuardError):
                    rig.validate_config(config)

    def test_failed_command_preserves_error_written_only_to_stdout(self):
        result = SimpleNamespace(returncode=1, stdout="Migration table not found", stderr="")
        with patch.object(rig.subprocess, "run", return_value=result):
            with self.assertRaisesRegex(rig.GuardError, "Migration table not found"):
                rig.run(["php", "artisan", "migrate:status"])

    def test_wrong_project_network_external_volume_and_dev_bind_are_rejected(self):
        for mutation in [
            lambda c: c.update(name="agelto_dev"),
            lambda c: c["networks"]["core"].update(internal=False),
            lambda c: c["volumes"]["core-postgres"].update(external=True),
            lambda c: c["volumes"]["core-postgres"].update(name="agelto_dev_pgsql_data"),
            lambda c: c["services"]["app"]["volumes"].append(
                {"type": "bind", "source": str(rig.ROOT / ".env"), "target": "/var/www/html/.env", "read_only": True}),
            lambda c: c["services"]["app"]["environment"].update(DB_HOST="postgres"),
            lambda c: c["services"]["app"]["environment"].update(REDIS_PREFIX="agelto-database-"),
            lambda c: c["services"]["app"].update(ports=["8080:8080"]),
        ]:
            config = copy.deepcopy(self.config)
            mutation(config)
            with self.subTest(config=config["name"]), self.assertRaises(rig.GuardError):
                rig.validate_config(config)

    def test_exact_database_parallel_token_and_connected_identity(self):
        proof = {"project": rig.PROJECT, "environment": "testing", "database": "agelto_test",
                 "user": "core_test", "port": 5432, "server_version": "17.6"}
        rig.target_guard(proof)
        for key, value in [("project", "agelto_dev"), ("database", "agelto"),
                           ("database", "alien_test"), ("user", "postgres"),
                           ("environment", "production"), ("port", 6432), ("server_version", "16.0")]:
            with self.subTest(key=key, value=value), self.assertRaises(rig.GuardError):
                rig.target_guard({**proof, key: value})
        parallel = {**proof, "database": "agelto_test_test_2"}
        rig.target_guard(parallel, "agelto_test_test_2", "2")
        for token in ["1", "2;DROP DATABASE agelto", "../2"]:
            with self.subTest(token=token), self.assertRaises(rig.GuardError):
                rig.target_guard(parallel, "agelto_test_test_2", token)

    def test_guard_failure_precedes_every_reset_mutation(self):
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "destructive_guard", side_effect=rig.GuardError("foreign target")), \
                patch.object(rig, "compose") as compose, patch.object(rig, "pg") as pg:
            with self.assertRaises(rig.GuardError):
                rig.reset(Path(temp))
            compose.assert_not_called()  # no worker stop, Redis flush, volume cleanup or DROP.
            pg.assert_not_called()

    def test_stopped_application_is_rejected_before_any_reset_mutation(self):
        services = {name: {"state": "running"} for name in
                    ("app", "core-postgres", "core-redis", "object-storage")}
        services["app"]["state"] = "exited"
        with patch.object(rig, "identity", return_value={"services": services}), \
                patch.object(rig, "compose") as compose, patch.object(rig, "pg") as pg:
            with self.assertRaisesRegex(rig.GuardError, "running application/data services"):
                rig.destructive_guard()
            compose.assert_not_called()
            pg.assert_not_called()

    def test_install_clock_is_enabled_for_migrations_and_seed_without_seed_normalization(self):
        clock = {"anchor_utc": "2026-10-24T07:40:00Z", "identity": {"project": rig.PROJECT}}
        observed = {**clock, "anchor_utc": "2026-10-24T07:42:00Z"}
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "install_clock", return_value=observed), \
                patch.object(rig, "stop_workers"), patch.object(rig, "readiness"), \
                patch.object(rig, "snapshot", return_value={"seeds": "full-seed-hash"}), \
                patch.object(rig, "pg"), patch.object(rig, "compose") as compose:
            compose.return_value = SimpleNamespace(returncode=0, stdout="", stderr="")
            for name in ("install-1", "install-2"):
                folder = Path(temp) / name
                folder.mkdir()
                self.assertEqual(rig.reset(folder, clock=clock), {"seeds": "full-seed-hash"})
                self.assertEqual(json.loads((folder / "install-clock.json").read_text())["pass"], clock)
            installs = [call.args for call in compose.call_args_list
                        if "migrate" in call.args or "db:seed" in call.args]
            self.assertEqual(len(installs), 4)
            for command in installs:
                self.assertEqual(command[:9], ("exec", "-T", "-e", "PROCESS_CORE_INSTALL=1",
                                               "-e", "PROCESS_CORE_INSTALL_AT=" + clock["anchor_utc"], "app", "php",
                                               "tests/Support/ProcessCore/artisan.php"))

    def test_install_clock_samples_only_guarded_postgres_and_native_runtime(self):
        events = []
        timestamp = "2026-10-24T07:40:00Z"

        def guard():
            events.append("guarded")
            return {"project": rig.PROJECT}

        def postgres(sql):
            self.assertEqual(events, ["guarded"])
            self.assertIn("clock_timestamp() AT TIME ZONE 'UTC'", sql)
            events.append("postgres")
            return json.dumps({"utc": timestamp, "timezone": "Etc/UTC"})

        def runtime(*args):
            self.assertEqual(events, ["guarded", "postgres"])
            self.assertEqual(args[:6], ("exec", "-T", "app", "php", "-r", "echo gmdate('Y-m-d\\TH:i:s\\Z');"))
            return SimpleNamespace(stdout=timestamp)

        with patch.object(rig, "destructive_guard", side_effect=guard), \
                patch.object(rig, "pg", side_effect=postgres), patch.object(rig, "compose", side_effect=runtime):
            clock = rig.install_clock()
            self.assertEqual(clock["anchor_utc"], timestamp)
            self.assertEqual(clock["runtime_utc"], timestamp)
            self.assertEqual(clock["identity"], {"project": rig.PROJECT})

    def test_install_clock_rejects_skew_month_mismatch_timezone_and_invalid_dates_before_mutation(self):
        for postgres, runtime, timezone in [
            ("2026-10-24T07:40:00Z", "2026-10-24T07:41:01Z", "UTC"),
            ("2026-10-31T23:59:59Z", "2026-11-01T00:00:00Z", "UTC"),
            ("2026-10-24T07:40:00Z", "2026-10-24T07:40:00Z", "Europe/Moscow"),
            ("2026-02-30T07:40:00Z", "2026-02-30T07:40:00Z", "UTC"),
            ("2026-10-24", "2026-10-24T07:40:00Z", "UTC"),
        ]:
            with self.subTest(postgres=postgres, runtime=runtime, timezone=timezone), \
                    tempfile.TemporaryDirectory() as temp, \
                    patch.object(rig, "destructive_guard", return_value={}), \
                    patch.object(rig, "pg", return_value=json.dumps({"utc": postgres, "timezone": timezone})) as pg, \
                    patch.object(rig, "compose", return_value=SimpleNamespace(stdout=runtime)) as compose, \
                    patch.object(rig, "stop_workers") as stop:
                with self.assertRaises((rig.GuardError, ValueError)):
                    rig.reset(Path(temp))
                stop.assert_not_called()
                self.assertEqual(pg.call_count, 1)  # Only the clock SELECT, never DROP.
                self.assertEqual(compose.call_count, 1)  # Only native PHP clock, never a migration.

    def test_pass_month_rollover_is_rejected_before_worker_stop_or_drop(self):
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "install_clock", return_value={"anchor_utc": "2026-11-01T00:00:00Z"}), \
                patch.object(rig, "stop_workers") as stop, patch.object(rig, "pg") as pg, \
                patch.object(rig, "compose") as compose:
            with self.assertRaisesRegex(rig.GuardError, "crossed a UTC month"):
                rig.reset(Path(temp), clock={"anchor_utc": "2026-10-31T23:59:59Z"})
            stop.assert_not_called()
            pg.assert_not_called()
            compose.assert_not_called()

    def test_two_clean_installs_share_one_reported_anchor(self):
        clock = {"anchor_utc": "2026-10-24T07:40:00Z", "identity": {"project": rig.PROJECT}}
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "acceptance_runtime", return_value={"fixture": True}), \
                patch.object(rig, "ROOT", Path(temp)), \
                patch.object(rig, "new_report", return_value=(Path(temp), {})), \
                patch.object(rig, "install_clock", return_value=clock) as capture, \
                patch.object(rig, "reset", return_value={"seeds": "full-seed-hash"}) as reset, \
                patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-install", "install"), 0)
            capture.assert_called_once()
            self.assertEqual(len(reset.call_args_list), 2)
            for call in reset.call_args_list:
                self.assertIs(call.kwargs["clock"], clock)
            self.assertEqual(json.loads((Path(temp) / "result.json").read_text())["install_clock"], clock)
            self.assertEqual(json.loads((Path(temp) / "install-clock.json").read_text()), clock)

    def test_required_runtime_inputs_are_not_optional_or_hash_normalized(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(rig, "ROOT", Path(temp)):
            root = Path(temp)
            manifest = root / "tests/Fixtures/ProcessCore/runtime-inputs.json"
            original = root / "original.json"
            original.write_text('{"measured": 42}')
            manifest.write_text(json.dumps({"files": [copy_synthetic_gsfx(root),
                                  {"source": "original.json", "runtime": "original.json",
                                   "sha256": rig.hashlib.sha256(original.read_bytes()).hexdigest()}]}))
            rig.verify_fixture_inputs()
            original.write_text('{"measured": 0}')
            with self.assertRaisesRegex(rig.GuardError, "required runtime input"):
                rig.verify_fixture_inputs()
            original.unlink()
            with self.assertRaisesRegex(rig.GuardError, "required runtime input"):
                rig.verify_fixture_inputs()

    def test_historical_fixtures_use_readonly_test_mount_without_plan_aliases(self):
        manifest = rig.verify_fixture_inputs()
        entries = {entry["source"]: entry for entry in manifest["files"]}
        fixture = "tests/Fixtures/ProcessCore/historical/gold-fields-audit.json"
        self.assertEqual(entries[fixture]["runtime"], fixture)
        self.assertEqual(entries[fixture]["sha256"],
                         "221b395d513c9612de02e016b1ce42c283957103dc279d6678eec8d783e4f5b0")
        mounts = self.config["services"]["app"]["volumes"]
        self.assertTrue(any(m["type"] == "bind" and m["target"] == "/var/www/html/tests"
                            and m["read_only"] for m in mounts))
        self.assertFalse(any(m["target"].startswith(("/var/www/html/plans/", "/var/www/html/research/"))
                             for m in mounts))

    def test_container_label_and_mount_substitution_are_rejected(self):
        service = self.config["services"]["app"]
        container = {
            "Name": "/agelto_core_test-app-1",
            "Config": {"Labels": {"com.docker.compose.project": rig.PROJECT, "com.docker.compose.service": "app"},
                       "Env": [f"{k}={v}" for k, v in service["environment"].items()]},
            "HostConfig": {"NetworkMode": "agelto_core_test_core", "Privileged": False},
            "NetworkSettings": {"Networks": {"agelto_core_test_core": {}}},
            "Mounts": [{"Type": m["type"], "Destination": m["target"], "Source": m["source"],
                        "Name": f"agelto_core_test_{m['source']}", "RW": False}
                       for m in service["volumes"]],
        }
        rig.container_identity(self.config, "app", container)
        for change in [lambda c: c["Config"]["Labels"].update({"com.docker.compose.project": "agelto_dev"}),
                       lambda c: c.update(Name="/agelto_dev-app-1"),
                       lambda c: c["Mounts"].append({"Destination": "/foreign"}),
                       lambda c: c["NetworkSettings"]["Networks"].update(dev={})]:
            forged = copy.deepcopy(container)
            change(forged)
            with self.assertRaises(rig.GuardError):
                rig.container_identity(self.config, "app", forged)

    def test_unknown_suite_is_nonzero_without_runtime_commands(self):
        with patch.object(rig, "compose") as compose, \
                self.assertRaisesRegex(rig.GuardError, "unknown core target"):
            rig.main("core-test-unknown")
        compose.assert_not_called()

    def test_load_width_fails_before_clock_or_reset(self):
        for width in ("", "1", "100", "20;exit", "010"):
            with self.subTest(width=width), tempfile.TemporaryDirectory() as temp, \
                    patch.dict(os.environ, {"WIDTH": width}), \
                    patch.object(rig, "ROOT", Path(temp).parent), \
                    patch.object(rig, "new_report", return_value=(Path(temp), {})), \
                    patch.object(rig, "install_clock") as clock, patch.object(rig, "reset") as reset, \
                    patch("sys.stdout", new=io.StringIO()):
                self.assertEqual(rig.execute("core-test-load", "load"), 1)
                clock.assert_not_called()
                reset.assert_not_called()

    def test_faults_and_load_are_separate_nonempty_owned_suites(self):
        for name in ("faults", "load"):
            self.assertIn(name, rig.IMPLEMENTED)
            self.assertNotIn(name, rig.FUTURE)
            self.assertTrue(rig.SUITE_PATHS[name])
            self.assertNotEqual(rig.SUITE_PATHS[name], rig.SUITE_PATHS["e2e"])

    def test_execution_suite_runs_only_owned_paths_and_rejects_empty_or_skipped_selection(self):
        for name in ("documents", "execution", "network", "platforms", "acquisition", "e2e"):
            self.assertNotIn(name, rig.FUTURE)
            for cases, error in [([], "empty test selection"),
                                 ([{"name": "skipped", "skipped": True}, {"name": "claim", "skipped": False}], "failed/skipped"),
                                 ([{"name": "unasserted", "skipped": False, "assertions": "0"}], "no assertions"),
                                 ([{"name": "admission", "skipped": False}], None)]:
                with self.subTest(suite=name, error=error), tempfile.TemporaryDirectory() as temp, \
                        patch.object(rig, "readiness"), patch.object(rig, "stop_workers"), \
                        patch.object(rig, "verify_fixture_inputs"), patch.object(rig, "prepare_runtime"), \
                        patch.object(rig, "compose") as compose:
                    folder = Path(temp)
                    commands = []

                    def execute(*args, **kwargs):
                        commands.append(args)
                        if args[0] == "cp":
                            suite = rig.ET.Element("testsuite")
                            for case in cases:
                                element = rig.ET.SubElement(suite, "testcase", name=case["name"], assertions=case.get("assertions", "1"))
                                if case["skipped"]:
                                    rig.ET.SubElement(element, "skipped")
                            rig.ET.ElementTree(suite).write(folder / "junit.xml")
                        return SimpleNamespace(returncode=0, stdout="", stderr="")

                    compose.side_effect = execute
                    if error:
                        with self.assertRaisesRegex(rig.GuardError, error):
                            rig.suite(folder, name)
                    else:
                        self.assertEqual(rig.suite(folder, name), {"executed": 1, "exit": 0})
                    pest = next(command for command in commands if command[0] == "exec")
                    self.assertEqual(list(pest[-len(rig.SUITE_PATHS[name]):]), rig.SUITE_PATHS[name])
                    self.assertNotIn("--testsuite=Unit", pest)

    def test_concurrency_suite_runs_after_clean_reset_and_only_multi_process_paths(self):
        self.assertNotIn("concurrency", rig.FUTURE)
        self.assertIn("concurrency", rig.IMPLEMENTED)
        self.assertEqual(rig.SUITE_PATHS["concurrency"], ["tests/Integration/ProcessCore/Concurrency"])
        calls = []
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "acceptance_runtime", return_value={"fixture": True}), \
                patch.object(rig, "ROOT", Path(temp).parent), \
                patch.object(rig, "new_report", return_value=(Path(temp), {"command": "core-test-concurrency"})), \
                patch.object(rig, "install_clock", return_value={"epoch": 1}), \
                patch.object(rig, "reset", side_effect=lambda folder, clock: calls.append(("reset", folder.name))), \
                patch.object(rig, "suite", side_effect=lambda folder, name: calls.append(("suite", name)) or {"executed": 1, "exit": 0}), \
                patch.object(rig, "write_json"), patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-concurrency", "concurrency"), 0)
        self.assertEqual(calls, [("reset", "clean-reset"), ("suite", "concurrency")])

    def test_documents_suite_preserves_safety_corpus_and_runs_after_guarded_clean_reset(self):
        self.assertIn("documents", rig.IMPLEMENTED)
        self.assertNotIn("documents", rig.FUTURE)
        self.assertEqual(rig.SUITE_PATHS["documents"], [
            "tests/Feature/ProcessCore/Documents",
            "tests/Integration/ProcessCore/Concurrency/StorageCapacityTest.php",
            "tests/Integration/ProcessCore/Concurrency/DocumentStorageTest.php",
            "tests/Feature/Procurement/Prepare",
            "tests/Feature/Document/DocumentDownloaderTest.php",
        ])
        calls = []
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "ROOT", Path(temp).parent), \
                patch.object(rig, "new_report", return_value=(Path(temp), {"command": "core-test-documents"})), \
                patch.object(rig, "install_clock", return_value={"epoch": 1}), \
                patch.object(rig, "reset", side_effect=lambda folder, clock: calls.append(("reset", folder.name))), \
                patch.object(rig, "suite", side_effect=lambda folder, name: calls.append(("suite", name)) or {"executed": 1, "exit": 0}), \
                patch.object(rig, "write_json"), patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-documents", "documents"), 0)
        self.assertEqual(calls, [("reset", "clean-reset"), ("suite", "documents")])

    def test_network_suite_runs_initial_machinery_after_guarded_clean_reset(self):
        self.assertIn("network", rig.IMPLEMENTED)
        self.assertEqual(rig.FUTURE, set())
        self.assertEqual(rig.SUITE_PATHS["network"], [
            "tests/Feature/ProcessCore/Network",
            "tests/Feature/Procurement/Network/ProxymaCutoverTest.php",
            "tests/Feature/Procurement/Network/ProxymaProbingAndGuardTest.php",
            "tests/Integration/ProcessCore/Concurrency/CatalogConcurrencyTest.php",
        ])
        calls = []
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "ROOT", Path(temp).parent), \
                patch.object(rig, "new_report", return_value=(Path(temp), {"command": "core-test-network"})), \
                patch.object(rig, "install_clock", side_effect=lambda: calls.append("clock") or {"epoch": 1}), \
                patch.object(rig, "reset", side_effect=lambda folder, clock: calls.append(("reset", folder.name))), \
                patch.object(rig, "suite", side_effect=lambda folder, name: calls.append(("suite", name)) or {"executed": 1, "exit": 0}), \
                patch.object(rig, "write_json"), patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-network", "network"), 0)
        self.assertEqual(calls, ["clock", ("reset", "clean-reset"), ("suite", "network")])

    def test_platforms_suite_runs_owned_contracts_after_guarded_clean_reset(self):
        self.assertIn("platforms", rig.IMPLEMENTED)
        self.assertNotIn("platforms", rig.FUTURE)
        self.assertEqual(rig.SUITE_PATHS["platforms"], [
            "tests/Feature/ProcessCore/Platforms",
            "tests/Unit/Architecture/PlatformsDomainLayoutTest.php",
            "tests/Unit/Architecture/PlatformExtensionContractTest.php",
        ])
        calls = []
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "ROOT", Path(temp).parent), \
                patch.object(rig, "new_report", return_value=(Path(temp), {"command": "core-test-platforms"})), \
                patch.object(rig, "install_clock", side_effect=lambda: calls.append("clock") or {"epoch": 1}), \
                patch.object(rig, "reset", side_effect=lambda folder, clock: calls.append(("reset", folder.name))), \
                patch.object(rig, "suite", side_effect=lambda folder, name: calls.append(("suite", name)) or {"executed": 1, "exit": 0}), \
                patch.object(rig, "write_json"), patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-platforms", "platforms"), 0)
        self.assertEqual(calls, ["clock", ("reset", "clean-reset"), ("suite", "platforms")])

    def test_acquisition_suite_runs_owned_contracts_after_guarded_clean_reset(self):
        self.assertIn("acquisition", rig.IMPLEMENTED)
        self.assertNotIn("acquisition", rig.FUTURE)
        self.assertEqual(rig.SUITE_PATHS["acquisition"], ["tests/Feature/ProcessCore/Acquisition", "tests/Unit/Architecture/AcquisitionIdentityOwnershipTest.php"])
        calls = []
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "ROOT", Path(temp).parent), \
                patch.object(rig, "new_report", return_value=(Path(temp), {"command": "core-test-acquisition"})), \
                patch.object(rig, "install_clock", side_effect=lambda: calls.append("clock") or {"epoch": 1}), \
                patch.object(rig, "reset", side_effect=lambda folder, clock: calls.append(("reset", folder.name))), \
                patch.object(rig, "suite", side_effect=lambda folder, name: calls.append(("suite", name)) or {"executed": 1, "exit": 0}), \
                patch.object(rig, "write_json"), patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-acquisition", "acquisition"), 0)
        self.assertEqual(calls, ["clock", ("reset", "clean-reset"), ("suite", "acquisition")])

    def test_e2e_suite_runs_owned_contracts_after_guarded_clean_reset(self):
        self.assertIn("e2e", rig.IMPLEMENTED)
        self.assertNotIn("e2e", rig.FUTURE)
        self.assertEqual(rig.SUITE_PATHS["e2e"], [
            "tests/Feature/ProcessCore/E2e",
            "tests/Integration/ProcessCore/E2e",
            "tests/Feature/ProcessCore/Observability/FinalCutoverTest.php",
                       "tests/Feature/ProcessCore/Observability/PlatformMetricsProjectionTest.php",
            "tests/Feature/ProcessCore/Platforms/PlatformUiRegistryTest.php",
        ])
        calls = []
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "ROOT", Path(temp).parent), \
                patch.object(rig, "new_report", return_value=(Path(temp), {"command": "core-test-e2e"})), \
                patch.object(rig, "acceptance_runtime", return_value={"fixture": True}), \
                patch.object(rig, "install_clock", side_effect=lambda: calls.append("clock") or {"epoch": 1}), \
                patch.object(rig, "reset", side_effect=lambda folder, clock: calls.append(("reset", folder.name))), \
                patch.object(rig, "suite", side_effect=lambda folder, name: calls.append(("suite", name)) or {"executed": 1, "exit": 0}), \
                patch.object(rig, "write_json"), patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-e2e", "e2e"), 0)
        self.assertEqual(calls, ["clock", ("reset", "clean-reset"), ("suite", "e2e")])

    def test_fixture_proof_requires_success_nonempty_assertions_and_no_skips(self):
        for code, result in [(1, {"executed": 1, "skipped": 0}),
                             (0, {"executed": 0, "skipped": 0}),
                             (0, {"executed": 1, "skipped": 1})]:
            with self.subTest(code=code, result=result), tempfile.TemporaryDirectory() as temp, \
                    patch.object(rig, "run") as run:
                run.side_effect = [SimpleNamespace(returncode=0, stdout='{"fixtures": []}', stderr=""),
                                   SimpleNamespace(returncode=code, stdout=json.dumps(result), stderr="")]
                with self.assertRaises(rig.GuardError):
                    rig.fixture_proof(Path(temp))
                self.assertFalse((Path(temp) / "fixture-proof.json").exists())

    def test_catalog_normalization_preserves_semantic_differences(self):
        first = {"extensions": ["pg_partman"], "partman": [{"parent_table": "public.events",
                 "retention": "6 months", "maintenance_last_run": "2026-10-23T10:00:00Z"}]}
        second = copy.deepcopy(first)
        second["partman"][0]["maintenance_last_run"] = "2026-10-23T11:00:00Z"
        self.assertEqual(rig.normalize_catalog(first), rig.normalize_catalog(second))
        second["partman"][0]["retention"] = "1 month"
        self.assertNotEqual(rig.normalize_catalog(first), rig.normalize_catalog(second))

    def test_identity_preflight_does_not_require_migrations_but_readiness_does(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "phpunit.xml").write_text('<env name="DB_DATABASE" value="agelto_test"/>')
            (root / "test.env").write_text("APP_ENV=testing\n")
            bin_dir = root / "bin"
            bin_dir.mkdir()
            docker = bin_dir / "docker"
            docker.write_text('''#!/usr/bin/env bash
case " $* " in
  *" ps --status running postgres "*) echo postgres ;;
  *" ps --status running app "*) echo app ;;
  *" pg_isready "*) exit 0 ;;
  *"SELECT 1 FROM pg_database"*) echo 1 ;;
  *" migrate:status "*) echo Pending ;;
  *) exit 99 ;;
esac
''')
            docker.chmod(0o755)
            command = ["bash", str(rig.ROOT / "docker/scripts/test-database.sh"), "--root", str(root),
                       "--env-file", "test.env", "--project-name", "agelto_contract", "--phase"]
            env = {**os.environ, "PATH": str(bin_dir) + os.pathsep + os.environ["PATH"]}
            self.assertEqual(subprocess.run(command + ["identity"], env=env, capture_output=True).returncode, 0)
            self.assertEqual(subprocess.run(command + ["readiness"], env=env, capture_output=True).returncode, 68)

    def test_report_symlink_is_rejected_before_directory_creation(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / "project"
            root.mkdir()
            foreign = Path(temp) / "foreign"
            foreign.mkdir()
            (root / "storage").symlink_to(foreign, target_is_directory=True)
            reports = root / "storage/app/testing/process-core/reports"
            with patch.object(rig, "ROOT", root), patch.object(rig, "REPORTS", reports):
                with self.assertRaises(rig.GuardError):
                    rig.new_report("guard-fixture")
            self.assertEqual(list(foreign.iterdir()), [])

    def test_stopped_owned_worker_is_not_a_foreign_network_member(self):
        def container(service, running):
            config = self.config["services"][service]
            return {
                "Name": f"/{rig.PROJECT}-{service}-1",
                "Config": {"Labels": {"com.docker.compose.project": rig.PROJECT,
                                      "com.docker.compose.service": service},
                           "Env": [f"{k}={v}" for k, v in config["environment"].items()]},
                "State": {"Running": running, "Status": "running" if running else "exited"},
                "HostConfig": {"NetworkMode": f"{rig.PROJECT}_core"},
                "NetworkSettings": {"Networks": {f"{rig.PROJECT}_core": {}}},
                "Mounts": [{"Type": m["type"], "Destination": m["target"], "Source": m["source"],
                            "Name": f"{rig.PROJECT}_{m['source']}", "RW": False}
                           for m in config["volumes"]],
            }

        containers = {"app-id": container("app", True), "stopped-id": container("horizon", False)}
        network = {"Internal": True, "Driver": "bridge",
                   "Labels": {"com.docker.compose.project": rig.PROJECT}, "Containers": {"app-id": {}}}

        def inspect_resource(argv, **kwargs):
            return SimpleNamespace(returncode=0, stdout=json.dumps([network])) if argv[1] == "network" \
                else SimpleNamespace(returncode=1, stdout="")

        with patch.object(rig, "config_identity", return_value=self.config), \
                patch.object(rig, "compose", return_value=SimpleNamespace(stdout="app-id\nstopped-id\n")), \
                patch.object(rig, "inspect_json", side_effect=lambda kind, cid: containers[cid]), \
                patch.object(rig, "run", side_effect=inspect_resource):
            self.assertEqual(rig.identity()["services"]["horizon"]["state"], "exited")
            network["Containers"]["foreign-id"] = {}
            with self.assertRaisesRegex(rig.GuardError, "foreign network member"):
                rig.identity()
            network["Containers"] = {}
            with self.assertRaisesRegex(rig.GuardError, "foreign network member"):
                rig.identity()

    def test_project_lock_excludes_a_second_real_process_and_releases(self):
        code = r'''
import importlib.util, pathlib, sys, fcntl
spec=importlib.util.spec_from_file_location('rig',sys.argv[1]); rig=importlib.util.module_from_spec(spec); spec.loader.exec_module(rig)
rig.ROOT=pathlib.Path(sys.argv[2]); rig.REPORTS=rig.ROOT/'storage/reports'
with open(rig.REPORTS/'.project.lock','a') as probe:
    try:
        fcntl.flock(probe,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError:
        print('blocked',flush=True)
    else:
        print('not-blocked',flush=True)
with rig.project_lock():
    print('entered',flush=True)
'''
        with tempfile.TemporaryDirectory() as temp, patch.object(rig, "ROOT", Path(temp)), \
                patch.object(rig, "REPORTS", Path(temp) / "storage/reports"):
            child = None
            try:
                with rig.project_lock():
                    child = subprocess.Popen([sys.executable, "-B", "-c", code, rig.__file__, temp],
                                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, bufsize=0)
                    self.assertEqual(child.stdout.readline(), b"blocked\n")
                output, error = child.communicate(timeout=5)
                self.assertEqual(child.returncode, 0, error)
                self.assertEqual(output, b"entered\n")
            finally:
                if child is not None and child.poll() is None:
                    child.kill()
                    child.communicate()

    def test_cli_acquires_project_lock_before_any_operation(self):
        events = []

        @rig.contextmanager
        def lock():
            events.append("locked")
            yield
            events.append("released")

        def execute(command, name):
            self.assertEqual(events, ["locked"])
            self.assertEqual((command, name), ("core-test-install", "install"))
            events.append("executed")
            return 0

        with patch.object(rig, "project_lock", lock), patch.object(rig, "execute", execute):
            self.assertEqual(rig.main("core-test-install"), 0)
        self.assertEqual(events, ["locked", "executed", "released"])

    def test_ai_target_requires_clean_reset_and_asserting_suite(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(rig, "ROOT", Path(temp)), \
                patch.object(rig, "new_report", return_value=(Path(temp), {})), \
                patch.object(rig, "install_clock", return_value={}), \
                patch.object(rig, "reset") as reset, \
                patch.object(rig, "suite", side_effect=rig.GuardError("suite executed no assertions")) as suite:
            self.assertNotIn("ai", rig.FUTURE)
            self.assertEqual(rig.execute("core-test-ai", "ai"), 1)
            reset.assert_called_once_with(Path(temp) / "clean-reset", clock={})
            suite.assert_called_once_with(Path(temp), "ai")
            self.assertEqual(json.loads((Path(temp) / "result.json").read_text())["exit"], 1)

    def test_project_lock_rejects_symlink_without_touching_foreign_file(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(rig, "ROOT", Path(temp)), \
                patch.object(rig, "REPORTS", Path(temp) / "storage/reports"):
            rig.prepare_reports()
            foreign = Path(temp) / "foreign"
            foreign.write_text("keep")
            (rig.REPORTS / ".project.lock").symlink_to(foreign)
            with self.assertRaises(OSError):
                with rig.project_lock():
                    self.fail("symlink lock accepted")
            self.assertEqual(foreign.read_text(), "keep")

    def test_pure_unit_suite_does_not_require_a_migrated_domain_schema(self):
        junit = '<testsuite><testcase classname="Tests.Unit.PureTest" name="pure" assertions="1"/></testsuite>'
        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "identity") as identity, patch.object(rig, "readiness") as readiness, \
                patch.object(rig, "fixture_proof") as fixture_proof, \
                patch.object(rig, "stop_workers"), patch.object(rig, "compose") as compose:
            folder = Path(temp)

            def call(*args, **kwargs):
                if args[0] == "cp":
                    (folder / "junit.xml").write_text(junit)
                return SimpleNamespace(returncode=0, stdout="", stderr="")

            compose.side_effect = call
            self.assertEqual(rig.suite(folder, "unit")["executed"], 1)
            identity.assert_called_once()
            readiness.assert_not_called()
            fixture_proof.assert_called_once_with(folder)

    def test_unit_suite_rejects_empty_skipped_or_assertion_free_results(self):
        for cases in ['', '<testcase name="empty" assertions="0"/>',
                      '<testcase name="skipped" assertions="1"><skipped/></testcase>']:
            with self.subTest(cases=cases), tempfile.TemporaryDirectory() as temp, \
                    patch.object(rig, "identity"), patch.object(rig, "stop_workers"), \
                    patch.object(rig, "fixture_proof"), patch.object(rig, "prepare_runtime"), \
                    patch.object(rig, "compose") as compose:
                folder = Path(temp)

                def call(*args, **kwargs):
                    if args[0] == "cp":
                        (folder / "junit.xml").write_text('<testsuite>' + cases + '</testsuite>')
                    return SimpleNamespace(returncode=0, stdout="", stderr="")

                compose.side_effect = call
                with self.assertRaises(rig.GuardError):
                    rig.suite(folder, "unit")

    def test_executed_scenarios_preserve_exact_names_datasets_and_only_mapped_paths(self):
        before = [{"file": "tests/Unit/OldTest.php::original with data set windows-1251",
                   "name": "original with data set windows-1251", "status": "passed"}]
        moved = {**before[0], "file": "tests/Unit/Platforms/Eis/Csv/OldTest.php"}
        moves = [{"old": "tests/Unit/OldTest.php", "new": moved["file"]}]
        extra = {"file": "tests/Unit/NewGuardTest.php", "name": "negative guard", "status": "passed"}
        proof = rig.compare_scenarios(before, [moved, extra], moves)
        self.assertEqual(proof["missing"], [])
        self.assertEqual(proof["preserved_cases"], 1)
        self.assertEqual(proof["new"], [{"file": extra["file"], "name": extra["name"], "count": 1}])

        # Equal totals and the same file cannot hide a lost dataset/renamed scenario.
        for replacement in [{**moved, "name": "original with data set utf8"},
                            {**moved, "status": "skipped"}, {**moved, "status": "failed"},
                            {**moved, "file": "tests/Unit/WrongTest.php"}]:
            with self.subTest(replacement=replacement):
                proof = rig.compare_scenarios(before, [replacement], moves)
                self.assertEqual(proof["preserved_cases"], 0)
                self.assertEqual(len(proof["missing"]), 1)
        self.assertTrue(rig.compare_scenarios(before, [moved, moved], moves)["duplicates"])
        for invalid in [[], [{**before[0], "status": "skipped"}], [{**before[0], "file": ""}]]:
            with self.subTest(invalid=invalid), self.assertRaises(rig.GuardError):
                rig.compare_scenarios(invalid, [moved], moves)

    def test_d18_transfer_requires_every_exact_passed_target_and_preserves_original_accounting(self):
        source = {"file": "tests/Unit/OldTest.php", "name": "edge with data set win1251", "status": "passed"}
        target = {"file": "tests/Unit/NewTest.php", "name": "bounded decoder win1251", "status": "passed"}
        transfer = {"source": {"file": source["file"], "name": source["name"]},
                    "replacements": [{"file": target["file"], "name": target["name"]}],
                    "reason": "The typed decoder owns source decoding after the acquisition cutover.",
                    "contract": "knowledge/legacy-inventory#li-purpose"}
        proof = rig.compare_scenarios([source], [target], [], [transfer])
        self.assertEqual(proof["preserved_cases"], 1)
        self.assertEqual(proof["missing"], [])
        self.assertEqual(proof["transferred"], [transfer])
        for after in [[], [{**target, "status": "skipped"}], [{**target, "status": "failed"}],
                      [{**target, "name": "other dataset"}], [target, target]]:
            with self.subTest(after=after):
                proof = rig.compare_scenarios([source], after, [], [transfer])
                self.assertEqual(proof["preserved_cases"], 0)
                self.assertEqual(len(proof["missing"]), 1)
        for transfers in [[transfer, transfer], [{**transfer, "reason": ""}],
                          [{**transfer, "replacements": []}],
                          [{**transfer, "source": {"file": source["file"], "name": "unknown source"}}],
                          [{**transfer, "replacements": [transfer["source"]]}]]:
            with self.subTest(transfers=transfers), self.assertRaises(rig.GuardError):
                rig.compare_scenarios([source], [target], [], transfers)
        chained = {**transfer, "source": transfer["replacements"][0],
                   "replacements": [{"file": "tests/Unit/ThirdTest.php", "name": "third"}]}
        with self.assertRaisesRegex(rig.GuardError, "chain or cycle"):
            rig.compare_scenarios([source, target], [target], [], [transfer, chained])

    def test_scenario_proof_records_missing_case_before_failing(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(rig, "ROOT", Path(temp)):
            fixtures = Path(temp) / "tests/Fixtures/ProcessCore"
            fixtures.mkdir(parents=True)
            original = {"format": "process-core-executed-baseline/v1", "provenance": {"exit": 0, "command": "core-test-regression"},
                        "cases": [{"file": "tests/Unit/OldTest.php", "name": "edge", "status": "passed"}]}
            original["provenance"]["cases_sha256"] = rig.digest(original["cases"])
            (fixtures / "original-executed-scenarios.json").write_text(json.dumps(original))
            (fixtures / "platforms-move-map.json").write_text(json.dumps({"moves": []}))
            with self.assertRaisesRegex(rig.GuardError, "original executed scenarios lost"):
                rig.scenario_proof(Path(temp), [])
            proof = json.loads((Path(temp) / "scenario-comparison.json").read_text())
            self.assertEqual(proof["missing"], [{"file": "tests/Unit/OldTest.php", "name": "edge", "count": 1}])
            self.assertEqual(proof["before_evidence"], original["provenance"])
            self.assertEqual(rig.scenario_proof(Path(temp), original["cases"])["preserved_cases"], 1)
            original["cases"][0]["name"] = "silently replaced original"
            (fixtures / "original-executed-scenarios.json").write_text(json.dumps(original))
            with self.assertRaisesRegex(rig.GuardError, "provenance/hash mismatch"):
                rig.scenario_proof(Path(temp), original["cases"])

    def test_authentic_before_fixture_has_2014_passed_unique_cases_and_move_map_preserves_each(self):
        fixtures = rig.ROOT / "tests/Fixtures/ProcessCore"
        original = json.loads((fixtures / "original-executed-scenarios.json").read_text())
        moves = json.loads((fixtures / "platforms-move-map.json").read_text())["moves"]
        self.assertEqual(original["provenance"]["exit"], 0)
        self.assertEqual(original["provenance"]["command"], "core-test-regression")
        paths = {move["old"]: move["new"] for move in moves}
        after = [{**case, "file": paths.get(case["file"].split("::", 1)[0], case["file"])} for case in original["cases"]]
        proof = rig.compare_scenarios(original["cases"], after, moves)
        self.assertEqual(proof["original_executed_cases"], 2014)
        self.assertEqual(proof["preserved_cases"], 2014)
        self.assertEqual(proof["missing"], [])
        self.assertEqual(proof["duplicates"], [])
        self.assertEqual(proof["new"], [])
        canonical = json.dumps(original["cases"], ensure_ascii=False, sort_keys=True, indent=2) + "\n"
        self.assertEqual(rig.hashlib.sha256(canonical.encode()).hexdigest(),
                         original["provenance"]["artifacts"]["executed-scenarios.json"])
        self.assertEqual(rig.digest(original["cases"]), original["provenance"]["cases_sha256"])

    def test_full_regression_rejects_lost_case_even_when_original_file_is_present(self):
        cases = [{"file": "tests/Feature/OriginalTest.php", "name": name, "status": "passed"}
                 for name in ("first", "windows-1251")]
        original = {"format": "process-core-executed-baseline/v1", "cases": cases,
                    "provenance": {"command": "core-test-regression", "exit": 0,
                                   "cases_sha256": rig.digest(cases)}}
        with tempfile.TemporaryDirectory() as temp, patch.object(rig, "ROOT", Path(temp)), \
                patch.object(rig, "readiness"), patch.object(rig, "stop_workers"), \
                patch.object(rig, "verify_fixture_inputs"), patch.object(rig, "prepare_runtime"), \
                patch.object(rig, "compose") as compose:
            folder = Path(temp) / "report"
            folder.mkdir()
            fixtures = Path(temp) / "tests/Fixtures/ProcessCore"
            fixtures.mkdir(parents=True)
            (fixtures / "original-executed-scenarios.json").write_text(json.dumps(original))
            (fixtures / "platforms-move-map.json").write_text(json.dumps({"moves": []}))
            (fixtures / "manifest.json").write_text(json.dumps({"files": [
                {"path": cases[0]["file"], "scenarios": ["first", "windows-1251"]}]}))
            observed = cases[:1]

            def execute(*args, **kwargs):
                if args[0] == "cp":
                    suite = rig.ET.Element("testsuite")
                    for case in observed:
                        rig.ET.SubElement(suite, "testcase", file=case["file"], name=case["name"], assertions="1")
                    rig.ET.ElementTree(suite).write(folder / "junit.xml")
                return SimpleNamespace(returncode=0, stdout="", stderr="")

            compose.side_effect = execute
            with self.assertRaisesRegex(rig.GuardError, "original executed scenarios lost"):
                rig.suite(folder, "regression")
            self.assertEqual(json.loads((folder / "baseline-coverage.json").read_text())["missing_files"], [])
            comparison = json.loads((folder / "scenario-comparison.json").read_text())
            self.assertEqual(comparison["missing"], [{"file": cases[1]["file"], "name": "windows-1251", "count": 1}])
            observed = cases + [{"file": "tests/Unit/NewGuardTest.php", "name": "negative", "status": "passed"}]
            result = rig.suite(folder, "regression")
            self.assertEqual((result["executed"], result["original_preserved"]), (3, 2))
            self.assertEqual(result["scenario_comparison_sha256"],
                             rig.hashlib.sha256((folder / "scenario-comparison.json").read_bytes()).hexdigest())

    def test_report_requires_candidate_bound_comparison_artifact_and_its_hash(self):
        from test_acceptance_report import EvidenceFixture
        with tempfile.TemporaryDirectory() as temp:
            evidence = EvidenceFixture(temp)
            with patch.object(rig, "ROOT", evidence.root), patch.object(rig, "REPORTS", evidence.reports), \
                 patch.object(rig, "run", return_value=subprocess.CompletedProcess([], 0, "a" * 40, "")), \
                 patch.object(rig, "validate_load_report", return_value={"verdict": "pass"}):
                rig.collect(evidence.folder)
                self.assertEqual("ready_for_owner_review", json.loads((evidence.folder / "summary.json").read_text())["verdict"])
                (evidence.reports / "V6/scenario-comparison.json").write_text('{"preserved_cases": 0}')
                with self.assertRaisesRegex(rig.GuardError, "missing/stale acceptance evidence"):
                    rig.collect(evidence.folder)
                summary = json.loads((evidence.folder / "summary.json").read_text())
                self.assertTrue(any(e["id"] == "V6" and "comparison hash mismatch" in e["error"] for e in summary["errors"]))


class AcceptanceRuntimeTest(unittest.TestCase):
    def capture(self, browser=False, wrong_browser=False, missing_app=False):
        dependencies = {"proxyma": {"commit": "a" * 40}}
        services = {name: {"image": "php:fixture"} for name in ("app", "horizon", "scheduler")}
        services["browser"] = {"image": "playwright:fixture"}
        observed = {"services": {"app": {"id": "app-container"}}, "config_sha256": "configuration"}
        if browser:
            observed["services"]["browser"] = {"id": "browser-container"}
        calls = []

        def inspect(kind, name):
            calls.append((kind, name))
            if kind == "image":
                if name == "playwright:fixture":
                    if not browser:
                        raise rig.GuardError("optional browser image is not cached")
                    return {"Id": "browser-image"}
                if missing_app:
                    raise rig.GuardError("required app image is not cached")
                return {"Id": "app-image"}
            return {"Image": ("stale-browser-image" if wrong_browser else "browser-image")
                    if name == "browser-container" else "app-image"}

        with tempfile.TemporaryDirectory() as temp, \
                patch.object(rig, "RUNTIME_INPUTS", Path(temp)), \
                patch.object(rig, "identity", return_value=observed), \
                patch.object(rig, "config_identity", return_value={"services": services}), \
                patch.object(rig, "inspect_json", side_effect=inspect), \
                patch.object(rig, "verify_fixture_inputs", return_value={"repositories": dependencies}), \
                patch.object(rig, "compose", return_value=SimpleNamespace(stdout='{}')):
            (Path(temp) / "metadata.json").write_text(json.dumps({"dependencies": dependencies}))
            return rig.acceptance_runtime(), calls

    def test_не_требует_образ_неиспользуемого_browser_profile(self):
        runtime, calls = self.capture()
        self.assertEqual({name: "app-image" for name in ("app", "horizon", "scheduler")}, runtime["images"])
        self.assertNotIn(("image", "playwright:fixture"), calls)

    def test_проверяет_созданный_browser_без_изменения_общей_runtime_identity(self):
        without_browser, _ = self.capture()
        with_browser, calls = self.capture(browser=True)
        self.assertEqual(without_browser, with_browser)
        self.assertIn(("image", "playwright:fixture"), calls)
        self.assertIn(("container", "browser-container"), calls)
        with self.assertRaisesRegex(rig.GuardError, "stale running acceptance image"):
            self.capture(browser=True, wrong_browser=True)

    def test_не_игнорирует_отсутствующий_обязательный_app_image(self):
        with self.assertRaisesRegex(rig.GuardError, "required app image is not cached"):
            self.capture(missing_app=True)


class ComposerRuntimeTest(unittest.TestCase):
    def setUp(self):
        self.module = rig.vendor_module()

    def test_versioned_php_is_used_instead_of_incompatible_default_php(self):
        paths = {"composer": "/tools/composer", "php8.4": "/tools/php8.4", "php": "/tools/php"}
        with patch.dict(os.environ, {"AGELTO_PHP_BINARY": ""}), \
                patch.object(self.module.shutil, "which", side_effect=paths.get), \
                patch.object(self.module.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout="80427")) as run:
            self.assertEqual(self.module.composer_command(), ["/tools/php8.4", "/tools/composer"])
            run.assert_called_once_with(["/tools/php8.4", "-r", "echo PHP_VERSION_ID;"], capture_output=True, text=True)

    def test_explicit_incompatible_php_refuses_fallback(self):
        with patch.dict(os.environ, {"AGELTO_PHP_BINARY": "php8.3"}), \
                patch.object(self.module.shutil, "which", side_effect=lambda name: "/tools/" + name), \
                patch.object(self.module.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout="80336")) as run:
            with self.assertRaisesRegex(ValueError, "PHP >=8.4.1"):
                self.module.composer_command()
            self.assertEqual(run.call_count, 1)

    def test_generic_php_is_accepted_only_when_it_meets_the_lock_minimum(self):
        paths = {"composer": "/tools/composer", "php": "/tools/php"}
        for version in ("80336", "80400", "80401", "80500", "90000"):
            with self.subTest(version=version), patch.dict(os.environ, {"AGELTO_PHP_BINARY": ""}), \
                    patch.object(self.module.shutil, "which", side_effect=paths.get), \
                    patch.object(self.module.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout=version)):
                if version in {"80401", "80500"}:
                    self.assertEqual(self.module.composer_command(), ["/tools/php", "/tools/composer"])
                else:
                    with self.assertRaises(ValueError):
                        self.module.composer_command()

    def test_missing_composer_or_php_refuses_before_composer_checks(self):
        for paths in ({}, {"composer": "/tools/composer"}):
            with patch.dict(os.environ, {"AGELTO_PHP_BINARY": ""}), \
                    patch.object(self.module.shutil, "which", side_effect=paths.get), \
                    patch.object(self.module.subprocess, "run") as run:
                with self.assertRaises(ValueError):
                    self.module.run_composer_checks(rig.ROOT)
                run.assert_not_called()

    def test_both_composer_checks_keep_the_selected_php_and_platform_checks(self):
        with patch.object(self.module, "composer_command", return_value=["/tools/php8.4", "/tools/composer"]), \
                patch.object(self.module.subprocess, "run", return_value=SimpleNamespace(returncode=0)) as run:
            self.module.run_composer_checks(rig.ROOT)
            commands = [call.args[0] for call in run.call_args_list]
            self.assertEqual([c[:3] for c in commands], [["/tools/php8.4", "/tools/composer", "validate"],
                                                        ["/tools/php8.4", "/tools/composer", "install"]])
            self.assertIn("--strict", commands[0])
            self.assertIn("--dry-run", commands[1])
            self.assertFalse(any(flag.startswith("--ignore-platform") for c in commands for flag in c))

    def test_real_composer_checks_run_offline_with_a_compatible_installed_php(self):
        with patch.dict(os.environ, {"AGELTO_PHP_BINARY": "", "COMPOSER_DISABLE_NETWORK": "1"}):
            command = self.module.composer_command()
            self.assertTrue(Path(command[0]).is_file())
            self.module.run_composer_checks(rig.ROOT)


class VendorDeliveryTest(unittest.TestCase):
    """Real local Git/Composer fixtures; no remote enrollment or application DB."""

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "consumer"
        self.root.mkdir()
        scripts = self.root / "docker/scripts"
        scripts.mkdir(parents=True)
        for name in ("vendor_manifest.py", "vendor-sync.sh"):
            shutil.copy2(rig.ROOT / "docker/scripts" / name, scripts / name)
        self.module = rig.vendor_module()
        self.git(self.root, "init", "--template=")
        (self.root / "README.md").write_text("consumer fixture")
        self.commit(self.root)
        self.rows = []
        for key in ("azguard", "proxyma"):
            source = Path(self.temp.name) / key
            source.mkdir()
            self.git(source, "init", "--template=")
            packages = []
            for suffix, folder in (("", "core"), ("-filament", "filament")):
                directory = source / "packages" / folder
                directory.mkdir(parents=True)
                name = "axiomasoft/" + key + suffix
                manifest = {"name": name, "description": "private fixture", "license": "proprietary"}
                if suffix:
                    manifest["require"] = {"axiomasoft/" + key: "self.version"}
                (directory / "composer.json").write_text(json.dumps(manifest) + "\n")
                (directory / "payload.txt").write_text(key + folder)
                packages.append({"name": name, "path": "packages/" + folder,
                                 "version": "0.1.0" if key == "proxyma" else "dev-main"})
            (source / ".env").write_text("must never reach a snapshot")
            (source / "auth.json").write_text("credential fixture")
            self.commit(source)
            row = {"key": key, "repository": {"host": "github.com", "path": self.module.REPOSITORIES[key]},
                   "commit": self.git(source, "rev-parse", "HEAD"), "packages": packages}
            self.rows.append(row)
            checkout = self.root / "local/vendors" / key
            checkout.parent.mkdir(parents=True, exist_ok=True)
            self.git(self.root, "clone", "--no-hardlinks", source, checkout)
            self.git(checkout, "remote", "set-url", "origin", "https://github.com/" + row["repository"]["path"] + ".git")
        self.manifest = self.root / "docker/private-vendors.lock.json"
        self.save()

    def git(self, root, *args):
        result = subprocess.run(["git", "-C", str(root), *map(str, args)], capture_output=True, text=True,
                                env={**os.environ, "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_NOSYSTEM": "1"})
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout.strip()

    def commit(self, root):
        self.git(root, "add", "--", ".")
        self.git(root, "-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", "commit", "-qm", "fixture")

    def save(self, value=None):
        self.manifest.write_text(json.dumps(value or {"version": 2, "repositories": self.rows}))

    def sync(self, *args, succeeds=True):
        result = subprocess.run(["bash", str(self.root / "docker/scripts/vendor-sync.sh"), *args],
                                capture_output=True, text=True,
                                env={**os.environ, "GIT_ALLOW_PROTOCOL": "file", "GIT_TERMINAL_PROMPT": "0"})
        self.assertEqual(result.returncode == 0, succeeds, result.stdout + result.stderr)
        self.assertNotIn("token:fixture-secret", result.stdout + result.stderr)
        return result

    def test_v2_and_v1_azguard_compatibility_install_from_offline_git_cache(self):
        self.sync("--check")
        self.sync()  # The allowed origins cannot be fetched with GIT_ALLOW_PROTOCOL=file.
        azguard = self.rows[0]
        self.save({"version": 1, "repository": azguard["repository"], "commit": azguard["commit"],
                   "packages": [{k: p[k] for k in ("name", "path")} for p in azguard["packages"]]})
        self.sync("--check")
        self.sync()

    def test_all_supported_origins_and_credential_wrong_repo_rejection(self):
        for row in self.rows:
            checkout = self.root / "local/vendors" / row["key"]
            for prefix in ("https://github.com/", "git@github.com:", "ssh://git@github.com/"):
                for suffix in ("", ".git"):
                    self.git(checkout, "remote", "set-url", "origin", prefix + row["repository"]["path"] + suffix)
                    self.sync("--check")
            for origin in ("https://token:fixture-secret@github.com/" + row["repository"]["path"],
                           "https://github.com/other/repo.git", "http://github.com/" + row["repository"]["path"],
                           "git@github-proxyma:" + row["repository"]["path"],
                           "https://github.com:8443/" + row["repository"]["path"]):
                self.git(checkout, "remote", "set-url", "origin", origin)
                self.sync("--check", succeeds=False)
                self.sync(succeeds=False)
            self.git(checkout, "remote", "set-url", "origin", "https://github.com/" + row["repository"]["path"])

    def test_sha_dirty_missing_package_and_symlink_refuse_without_mutation(self):
        checkout = self.root / "local/vendors/proxyma"
        original = self.rows[1]["commit"]
        self.rows[1]["commit"] = "a" * 40
        self.save()
        self.sync("--check", succeeds=False)
        self.assertEqual(self.git(checkout, "rev-parse", "HEAD"), original)
        self.rows[1]["commit"] = original
        self.save()
        for file in ("packages/core/payload.txt", "untracked.txt"):
            target = checkout / file
            before = target.read_bytes() if target.exists() else None
            target.write_text("dirty")
            self.sync("--check", succeeds=False)
            self.sync(succeeds=False)
            self.assertEqual(target.read_text(), "dirty")
            if before is None:
                target.unlink()
            else:
                target.write_bytes(before)
        target = checkout / "packages/core/composer.json"
        raw = target.read_bytes()
        target.unlink()
        self.sync("--check", succeeds=False)
        target.symlink_to(self.root / "README.md")
        self.sync("--check", succeeds=False)
        target.unlink()
        target.write_bytes(raw)
        shutil.rmtree(checkout)
        self.sync("--check", succeeds=False)
        checkout.symlink_to(self.root / "local/vendors/azguard", target_is_directory=True)
        self.sync("--check", succeeds=False)

    def test_strict_manifest_rejects_unknown_repo_traversal_duplicate_and_missing_packages(self):
        valid = json.loads(self.manifest.read_text())
        mutations = [
            lambda v: v.update(version=3),
            lambda v: v.update(version=True),
            lambda v: v.update(extra=True),
            lambda v: v["repositories"][1].update(key="../proxyma"),
            lambda v: v["repositories"][1]["repository"].update(host="evil.invalid"),
            lambda v: v["repositories"][1]["repository"].update(path="axiomasoft/other"),
            lambda v: v["repositories"][1].update(commit="0" * 40),
            lambda v: v["repositories"][1].update(commit="main"),
            lambda v: v["repositories"].append(copy.deepcopy(v["repositories"][0])),
            lambda v: v["repositories"][1]["packages"][0].update(path="packages/../core"),
            lambda v: v["repositories"][1]["packages"][0].update(path="/packages/core"),
            lambda v: v["repositories"][1]["packages"][0].update(name="axiomasoft/azguard"),
            lambda v: v["repositories"][1]["packages"][0].update(version="dev-main"),
            lambda v: v["repositories"][1]["packages"].append(copy.deepcopy(v["repositories"][1]["packages"][0])),
            lambda v: v["repositories"][1]["packages"].pop(),
        ]
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                value = copy.deepcopy(valid)
                mutation(value)
                self.save(value)
                self.sync("--check", succeeds=False)
        self.manifest.write_text("{broken")
        self.sync("--check", succeeds=False)

    def test_проверяет_semver_пакетов_proxyma(self):
        for version, valid in (("0.2.0", True), ("1.2.3-rc.1+build.7", True),
                               ("01.2.3", False), ("1.2.3-01", False), ("^1.2", False)):
            with self.subTest(version=version):
                value = {"version": 2, "repositories": copy.deepcopy(self.rows)}
                for package in value["repositories"][1]["packages"]:
                    package["version"] = version
                self.save(value)
                self.sync("--check", succeeds=valid)

        value["repositories"][1]["packages"][0]["version"] = "1.2.3"
        value["repositories"][1]["packages"][1]["version"] = "1.2.4"
        self.save(value)
        self.sync("--check", succeeds=False)

    def test_composer_offline_copies_both_repositories_and_rejects_forged_lock(self):
        composer = shutil.which("composer")
        self.assertIsNotNone(composer, "Composer is required for the offline installation contract")
        repositories, requirements = [], {}
        for row in self.rows:
            for package in row["packages"]:
                repositories.append({"type": "path", "url": "local/vendors/" + row["key"] + "/" + package["path"],
                                     "options": {"symlink": False, "reference": "config", "versions": {package["name"]: package["version"]}}})
                requirements[package["name"]] = package["version"]
        (self.root / "composer.json").write_text(json.dumps({"name": "fixture/consumer", "license": "proprietary",
                                                            "repositories": repositories, "require": requirements}))
        env = {**os.environ, "COMPOSER_DISABLE_NETWORK": "1", "COMPOSER_HOME": str(self.root / "composer-home")}
        for argv in ([composer, "update", "--no-install", "--no-audit"], [composer, "install", "--no-dev"]):
            result = subprocess.run([*argv, "--no-scripts", "--no-plugins", "--no-interaction", "--no-progress"],
                                    cwd=self.root, env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
        self.module.composer_contract(self.root, self.rows)
        for row in self.rows:
            for package in row["packages"]:
                installed = self.root / "vendor" / package["name"]
                self.assertFalse(installed.is_symlink())
                self.assertEqual((installed / "payload.txt").read_bytes(),
                                 (self.root / "local/vendors" / row["key"] / package["path"] / "payload.txt").read_bytes())
        lockfile = self.root / "composer.lock"
        original = json.loads(lockfile.read_text())
        for mutate in (lambda p: p["dist"].update(reference="0" * 40),
                       lambda p: p["dist"].update(url="wrong/path"),
                       lambda p: p.update(version="0.2.0"),
                       lambda p: p["transport-options"].update(symlink=True)):
            value = copy.deepcopy(original)
            mutate(next(p for p in value["packages"] if p["name"] == "axiomasoft/proxyma"))
            lockfile.write_text(json.dumps(value))
            with self.assertRaises(ValueError):
                self.module.composer_contract(self.root, self.rows)

    def test_snapshots_bind_both_source_hashes_reject_missing_stale_dirty_and_credentials(self):
        manifest = self.root / "tests/Fixtures/ProcessCore/runtime-inputs.json"
        fixtures = [copy_synthetic_gsfx(self.root)]
        repositories = {r["key"]: {"commit": r["commit"], "packages": self.module.package_hashes(
                        self.module.package_files(self.root / "local/vendors" / r["key"], r), r)} for r in self.rows}
        manifest.write_text(json.dumps({"files": fixtures, "repositories": repositories}))
        runtime = self.root / "docker/tests/process-core/runtime-inputs"
        runtime.parent.mkdir(parents=True)
        report = self.root / "report"
        report.mkdir()
        with patch.object(rig, "ROOT", self.root), patch.object(rig, "RUNTIME_INPUTS", runtime):
            proof = rig.prepare_metadata(report)
            self.assertEqual(proof["dependencies"], repositories)
            self.assertFalse((runtime / "proxyma/.env").exists())
            self.assertFalse((runtime / "proxyma/auth.json").exists())
            self.assertFalse((runtime / "proxyma/.git/hooks").exists())
            rig.prepare_metadata(report)  # Exact snapshots are reusable.
            for target in ("proxyma/packages/core/payload.txt", "proxyma/auth.json", "proxyma/packages/core/.env"):
                file = runtime / target
                before = file.read_bytes() if file.exists() else None
                file.write_text("tampered")
                with self.assertRaises(rig.GuardError):
                    rig.prepare_metadata(report)
                if before is None:
                    file.unlink()
                else:
                    file.write_bytes(before)
            file = runtime / "proxyma/packages/filament/composer.json"
            raw = file.read_bytes()
            file.unlink()
            with self.assertRaises(rig.GuardError):
                rig.prepare_metadata(report)
            file.write_bytes(raw)
            repositories["proxyma"]["commit"] = "a" * 40
            manifest.write_text(json.dumps({"files": fixtures, "repositories": repositories}))
            with self.assertRaisesRegex(rig.GuardError, "SHA/hashes"):
                rig.prepare_metadata(report)
            repositories["proxyma"]["commit"] = self.rows[1]["commit"]
            manifest.write_text(json.dumps({"files": fixtures, "repositories": repositories}))
            metadata = runtime / "metadata.json"
            metadata.write_text("{}")
            with self.assertRaisesRegex(rig.GuardError, "stale"):
                rig.prepare_metadata(report)
            metadata.write_text(json.dumps(proof))
            self.git(runtime / "proxyma", "config", "credential.helper", "fixture-helper")
            with self.assertRaisesRegex(rig.GuardError, "configuration"):
                rig.prepare_metadata(report)
            self.git(runtime / "proxyma", "config", "--unset", "credential.helper")
            shutil.rmtree(runtime / "proxyma")
            with self.assertRaisesRegex(rig.GuardError, "snapshot"):
                rig.prepare_metadata(report)

    def test_snapshot_sources_reject_credentials_and_symlinks_inside_package_payload(self):
        checkout = self.root / "local/vendors/proxyma"
        secret = checkout / "packages/core/.env"
        secret.write_text("fixture secret")
        self.commit(checkout)
        row = {**self.rows[1], "commit": self.git(checkout, "rev-parse", "HEAD")}
        with self.assertRaisesRegex(ValueError, "credential"):
            self.module.package_files(checkout, row)
        secret.unlink()
        secret.symlink_to("composer.json")
        self.commit(checkout)
        row["commit"] = self.git(checkout, "rev-parse", "HEAD")
        with self.assertRaisesRegex(ValueError, "symlink"):
            self.module.package_files(checkout, row)

    def test_snapshots_preserve_raw_pinned_blobs_with_git_export_attributes(self):
        checkout = self.root / "local/vendors/proxyma"
        package = checkout / "packages/core"
        (package / ".gitattributes").write_text(".gitattributes export-ignore\napi-manifest.json export-ignore\npayload.txt export-subst\n")
        (package / "api-manifest.json").write_text('{"source": "pinned metadata"}')
        (package / "payload.txt").write_text("$Format:%H$\n")
        self.commit(checkout)
        self.rows[1]["commit"] = self.git(checkout, "rev-parse", "HEAD")
        self.save()
        files = self.module.package_files(checkout, self.rows[1])
        self.assertEqual(files["packages/core/api-manifest.json"], (package / "api-manifest.json").read_bytes())
        self.assertEqual(files["packages/core/.gitattributes"], (package / ".gitattributes").read_bytes())
        self.assertEqual(files["packages/core/payload.txt"], b"$Format:%H$\n")
        manifest = self.root / "tests/Fixtures/ProcessCore/runtime-inputs.json"
        repositories = {r["key"]: {"commit": r["commit"], "packages": self.module.package_hashes(
                        self.module.package_files(self.root / "local/vendors" / r["key"], r), r)} for r in self.rows}
        manifest.write_text(json.dumps({"files": [copy_synthetic_gsfx(self.root)], "repositories": repositories}))
        runtime = self.root / "docker/tests/process-core/runtime-inputs"
        runtime.parent.mkdir(parents=True)
        report = self.root / "report"
        report.mkdir()
        with patch.object(rig, "ROOT", self.root), patch.object(rig, "RUNTIME_INPUTS", runtime):
            proof = rig.prepare_metadata(report)
            self.assertEqual(proof["dependencies"], repositories)
            self.assertEqual((runtime / "proxyma/packages/core/api-manifest.json").read_bytes(),
                             (package / "api-manifest.json").read_bytes())

    def test_deploy_contract_uses_fake_git_and_ssh_for_three_separate_readonly_aliases(self):
        result = subprocess.run(["bash", str(rig.ROOT / "docker/tests/deploy-repositories-contract.sh")],
                                cwd=rig.ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_build_context_excludes_credentials_git_env_and_unrelated_vendor_sources(self):
        context = self.root / "context"
        context.mkdir()
        shutil.copy2(rig.ROOT / ".dockerignore", context / ".dockerignore")
        for key in ("azguard", "proxyma"):
            for folder in ("core", "filament"):
                package = context / "local/vendors" / key / "packages" / folder
                package.mkdir(parents=True)
                for name in ("composer.json", "payload.php", ".env", "auth.json", "private.key"):
                    (package / name).write_text("fixture")
                (package / ".git").mkdir()
                (package / ".git/config").write_text("credential fixture")
            (context / "local/vendors" / key / "auth.json").write_text("fixture")
            (context / "local/vendors" / key / "unrelated.txt").write_text("fixture")
        (context / "Dockerfile").write_text("FROM scratch\nCOPY . /\n")
        output = self.root / "export"
        result = subprocess.run(["docker", "build", "--output=type=local,dest=" + str(output), str(context)],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        for key in ("azguard", "proxyma"):
            for folder in ("core", "filament"):
                package = output / "local/vendors" / key / "packages" / folder
                self.assertTrue((package / "composer.json").is_file())
                self.assertTrue((package / "payload.php").is_file())
                self.assertEqual(sorted(p.name for p in package.iterdir()), ["composer.json", "payload.php"])
            self.assertFalse((output / "local/vendors" / key / "unrelated.txt").exists())
            self.assertFalse((output / "local/vendors" / key / "auth.json").exists())


if __name__ == "__main__":
    unittest.main()
