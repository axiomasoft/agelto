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
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("rig", Path(__file__).with_name("rig.py"))
rig = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rig)


class RigGuardsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.config = rig.config_identity()  # compose config only; no containers or DB commands.

    def test_positive_resolved_compose_identity(self):
        self.assertEqual(rig.validate_config(self.config)["name"], "agelto_core_test")

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
            manifest.parent.mkdir(parents=True)
            original = root / "original.json"
            original.write_text('{"measured": 42}')
            manifest.write_text(json.dumps({"files": [{"source": "original.json",
                                  "sha256": rig.hashlib.sha256(original.read_bytes()).hexdigest()}]}))
            rig.verify_fixture_inputs()
            original.write_text('{"measured": 0}')
            with self.assertRaisesRegex(rig.GuardError, "required runtime input"):
                rig.verify_fixture_inputs()
            original.unlink()
            with self.assertRaisesRegex(rig.GuardError, "required runtime input"):
                rig.verify_fixture_inputs()

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

    def test_future_suite_is_nonzero_without_runtime_commands(self):
        for name in rig.FUTURE:
            with self.subTest(suite=name), patch.object(rig, "compose") as compose, \
                    patch("sys.stdout", new=io.StringIO()) as out:
                self.assertEqual(rig.main("core-test-" + name), 78)
                self.assertIn("suite_not_implemented", out.getvalue())
                compose.assert_not_called()

    def test_execution_suite_runs_only_owned_paths_and_rejects_empty_or_skipped_selection(self):
        for name in ("execution", "network", "platforms", "acquisition"):
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
                patch.object(rig, "ROOT", Path(temp).parent), \
                patch.object(rig, "new_report", return_value=(Path(temp), {"command": "core-test-concurrency"})), \
                patch.object(rig, "install_clock", return_value={"epoch": 1}), \
                patch.object(rig, "reset", side_effect=lambda folder, clock: calls.append(("reset", folder.name))), \
                patch.object(rig, "suite", side_effect=lambda folder, name: calls.append(("suite", name)) or {"executed": 1, "exit": 0}), \
                patch.object(rig, "write_json"), patch("sys.stdout", new=io.StringIO()):
            self.assertEqual(rig.execute("core-test-concurrency", "concurrency"), 0)
        self.assertEqual(calls, [("reset", "clean-reset"), ("suite", "concurrency")])

    def test_network_suite_runs_initial_machinery_after_guarded_clean_reset(self):
        self.assertIn("network", rig.IMPLEMENTED)
        self.assertEqual(rig.FUTURE, {"documents", "ai", "e2e", "faults", "load"})
        self.assertEqual(rig.SUITE_PATHS["network"], [
            "tests/Feature/ProcessCore/Network",
            "tests/Integration/ProcessCore/Concurrency/T45CatalogConcurrencyTest.php",
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
        with tempfile.TemporaryDirectory() as temp, patch.object(rig, "REPORTS", Path(temp)):
            folder = Path(temp) / "report"
            folder.mkdir()
            inputs = {"product.php": "hash"}
            (folder / "inputs.json").write_text(json.dumps(inputs))
            commands = ["core-test-up", "core-test-env", "core-test-install", "core-test-unit",
                        "core-test-regression", "core-quality"]
            for command in commands:
                gate = Path(temp) / command
                gate.mkdir()
                (gate / "result.json").write_text(json.dumps({"command": command, "exit": 0,
                    "candidate": {"input_tree_sha256": rig.digest(inputs)}}))
            with self.assertRaisesRegex(rig.GuardError, "missing regression scenario-comparison"):
                rig.collect(folder)
            regression = Path(temp) / "core-test-regression"
            comparison = regression / "scenario-comparison.json"
            comparison.write_text('{"preserved_cases": 2014}')
            with self.assertRaisesRegex(rig.GuardError, "comparison hash mismatch"):
                rig.collect(folder)
            result_path = regression / "result.json"
            result = json.loads(result_path.read_text())
            result["tests"] = {"scenario_comparison_sha256": rig.hashlib.sha256(comparison.read_bytes()).hexdigest()}
            result_path.write_text(json.dumps(result))
            rig.collect(folder)
            self.assertTrue((folder / "summary.json").is_file())
            comparison.write_text('{"preserved_cases": 0}')
            with self.assertRaisesRegex(rig.GuardError, "comparison hash mismatch"):
                rig.collect(folder)


if __name__ == "__main__":
    unittest.main()
