#!/usr/bin/env python3
"""Fixed-target, fail-closed process-core rig. No development Compose fallback."""
import datetime
from collections import Counter
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[3]
PROJECT = "agelto_core_test"
COMPOSE_FILE = ROOT / "docker/compose.core-test.yml"
ENV_FILE = ROOT / "docker/tests/process-core/rig.env"
REPORTS = ROOT / "storage/app/testing/process-core/reports"
SERVICES = {"app", "core-postgres", "core-redis", "object-storage", "fixture-http",
            "fixture-proxy", "fake-gemini", "horizon", "scheduler", "browser"}
VOLUMES = {"core-postgres", "core-redis", "core-objects", "core-storage", "core-bootstrap", "core-pest",
           "core-prompts", "core-estimate-prompts"}
RUNTIME_INPUTS = ROOT / "docker/tests/process-core/runtime-inputs"
FUTURE = {"platforms", "acquisition",
          "documents", "ai", "e2e", "faults", "load"}
IMPLEMENTED = {"up", "env", "reset", "install", "unit", "execution", "concurrency", "observability", "network", "regression", "report", "down"}
# Domain suites run only the files their owning package filled with real assertions. execution:
# P1.6 ledger/authority + P1.7 admission/claim/controls + P1.8 lease/checkpoint/finalize on real PG.
# concurrency: only separate PHP processes/PG connections with deterministic barriers (P1.7 pause/
# feeder races, P1.8 finalize fence vs controlled revocation, fault budget); real reconciler — P1.11.
# network: P2.1 INITIAL contracts/catalog/owner DDL only, plus real PG catalog races.
# Transport, actual-send permits and health reducers are supplied by their later owning points.
SUITE_PATHS = {"execution": ["tests/Feature/ProcessCore/Execution", "tests/Integration/ProcessCore/Concurrency"],
               "concurrency": ["tests/Integration/ProcessCore/Concurrency"],
               "observability": ["tests/Feature/ProcessCore/Observability"],
               "network": ["tests/Feature/ProcessCore/Network",
                           "tests/Integration/ProcessCore/Concurrency/T45CatalogConcurrencyTest.php"]}


class GuardError(RuntimeError):
    pass


def require(condition, message):
    if not condition:
        raise GuardError(message)


def child_env():
    # Compose interpolation must not use a caller's project, profiles, credentials or .env.
    return {k: v for k, v in os.environ.items()
            if k in {"PATH", "HOME", "DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_CONFIG",
                     "XDG_RUNTIME_DIR", "SSH_AUTH_SOCK"}}


def run(argv, *, data=None, check=True):
    result = subprocess.run([str(a) for a in argv], cwd=ROOT, env=child_env(),
                            input=data, capture_output=True, text=True)
    if check and result.returncode:
        # Logs belong to the run report, not an unbounded terminal transcript.
        raise GuardError(f"command failed ({result.returncode}): {argv[0:4]} ... {argv[-3:]}\n"
                         + (result.stderr + "\n" + result.stdout)[-3000:])
    return result


def compose(*args, **kwargs):
    return run(["docker", "compose", "--project-directory", COMPOSE_FILE.parent,
                "--project-name", PROJECT, "--env-file", ENV_FILE,
                "-f", COMPOSE_FILE, *args], **kwargs)


def validate_config(config):
    require(config.get("name") == PROJECT, "wrong Compose project")
    require(set(config.get("services", {})) == SERVICES, "unexpected Compose services")
    networks = config.get("networks", {})
    require(set(networks) == {"core"} and networks["core"].get("internal") is True
            and not networks["core"].get("external"), "network must deny external egress")
    volumes = config.get("volumes", {})
    require(set(volumes) == VOLUMES, "unexpected volumes")
    for name, volume in volumes.items():
        require(not volume.get("external") and not volume.get("driver_opts")
                and volume.get("name") == f"{PROJECT}_{name}", "foreign volume: " + name)
    for name, service in config["services"].items():
        require(set(service.get("networks", {})) == {"core"}, "foreign network: " + name)
        require(not any(service.get(k) for k in ("ports", "network_mode", "privileged",
                    "secrets", "env_file", "volumes_from", "devices", "cap_add")),
                "unsafe container capability: " + name)
        for mount in service.get("volumes", []):
            require(mount["type"] in {"bind", "volume"}, "unsupported mount")
            if mount["type"] == "volume":
                require(mount["source"] in VOLUMES, "foreign volume mount")
            else:
                source = Path(mount["source"]).resolve()
                require(source.is_relative_to(ROOT) and source != ROOT
                        and mount.get("read_only") is True, "unsafe source bind")
                allowed_examples = {ROOT / name for name in (".env.example", ".env.docker.example", ".env.docker.production.example")}
                require(not any((part.startswith(".env") and source not in allowed_examples)
                                or part in {"local", "secrets", "storage"}
                                for part in source.relative_to(ROOT).parts), "credential/runtime bind")
        env = service.get("environment", {})
        if name in {"app", "horizon", "scheduler"}:
            required = {"APP_ENV": "testing", "PROCESS_CORE_TEST": "1", "DB_HOST": "core-postgres",
                        "DB_DATABASE": "agelto_test", "DB_USERNAME": "core_test", "DB_URL": "",
                        "REDIS_HOST": "core-redis", "REDIS_PREFIX": "agelto_core_test:",
                        "HORIZON_PREFIX": "agelto_core_test:horizon:"}
            require(all(str(env.get(k)) == v for k, v in required.items()),
                    "unsafe resolved application identity: " + name)
            require(not any(k.endswith("_FILE") for k in env), "external secret reference")
    return config


def config_identity():
    return validate_config(json.loads(compose("--profile", "workers", "--profile", "browser",
                                              "config", "--format", "json").stdout))


def inspect_json(kind, identity):
    return json.loads(run(["docker", kind, "inspect", identity]).stdout)[0]


def container_identity(config, service, container):
    labels = container.get("Config", {}).get("Labels", {}) or {}
    require(labels.get("com.docker.compose.project") == PROJECT
            and labels.get("com.docker.compose.service") == service, "foreign container labels")
    require(container.get("Name") == f"/{PROJECT}-{service}-1", "wrong exact container identity")
    require(container["HostConfig"].get("NetworkMode") != "host"
            and not container["HostConfig"].get("Privileged")
            and not container["HostConfig"].get("PortBindings")
            and not container["HostConfig"].get("CapAdd"), "unsafe runtime container")
    expected = {mount["target"]: mount for mount in config["services"][service].get("volumes", [])}
    actual = {mount["Destination"]: mount for mount in container.get("Mounts", [])}
    require(set(actual) == set(expected), "unexpected runtime mounts: " + service)
    for target, mount in actual.items():
        declared = expected[target]
        require(mount["Type"] == declared["type"], "mount type differs")
        if mount["Type"] == "volume":
            require(mount["Name"] == f"{PROJECT}_{declared['source']}", "foreign runtime volume")
        else:
            require(Path(mount["Source"]).resolve() == Path(declared["source"]).resolve()
                    and not mount["RW"], "runtime bind differs")
    require(set(container["NetworkSettings"]["Networks"]) == {f"{PROJECT}_core"},
            "container connected to a foreign network")
    actual_env = dict(pair.split("=", 1) for pair in container["Config"].get("Env", []) if "=" in pair)
    for key, value in config["services"][service].get("environment", {}).items():
        require(actual_env.get(key) == str(value), "runtime environment differs: " + key)


def identity(*, allow_empty=False):
    config = config_identity()
    ids = compose("--profile", "workers", "--profile", "browser", "ps", "--all", "--quiet").stdout.split()
    require(ids or allow_empty, "isolated project is not running; make core-test-up")
    found = {}
    running_ids = set()
    for cid in ids:
        container = inspect_json("container", cid)
        service = container["Config"]["Labels"].get("com.docker.compose.service")
        require(service in SERVICES, "unknown project container")
        container_identity(config, service, container)
        found[service] = {"id": cid, "state": container["State"]["Status"]}
        if container["State"]["Running"]:
            running_ids.add(cid)
    for volume in VOLUMES:
        volume_name = f"{PROJECT}_{volume}"
        probe = run(["docker", "volume", "inspect", volume_name], check=False)
        if probe.returncode == 0:
            item = json.loads(probe.stdout)[0]
            require((item.get("Labels") or {}).get("com.docker.compose.project") == PROJECT
                    and not item.get("Options") and item.get("Driver") == "local",
                    "foreign volume identity: " + volume)
    network_probe = run(["docker", "network", "inspect", f"{PROJECT}_core"], check=False)
    if network_probe.returncode == 0:
        network = json.loads(network_probe.stdout)[0]
        require(network["Internal"] is True
                and network["Driver"] == "bridge"
                and (network.get("Labels") or {}).get("com.docker.compose.project") == PROJECT,
                "unsafe runtime network")
        # Docker disconnects stopped workers, but compose ps --all still lists them.
        # Every expected endpoint must belong to a running, fully validated container.
        require(set(network.get("Containers", {})) == running_ids, "foreign network member")
    else:
        require(not ids and allow_empty, "missing isolated network")
    return {"project": PROJECT, "services": found, "config_sha256": digest(config)}


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def target_guard(proof, database="agelto_test", token=None):
    require(proof.get("project") == PROJECT, "reset: wrong project")
    expected = "agelto_test" if token is None else f"agelto_test_test_{token}"
    require(token is None or re.fullmatch(r"[A-Za-z0-9_-]+", token), "reset: invalid parallel token")
    require(database == expected, "reset: wrong exact database/token")
    require(proof.get("database") == database and proof.get("user") == "core_test"
            and proof.get("port") == 5432 and proof.get("server_version", "").startswith("17."),
            "reset: wrong connected database identity")
    require(proof.get("environment") == "testing", "reset: not testing")


def pg(sql, database="postgres"):
    return compose("exec", "-T", "core-postgres", "psql", "-X", "-v", "ON_ERROR_STOP=1",
                   "-U", "core_test", "-d", database, "-Atq", data=sql).stdout.strip()


def destructive_guard():
    result = identity()
    required = {"app", "core-postgres", "core-redis", "object-storage"}
    require(required.issubset(result["services"]), "rig incomplete")
    require(all(result["services"][service]["state"] == "running" for service in required),
            "reset requires running application/data services before any mutation")
    # Maintenance identity works even before the first application migration.
    db = json.loads(pg("SELECT json_build_object('database', 'agelto_test', 'user', current_user, "
                       "'port', current_setting('port')::int, 'server_version', current_setting('server_version'), "
                       "'exists', EXISTS(SELECT 1 FROM pg_database WHERE datname='agelto_test'));"))
    db.update(project=PROJECT, environment="testing")
    target_guard(db)
    if db["exists"]:
        connected = json.loads(pg("SELECT json_build_object('database', current_database(), "
                                 "'user', current_user, 'port', current_setting('port')::int, "
                                 "'server_version', current_setting('server_version'));", "agelto_test"))
        target_guard({**connected, "project": PROJECT, "environment": "testing"})
    return result


def app(*args, check=True):
    return compose("exec", "-T", "app", "php", "tests/Support/ProcessCore/artisan.php", *args, check=check)


def readiness():
    identity()
    require(pg("SELECT current_database();", "agelto_test") == "agelto_test", "test DB not ready")
    pending = app("migrate:status", "--no-interaction").stdout
    require("Pending" not in pending, "pending migrations")
    require(compose("exec", "-T", "core-redis", "redis-cli", "ping").stdout.strip() == "PONG", "Redis unavailable")


def prepare_reports():
    for path in [REPORTS, *REPORTS.parents]:
        if path == ROOT:
            break
        require(not path.is_symlink(), "report root contains a symlink")
    REPORTS.mkdir(parents=True, exist_ok=True)
    require(REPORTS.resolve().is_relative_to(ROOT / "storage"), "report root escapes storage")


@contextmanager
def project_lock():
    prepare_reports()
    descriptor = os.open(REPORTS / ".project.lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, "a") as handle:
        # Hold one host lock across identity, reset, test execution and report capture.
        # Independent make processes must never reset the same DB concurrently.
        fcntl.flock(handle, fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(handle, fcntl.LOCK_UN)


def new_report(command):
    run_id = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%S.%fZ") + f"-{os.getpid()}"
    prepare_reports()
    folder = REPORTS / run_id
    folder.mkdir()
    candidate = {"head": run(["git", "rev-parse", "HEAD"]).stdout.strip(),
                 "dirty_digest": hashlib.sha256(run(["git", "diff", "--binary", "HEAD"]).stdout.encode()).hexdigest()}
    # Include untracked product inputs; git diff alone is not a candidate fingerprint.
    inputs = {}
    for name in run(["git", "ls-files", "--cached", "--others", "--exclude-standard"]).stdout.splitlines():
        if name.startswith(("plans/", ".swissknifeman/")):
            continue
        path = ROOT / name
        if path.is_symlink():
            inputs[name] = hashlib.sha256(os.readlink(path).encode()).hexdigest()
        elif path.is_file():
            inputs[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    candidate["input_tree_sha256"] = digest(inputs)
    (folder / "inputs.json").write_text(json.dumps(inputs, sort_keys=True, indent=2) + "\n")
    return folder, {"format": "process-core-report/v1", "command": command, "candidate": candidate,
                    "fixture_sha256": hashlib.sha256((ROOT / "tests/Fixtures/ProcessCore/manifest.json").read_bytes()).hexdigest(),
                    "utc": datetime.datetime.now(datetime.timezone.utc).isoformat()}


def write_json(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n")


def verify_fixture_inputs():
    manifest = json.loads((ROOT / "tests/Fixtures/ProcessCore/runtime-inputs.json").read_text())
    for entry in manifest["files"]:
        path = ROOT / entry["source"]
        require(path.is_file() and not path.is_symlink()
                and hashlib.sha256(path.read_bytes()).hexdigest() == entry["sha256"],
                "missing or changed required runtime input: " + entry["source"])
    return manifest


def fixture_proof(folder):
    manifest = run([sys.executable, "-B", "docker/tests/process-core/fixture_manifest.py", ROOT], check=False)
    (folder / "fixture-manifest.log").write_text(manifest.stdout + manifest.stderr)
    require(manifest.returncode == 0, "fixture manifest invalid; see fixture-manifest.log")
    inputs = json.loads(manifest.stdout)
    tests = run([sys.executable, "-B", "docker/tests/process-core/test_fixtures.py"], check=False)
    (folder / "fixture-tests.log").write_text(tests.stdout + tests.stderr)
    require(tests.returncode == 0, "local fixture assertions failed; see fixture-tests.log")
    result = json.loads(tests.stdout)
    require(type(result.get("executed")) is int and result["executed"] > 0
            and result.get("skipped") == 0, "empty/skipped local fixture assertions")
    proof = {**inputs, **result}
    write_json(folder / "fixture-proof.json", proof)
    return proof


def prepare_metadata(folder):
    """Export real shallow Git objects; never copy host config, hooks or credentials."""
    verify_fixture_inputs()
    manifest = json.loads((ROOT / "docker/private-vendors.lock.json").read_text())
    checkout = ROOT / "local/vendors/azguard"
    # This is the real read-only check, never vendor sync or a diagnostic fake.
    run(["bash", ROOT / "docker/scripts/vendor-sync.sh", "--check"])
    head = run(["git", "rev-parse", "HEAD"]).stdout.strip()
    expected = {"candidate_head": head, "dependency_commit": manifest["commit"],
                "dependency_tree": run(["git", "-C", checkout, "rev-parse", manifest["commit"] + "^{tree}"]).stdout.strip()}
    require(not RUNTIME_INPUTS.is_symlink(), "runtime metadata root is a symlink")
    RUNTIME_INPUTS.mkdir(exist_ok=True)
    metadata = RUNTIME_INPUTS / "metadata.json"
    if metadata.exists():
        require(json.loads(metadata.read_text()) == expected,
                "stale runtime Git snapshot; remove it only after stopping the isolated runtime")
    else:
        # An incomplete export is rejected on the next call, rather than silently reused.
        candidate = RUNTIME_INPUTS / "candidate.git"
        vendor = RUNTIME_INPUTS / "azguard"
        require(not candidate.exists() and not vendor.exists(), "incomplete runtime Git snapshot")
        run(["git", "init", "--bare", "--template=", candidate])
        run(["git", "--git-dir", candidate, "fetch", "--no-tags", "--depth=1", "--no-write-fetch-head",
             ROOT, head + ":refs/heads/candidate"])
        run(["git", "--git-dir", candidate, "symbolic-ref", "HEAD", "refs/heads/candidate"])
        run(["git", "init", "--template=", vendor])
        run(["git", "-C", vendor, "fetch", "--no-tags", "--depth=1", "--no-write-fetch-head",
             checkout, manifest["commit"] + ":refs/heads/pinned"])
        run(["git", "-C", vendor, "sparse-checkout", "set", "--no-cone",
             *["/" + package["path"] + "/" for package in manifest["packages"]]])
        run(["git", "-C", vendor, "checkout", "--detach", manifest["commit"]])
        repository = manifest["repository"]
        run(["git", "-C", vendor, "config", "remote.origin.url",
             f"https://{repository['host']}/{repository['path']}.git"])
        write_json(metadata, expected)
    require(run(["git", "--git-dir", RUNTIME_INPUTS / "candidate.git", "rev-parse", "HEAD"]).stdout.strip() == head,
            "candidate Git anchor differs")
    require(run(["git", "-C", RUNTIME_INPUTS / "azguard", "rev-parse", "HEAD"]).stdout.strip() == manifest["commit"],
            "dependency Git anchor differs")
    require(not run(["git", "-C", RUNTIME_INPUTS / "azguard", "status", "--porcelain", "--untracked-files=all",
                     "--", *[package["path"] for package in manifest["packages"]]]).stdout.strip(),
            "exported dependency package differs")
    write_json(folder / "runtime-metadata.json", expected)
    return expected


def prepare_runtime():
    return compose("exec", "-T", "app", "php", "tests/Support/ProcessCore/prepare-runtime.php")


def snapshot(folder):
    schema = compose("exec", "-T", "core-postgres", "pg_dump", "-U", "core_test", "-d", "agelto_test",
                     "--schema-only", "--no-owner", "--no-privileges").stdout
    # PG17's random psql restrict key changes on every pg_dump; no schema content is dropped.
    normalized = "\n".join(line for line in schema.splitlines()
                           if not line.startswith(("\\restrict ", "\\unrestrict ", "-- Dumped ")))
    (folder / "schema.sql").write_text(normalized + "\n")
    catalog = json.loads(pg((ROOT / "tests/Support/ProcessCore/catalog.sql").read_text(), "agelto_test"))
    write_json(folder / "catalog.json", catalog)
    normalized_catalog = normalize_catalog(catalog)
    write_json(folder / "normalized-catalog.json", normalized_catalog)
    inventory = compose("exec", "-T", "app", "php", "tests/Support/ProcessCore/verify-schema.php",
                        "--catalog-stdin", data=json.dumps({"catalog": catalog, "schema": normalized + "\n"}),
                        check=False)
    (folder / "schema-inventory.json").write_text(inventory.stdout + inventory.stderr)
    require(inventory.returncode == 0, "schema inventory mismatch; see schema-inventory.json")
    # All seed rows (including empty tables), stable JSON order, no timestamp/UUID stripping.
    tables = pg("SELECT quote_ident(schemaname)||'.'||quote_ident(tablename) FROM pg_tables t "
                "WHERE schemaname='public' AND NOT EXISTS (SELECT 1 FROM pg_inherits i "
                "WHERE i.inhrelid=(quote_ident(t.schemaname)||'.'||quote_ident(t.tablename))::regclass) "
                "ORDER BY 1;", "agelto_test").splitlines()
    seeds = {table: json.loads(pg(f"SELECT coalesce(json_agg(row_data ORDER BY row_data::text), '[]'::json) "
                                  f"FROM (SELECT row_to_json(t) AS row_data FROM {table} t) s;", "agelto_test"))
             for table in tables}
    write_json(folder / "seeds.json", seeds)
    return {"schema": hashlib.sha256(normalized.encode()).hexdigest(), "catalog": digest(normalized_catalog), "seeds": digest(seeds)}


def normalize_catalog(catalog):
    # Keep the raw catalog too. This operational timestamp is not schema/seed state.
    return {**catalog, "partman": [{k: v for k, v in row.items() if k != "maintenance_last_run"}
                                  for row in catalog["partman"]]}


def stop_workers():
    destructive_guard()
    compose("--profile", "workers", "stop", "horizon", "scheduler")


def clock_instant(value):
    require(isinstance(value, str) and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", value),
            "install clock: invalid UTC timestamp")
    return datetime.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc)


def install_clock():
    # Connectivity/identity first; even clock reads must stay inside the owned rig.
    proof = destructive_guard()
    postgres = json.loads(pg("SELECT json_build_object('utc', "
                             "to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), "
                             "'timezone', current_setting('TimeZone'));"))
    runtime = compose("exec", "-T", "app", "php", "-r", "echo gmdate('Y-m-d\\TH:i:s\\Z');").stdout.strip()
    anchor = clock_instant(postgres["utc"])
    native = clock_instant(runtime)
    require(postgres["timezone"] in {"UTC", "Etc/UTC"}, "install clock: PostgreSQL timezone must be UTC")
    require(abs((anchor - native).total_seconds()) <= 60 and anchor.strftime("%Y-%m") == native.strftime("%Y-%m"),
            "install clock: PostgreSQL/runtime clocks differ")
    return {"anchor_utc": postgres["utc"], "runtime_utc": runtime, "postgres_timezone": postgres["timezone"],
            "source": "guarded core-postgres clock_timestamp; native app PHP gmdate", "identity": proof}


def reset(folder, *, clock=None):
    observed = install_clock()
    clock = observed if clock is None else clock
    require(clock_instant(clock["anchor_utc"]).strftime("%Y-%m")
            == clock_instant(observed["anchor_utc"]).strftime("%Y-%m"),
            "install clock: pass crossed a UTC month; start a new pass")
    write_json(folder / "install-clock.json", {"pass": clock, "observed_before_drop": observed})
    proof = observed["identity"]
    write_json(folder / "identity-before-drop.json", proof)
    stop_workers()
    compose("exec", "-T", "app", "php", "tests/Support/ProcessCore/clean-runtime.php")
    # Full database recreation also removes pg_partman metadata outside public.
    pg("SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
       "WHERE datname='agelto_test' AND pid <> pg_backend_pid();\n"
       "DROP DATABASE IF EXISTS agelto_test;\nCREATE DATABASE agelto_test OWNER core_test;")
    for command in [("migrate", "--force", "--no-interaction"),
                    ("db:seed", "--class=Tests\\Support\\ProcessCore\\MinimalSeeder", "--force", "--no-interaction")]:
        result = compose("exec", "-T", "-e", "PROCESS_CORE_INSTALL=1",
                         "-e", "PROCESS_CORE_INSTALL_AT=" + clock["anchor_utc"], "app", "php",
                         "tests/Support/ProcessCore/artisan.php", *command, check=False)
        (folder / (command[0].replace(":", "-") + ".log")).write_text(result.stdout + result.stderr)
        require(result.returncode == 0, f"{command[0]} failed; see run log")
    # This entire Redis instance belongs to the verified test project, including Horizon.
    compose("exec", "-T", "core-redis", "redis-cli", "FLUSHALL")
    # Object storage is wholly test-owned, but reset only the two declared fixture buckets.
    compose("exec", "-T", "app", "mc", "alias", "set", "core-test", "http://object-storage:9000",
            "core_test", "core-test-only")
    for bucket in ("core-test", "core-test-estimates"):
        # mc rm --force --recursive is idempotent for a missing bucket; create it first.
        compose("exec", "-T", "app", "mc", "mb", "--ignore-existing", f"core-test/{bucket}")
        compose("exec", "-T", "app", "mc", "rm", "--recursive", "--force", f"core-test/{bucket}")
    readiness()
    manifests = snapshot(folder)
    compose("--profile", "workers", "up", "-d", "--no-build", "--wait", "horizon", "scheduler")
    return manifests


def env_proof(folder):
    proof = destructive_guard()
    fixture_proof(folder)
    guard_tests = run([sys.executable, "-B", "-m", "unittest", "discover", "-s",
                       "docker/tests/process-core", "-p", "test_rig.py", "-v"], check=False)
    (folder / "guard-tests.log").write_text(guard_tests.stdout + guard_tests.stderr)
    require(guard_tests.returncode == 0, "negative guard examples failed")
    # The negative proof never invokes reset; unit tests also assert no mutation on rejection.
    base = {"project": PROJECT, "database": "agelto_test", "user": "core_test", "port": 5432,
            "server_version": "17.0", "environment": "testing"}
    target_guard(base)
    rejected = []
    for key, value in [("project", "agelto_dev"), ("database", "agelto"),
                       ("database", "another_test"), ("user", "postgres"),
                       ("environment", "production"), ("port", 5433)]:
        try:
            target_guard({**base, key: value})
        except GuardError:
            rejected.append({key: value})
        else:
            raise GuardError("negative target guard accepted " + key)
    # Exercise raw sockets in every runtime role, independently of Laravel HTTP fakes.
    code = "$s=@fsockopen('1.1.1.1',443,$e,$m,2); if($s){fclose($s);exit(1);} echo 'egress_denied';"
    denial = {}
    for service in ("app", "horizon", "scheduler"):
        if service not in proof["services"] or proof["services"][service]["state"] != "running":
            if service != "app":
                continue
        denial[service] = compose("exec", "-T", service, "php", "-r", code).stdout
    require(denial.get("app") == "egress_denied", "egress deny not proved")
    local = compose("exec", "-T", "app", "curl", "--fail", "--silent", "--max-time", "5",
                    "http://fixture-http:8080/").stdout
    require(json.loads(local).get("ok") is True, "fixture network unavailable")
    write_json(folder / "environment.json", {"identity": proof, "negative_guards": rejected,
                                             "raw_socket_egress": denial, "fixture_http": True})


def compare_scenarios(before, after, moves):
    """Compare exact executed identities, including dataset labels, across a path-only move."""
    require(before and all(c.get("status") == "passed" for c in before),
            "original executed scenarios must be nonempty and passed")
    paths = {move["old"]: move["new"] for move in moves}

    def key(case, moved=False):
        path = case.get("file", "").split("::", 1)[0]
        name = case.get("name")
        require(path.startswith("tests/") and path.endswith(".php") and isinstance(name, str) and name,
                "invalid executed scenario identity")
        return (paths.get(path, path) if moved else path, name)

    expected = Counter(key(case, True) for case in before)
    observed = Counter(key(case) for case in after if case.get("status") == "passed")
    identities = Counter(key(case) for case in after)

    def entries(counter):
        return [{"file": path, "name": name, "count": count}
                for (path, name), count in sorted(counter.items())]

    return {"original_executed_cases": len(before), "after_executed_cases": len(after),
            "preserved_cases": sum((expected & observed).values()),
            "missing": entries(expected - observed), "new": entries(observed - expected),
            "duplicates": entries(Counter({k: v for k, v in identities.items() if v > 1})),
            "nonpassing": [case for case in after if case.get("status") != "passed"]}


def scenario_proof(folder, cases):
    original_path = ROOT / "tests/Fixtures/ProcessCore/original-executed-scenarios.json"
    original = json.loads(original_path.read_text())
    moves = json.loads((ROOT / "tests/Fixtures/ProcessCore/platforms-move-map.json").read_text())
    require(original.get("format") == "process-core-executed-baseline/v1",
            "unknown original executed scenario format")
    provenance = original["provenance"]
    require(provenance["command"] == "core-test-regression" and provenance["exit"] == 0
            and digest(original["cases"]) == provenance["cases_sha256"],
            "original executed scenario provenance/hash mismatch")
    comparison = compare_scenarios(original["cases"], cases, moves["moves"])
    comparison["before_evidence"] = provenance
    comparison["before_fixture_sha256"] = hashlib.sha256(original_path.read_bytes()).hexdigest()
    write_json(folder / "scenario-comparison.json", comparison)
    require(not comparison["missing"] and not comparison["duplicates"] and not comparison["nonpassing"],
            "original executed scenarios lost/changed/skipped; see scenario-comparison.json")
    return comparison


def suite(folder, name):
    if name == "unit":
        # Pure tests need the isolated runtime identity, not a migrated domain schema.
        identity()
    else:
        readiness()
    stop_workers()  # Regression tests own RefreshDatabase; no scheduler races with it.
    verify_fixture_inputs()
    if name == "unit":
        fixture_proof(folder)
    prepare_runtime()
    junit = f"storage/app/testing/process-core/{folder.name}-{name}.xml"
    command = ["./vendor/bin/pest", "--no-tia", "--compact", "--log-junit=" + junit]
    command += ["--testsuite=Unit"] if name == "unit" else SUITE_PATHS.get(name, [])
    result = compose("exec", "-T", "app", *command, check=False)
    (folder / (name + ".log")).write_text(result.stdout + result.stderr)
    compose("cp", f"app:/var/www/html/{junit}", str(folder / "junit.xml"))
    tree = ET.parse(folder / "junit.xml")
    cases = [{"class": c.get("classname"), "name": c.get("name"), "file": c.get("file"),
              "status": "failed" if c.find("failure") is not None or c.find("error") is not None
              else "skipped" if c.find("skipped") is not None else "passed"}
             for c in tree.iter("testcase")]
    write_json(folder / "executed-scenarios.json", cases)
    require(cases and any(c["status"] == "passed" for c in cases), "empty test selection")
    require(result.returncode == 0, f"{name} failed; see {folder}")
    require(all(c["status"] == "passed" for c in cases), "baseline contains failed/skipped scenarios")
    require(sum(int(c.get("assertions", "0")) for c in tree.iter("testcase")) > 0,
            "suite executed no assertions")
    if name == "regression":
        inventory = json.loads((ROOT / "tests/Fixtures/ProcessCore/manifest.json").read_text())
        # PHPUnit encodes the file path in Pest's generated classname. Keep both forms.
        observed = "\n".join(str(c) for c in cases).replace("\\", "/").lower()
        missing = [entry["path"] for entry in inventory["files"] if entry.get("scenarios")
                   and entry["path"].removesuffix(".php").lower() not in observed]
        write_json(folder / "baseline-coverage.json", {"missing_files": missing,
                   "declared_scenarios": sum(len(f.get("scenarios", [])) for f in inventory["files"]),
                   "executed_cases": len(cases)})
        require(not missing, "original scenario files missing from regression JUnit")
        comparison = scenario_proof(folder, cases)
        return {"executed": len(cases), "exit": result.returncode,
                "original_preserved": comparison["preserved_cases"],
                "scenario_comparison_sha256": hashlib.sha256((folder / "scenario-comparison.json").read_bytes()).hexdigest()}
    return {"executed": len(cases), "exit": result.returncode}


def quality(folder):
    identity()
    verify_fixture_inputs()
    prepare_runtime()
    config = (ROOT / "phpstan.neon").read_text()
    require("level: max" in config and not re.search(r"ignoreErrors|excludePaths|baseline", config)
            and not (ROOT / "phpstan-baseline.neon").exists(), "static baseline/ignores forbidden")
    schema_tests = run(["env", "PROCESS_CORE_SCHEMA_RIG=1", sys.executable, "-B", "-m", "unittest", "discover", "-s",
                        "docker/tests/process-core", "-p", "test_schema_inventory.py", "-v"], check=False)
    (folder / "schema-tests.log").write_text(schema_tests.stdout + schema_tests.stderr)
    results = {"schema-tests": schema_tests.returncode}
    for name, command in [("pint", ["./vendor/bin/pint", "--test"]),
                          ("phpstan", ["./vendor/bin/phpstan", "analyse", "--memory-limit=1G", "--no-progress"]),
                          ("architecture", ["./vendor/bin/pest", "--no-tia",
                                             "--compact", "tests/Unit/Architecture"]),
                          ("schema-docs", ["php", "tests/Support/ProcessCore/verify-baseline.php"])]:
        result = compose("exec", "-T", "app", *command, check=False)
        (folder / (name + ".log")).write_text(result.stdout + result.stderr)
        results[name] = result.returncode
    require(not any(results.values()), f"quality failed: {results}; see run logs")
    return results


def collect(folder):
    # The latest run of each command decides: an older green run never outlives a newer red one.
    latest = {}
    for path in sorted(REPORTS.glob("*/result.json")):
        result = json.loads(path.read_text())
        latest[result["command"]] = (result, path.parent)
    evidence = {name: result for name, (result, _) in latest.items() if result.get("exit") == 0}
    locations = {name: folder for name, (result, folder) in latest.items() if result.get("exit") == 0}
    required = {"core-test-up", "core-test-env", "core-test-install", "core-test-unit",
                "core-test-regression", "core-quality"}
    require(required.issubset(evidence), "missing evidence: " + str(sorted(required - set(evidence))))
    current = json.loads((folder / "inputs.json").read_text())
    for name in required:
        require(evidence[name]["candidate"]["input_tree_sha256"] == digest(current), "stale evidence: " + name)
    comparison_path = locations["core-test-regression"] / "scenario-comparison.json"
    require(comparison_path.is_file(), "missing regression scenario-comparison.json")
    require(hashlib.sha256(comparison_path.read_bytes()).hexdigest()
            == evidence["core-test-regression"].get("tests", {}).get("scenario_comparison_sha256"),
            "regression scenario comparison hash mismatch")
    write_json(folder / "summary.json", {"verified": sorted(required), "future": "suite_not_implemented",
                                        "evidence": evidence})


def main(command):
    name = command.removeprefix("core-test-")
    if name in FUTURE:
        print(json.dumps({"status": "suite_not_implemented", "suite": name}))
        return 78
    require(name in IMPLEMENTED or command == "core-quality", "unknown core target")
    with project_lock():
        return execute(command, name)


def execute(command, name):
    folder, result = new_report(command)
    try:
        if name in {"reset", "install", "execution", "concurrency", "observability", "network", "regression"}:
            clock = install_clock()
            result["install_clock"] = clock
            write_json(folder / "install-clock.json", clock)
        if name == "up":
            identity(allow_empty=True)  # Validate all pre-existing resources before mutation.
            result["runtime_metadata"] = prepare_metadata(folder)
            compose("up", "-d", "--build", "--wait", "app", "core-postgres", "core-redis", "object-storage",
                    "fixture-http", "fixture-proxy", "fake-gemini")
            result["identity"] = identity()
            destructive_guard()
            prepare_runtime()
        elif name == "env":
            env_proof(folder)
        elif name == "reset":
            result["manifests"] = reset(folder, clock=clock)
        elif name == "install":
            first, second = folder / "install-1", folder / "install-2"
            first.mkdir()
            second.mkdir()
            before = reset(first, clock=clock)
            after = reset(second, clock=clock)
            write_json(folder / "install-comparison.json", {"first": before, "second": after})
            require(before == after, "two clean installs produced different manifests")
            result["manifests"] = after
        elif name in {"unit", "execution", "concurrency", "observability", "network", "regression"}:
            if name in {"execution", "concurrency", "observability", "network", "regression"}:
                reset_folder = folder / "clean-reset"
                reset_folder.mkdir()
                reset(reset_folder, clock=clock)
            result["tests"] = suite(folder, name)
        elif command == "core-quality":
            result["quality"] = quality(folder)
        elif name == "report":
            collect(folder)
        elif name == "down":
            identity(allow_empty=True)
            compose("--profile", "workers", "--profile", "browser", "down", "--volumes", "--remove-orphans")
        result["exit"] = 0
    except (GuardError, ValueError, OSError) as exc:
        result.update(exit=1, error=str(exc))
    write_json(folder / "result.json", result)
    print(json.dumps({"exit": result["exit"], "evidence": str(folder.relative_to(ROOT))}))
    return result["exit"]


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1] if len(sys.argv) == 2 else ""))
    except (GuardError, ValueError, OSError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
