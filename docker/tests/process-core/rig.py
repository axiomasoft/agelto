#!/usr/bin/env python3
"""Fixed-target, fail-closed process-core rig. No development Compose fallback."""
import datetime
from collections import Counter
from contextlib import contextmanager
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
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
FUTURE = set()
IMPLEMENTED = {"faults", "load", "file", "shard", "tia", "release", "ai", "documents", "up", "env", "reset", "install", "unit", "execution", "concurrency", "observability", "network", "platforms", "acquisition", "e2e", "regression", "report", "down"}
RESET_SUITES = {"faults", "load", "shard", "tia", "execution", "concurrency", "observability",
                "network", "platforms", "acquisition", "documents", "ai", "e2e", "regression"}
GSFX_FIXTURE = "tests/Fixtures/ProcessCore/synthetic-estimate.gsfx"
# Domain suites execute their owning assertions; concurrency tests use separate processes and
# database connections. Transport, platform adapters and reconciliation have their own suites.
SUITE_PATHS = {"ai": ["tests/Feature/ProcessCore/Llm"], "documents": ["tests/Feature/ProcessCore/Documents", "tests/Integration/ProcessCore/Concurrency/StorageCapacityTest.php", "tests/Integration/ProcessCore/Concurrency/DocumentStorageTest.php", "tests/Feature/Procurement/Prepare", "tests/Feature/Procurement/Document/S3CrashRecoveryTest.php", "tests/Feature/Document/DocumentDownloaderTest.php"], "acquisition": ["tests/Feature/ProcessCore/Acquisition", "tests/Unit/Architecture/AcquisitionIdentityOwnershipTest.php"], "execution": ["tests/Feature/ProcessCore/Execution", "tests/Integration/ProcessCore/Concurrency"],
               "concurrency": ["tests/Integration/ProcessCore/Concurrency"],
               "observability": ["tests/Feature/ProcessCore/Observability",
                                 "tests/Feature/Health/PrivatePackagesArePinnedTest.php"],
               "network": ["tests/Feature/ProcessCore/Network",
                            "tests/Feature/Procurement/Network/ProxymaCutoverTest.php",
                            "tests/Feature/Procurement/Network/ProxymaProbingAndGuardTest.php",
                            "tests/Integration/ProcessCore/Concurrency/CatalogConcurrencyTest.php"],
               "platforms": ["tests/Feature/ProcessCore/Platforms",
                             "tests/Unit/Architecture/PlatformsDomainLayoutTest.php",
                             "tests/Unit/Architecture/PlatformExtensionContractTest.php"],
               "e2e": ["tests/Feature/ProcessCore/E2e",
                       "tests/Integration/ProcessCore/E2e",
                       "tests/Feature/ProcessCore/Observability/FinalCutoverTest.php",
                       "tests/Feature/ProcessCore/Observability/PlatformMetricsProjectionTest.php",
                       "tests/Feature/ProcessCore/Platforms/PlatformUiRegistryTest.php"],
               "load": ["tests/Integration/ProcessCore/Load/LoadTest.php",
                        "tests/Integration/ProcessCore/Concurrency/AdmissionFairnessConcurrencyTest.php",
                            "tests/Feature/ProcessCore/Network/ReplayBoundedDemandTest.php",
                            "tests/Feature/ProcessCore/Network/ReplayDemandFifoTest.php",
                            "tests/Feature/ProcessCore/Network/ReplayMultiScopeReservationTest.php",
                            "tests/Feature/ProcessCore/Network/ReplayGcraReservationTest.php"],
               "faults": ["tests/Integration/ProcessCore/Faults",
                          "tests/Feature/ProcessCore/Llm/ExtractionWorkerKillTest.php",
                          "tests/Integration/ProcessCore/Concurrency/ProviderJointSendTest.php",
                          "tests/Integration/ProcessCore/Concurrency/DaemonRedisLossAcceptanceTest.php",
                          "tests/Integration/ProcessCore/Concurrency/FinalizeFenceConcurrencyTest.php",
                          "tests/Feature/ProcessCore/Execution/BlockedProviderRecoveryTest.php",
                          "tests/Feature/ProcessCore/Llm/ProviderRecoveryConcurrencyTest.php",
                          "tests/Feature/ProcessCore/Network/ReplayClaimSendFenceTest.php",
                          "tests/Feature/ProcessCore/Network/ReplayDispatchDeadlineTest.php",
                          "tests/Feature/ProcessCore/Network/ReplaySealedWireTest.php",
                          "tests/Feature/ProcessCore/Network/ReplayRecoveryLifecycleTest.php",
                          "tests/Feature/ProcessCore/Network/ReplayBoundedDemandTest.php",
                          "tests/Feature/ProcessCore/Documents/DownloadPublicationFaultTest.php",
                          "tests/Feature/ProcessCore/Observability/PostCommitSinksTest.php",
                          "tests/Feature/ProcessCore/Observability/ProjectionRetentionTest.php",
                          "tests/Integration/ProcessCore/Concurrency/DocumentStorageTest.php"]}


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
            mounts = {m["target"]: m for m in service.get("volumes", [])}
            for key in ("azguard", "proxyma"):
                mount = mounts.get("/var/www/html/local/vendors/" + key, {})
                require(mount.get("type") == "bind" and mount.get("read_only") is True
                        and Path(mount.get("source", "")).resolve() == (RUNTIME_INPUTS / key).resolve(),
                        "missing sanitized readonly vendor snapshot: " + key)
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
    gsfx = [entry for entry in manifest["files"] if entry["runtime"].endswith(".gsfx")]
    require(len(gsfx) == 1 and gsfx[0]["source"] == gsfx[0]["runtime"] == GSFX_FIXTURE,
            "GSFX runtime input must use the hermetic synthetic fixture")
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


def vendor_module():
    spec = importlib.util.spec_from_file_location("vendor_manifest", ROOT / "docker/scripts/vendor_manifest.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def verify_snapshot(vendor, row, files):
    require(vendor.is_dir() and not vendor.is_symlink(), "missing/unsafe dependency snapshot")
    require(run(["git", "-C", vendor, "rev-parse", "HEAD"]).stdout.strip() == row["commit"],
            "dependency Git anchor differs")
    actual = {}
    for path in vendor.rglob("*"):
        relative = path.relative_to(vendor)
        require(not path.is_symlink(), "snapshot contains a symlink")
        if relative.parts[0] == ".git":
            continue
        if path.is_file():
            actual[relative.as_posix()] = path.read_bytes()
    require(actual == files, "exported dependency files differ or contain credentials")
    config = run(["git", "-C", vendor, "config", "--local", "--name-only", "--list"]).stdout.splitlines()
    require(set(config) <= {"core.repositoryformatversion", "core.filemode", "core.bare", "core.logallrefupdates",
                           "core.sparsecheckout", "core.sparsecheckoutcone", "extensions.worktreeconfig",
                           "remote.origin.url"}, "snapshot contains foreign Git configuration")
    require(not (vendor / ".git/hooks").exists(), "snapshot contains Git hooks")
    require(vendor_module().origin_matches(run(["git", "-C", vendor, "config", "--get", "remote.origin.url"]).stdout.strip(), row),
            "snapshot origin differs")


def prepare_metadata(folder):
    """Export real shallow Git objects and only the exact allowlisted package payloads."""
    inputs = verify_fixture_inputs()
    module = vendor_module()
    rows = module.load(ROOT / "docker/private-vendors.lock.json")
    run(["bash", ROOT / "docker/scripts/vendor-sync.sh", "--check"])
    head = run(["git", "rev-parse", "HEAD"]).stdout.strip()
    dependencies, payloads = {}, {}
    for row in rows:
        checkout = module.safe_checkout(ROOT, row)
        files = module.package_files(checkout, row)
        payloads[row["key"]] = files
        dependencies[row["key"]] = {"commit": row["commit"], "packages": module.package_hashes(files, row)}
    require(inputs.get("repositories") == dependencies, "runtime-input repository SHA/hashes differ from vendor lock")
    expected = {"candidate_head": head, "dependencies": dependencies}
    require(not RUNTIME_INPUTS.is_symlink(), "runtime metadata root is a symlink")
    RUNTIME_INPUTS.mkdir(exist_ok=True)
    metadata = RUNTIME_INPUTS / "metadata.json"
    if metadata.exists():
        require(not metadata.is_symlink() and json.loads(metadata.read_text()) == expected,
                "stale runtime Git snapshot; refresh only through the stopped isolated rig protocol")
    else:
        candidate = RUNTIME_INPUTS / "candidate.git"
        require(not candidate.exists() and all(not (RUNTIME_INPUTS / r["key"]).exists() for r in rows),
                "incomplete runtime Git snapshot")
        run(["git", "init", "--bare", "--template=", candidate])
        run(["git", "--git-dir", candidate, "fetch", "--no-tags", "--depth=1", "--no-write-fetch-head",
             ROOT, head + ":refs/heads/candidate"])
        run(["git", "--git-dir", candidate, "symbolic-ref", "HEAD", "refs/heads/candidate"])
        for row in rows:
            vendor = RUNTIME_INPUTS / row["key"]
            run(["git", "init", "--template=", vendor])
            run(["git", "-C", vendor, "fetch", "--no-tags", "--depth=1", "--no-write-fetch-head",
                 ROOT / "local/vendors" / row["key"], row["commit"] + ":refs/heads/pinned"])
            run(["git", "-C", vendor, "sparse-checkout", "set", "--no-cone",
                 *["/" + p["path"] + "/" for p in row["packages"]]])
            run(["git", "-C", vendor, "checkout", "--detach", row["commit"]])
            run(["git", "-C", vendor, "config", "remote.origin.url",
                 "https://github.com/" + row["repository"]["path"] + ".git"])
            verify_snapshot(vendor, row, payloads[row["key"]])
        write_json(metadata, expected)
    require(run(["git", "--git-dir", RUNTIME_INPUTS / "candidate.git", "rev-parse", "HEAD"]).stdout.strip() == head,
            "candidate Git anchor differs")
    for row in rows:
        verify_snapshot(RUNTIME_INPUTS / row["key"], row, payloads[row["key"]])
    write_json(folder / "runtime-metadata.json", expected)
    return expected


def prepare_runtime():
    return compose("exec", "-T", "app", "php", "tests/Support/ProcessCore/prepare-runtime.php")


def composer_on_bridge(folder, name, *arguments, install=False):
    """Use the rig's PHP image for public dependency metadata without runtime networking."""
    image = config_identity()["services"]["app"]["image"]
    mounts = [item for path in ("composer.json", "composer.lock")
              for item in ("--mount", f"type=bind,src={ROOT / path},dst=/var/www/html/{path},readonly")]
    if install:
        vendor = ROOT / "vendor"
        cache = REPORTS.parent / "composer-cache"
        for path in (vendor, cache):
            require(not path.is_symlink(), "unsafe Composer directory")
            path.mkdir(exist_ok=True)
        mounts += ["--mount", f"type=bind,src={vendor},dst=/var/www/html/vendor",
                   "--mount", f"type=bind,src={cache},dst=/composer-cache"]
        for key in ("azguard", "proxyma"):
            mounts += ["--mount", f"type=bind,src={RUNTIME_INPUTS / key},dst=/var/www/html/local/vendors/{key},readonly"]
    result = run(["docker", "run", "--rm", "--network", "bridge", "--cap-drop=ALL",
                  "--security-opt=no-new-privileges", "--user", f"{os.getuid()}:{os.getgid()}",
                  "--workdir", "/var/www/html", "-e", "COMPOSER_HOME=/tmp/composer",
                  "-e", "COMPOSER_CACHE_DIR=/composer-cache" if install else "COMPOSER_CACHE_DIR=/tmp/composer-cache",
                  *mounts, "--entrypoint", "composer", image, *arguments], check=False)
    (folder / (name + ".log")).write_text(result.stdout + result.stderr)
    return result.returncode


def composer_audit(folder):
    for name in ("composer-audit", "composer-audit-retry-1", "composer-audit-retry-2"):
        result = composer_on_bridge(folder, name, "audit", "--locked", "--no-interaction")
        if result != 100 or not re.search(r"curl error (6|28|56) while downloading", (folder / f"{name}.log").read_text()):
            return result
    return result


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
    for pattern, log in (("test_rig.py", "guard-tests.log"),
                         ("test_load_report.py", "load-report-tests.log"),
                         ("test_acceptance_report.py", "acceptance-report-tests.log")):
        guard_tests = run([sys.executable, "-B", "-m", "unittest", "discover", "-s",
                           "docker/tests/process-core", "-p", pattern, "-v"], check=False)
        (folder / log).write_text(guard_tests.stdout + guard_tests.stderr)
        require(guard_tests.returncode == 0, "negative guard examples failed: " + pattern)
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


def compare_scenarios(before, after, moves, transfers=()):
    """Require every original case or all its explicitly recorded D18 replacements."""
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

    transferred = []
    sources = set()
    for transfer in transfers:
        require(isinstance(transfer, dict) and set(transfer) == {"source", "replacements", "reason", "contract"},
                "invalid scenario transfer fields")
        source = key(transfer["source"])
        require(source in expected and source not in sources, "unknown or duplicate scenario transfer source")
        require(isinstance(transfer["reason"], str) and transfer["reason"].strip()
                and isinstance(transfer["contract"], str) and transfer["contract"].strip(),
                "scenario transfer requires its reason and contract")
        replacements = transfer["replacements"]
        require(isinstance(replacements, list) and replacements, "scenario transfer requires executed replacements")
        targets = [key(case) for case in replacements]
        require(len(set(targets)) == len(targets) and source not in targets,
                "duplicate or self-referencing scenario replacement")
        sources.add(source)
        # A transfer never excuses a failed/skipped/missing target, or a duplicate execution.
        if all(observed[target] == 1 and identities[target] == 1 for target in targets):
            transferred.append(transfer)

    require(not any(key(case) in sources for transfer in transfers for case in transfer["replacements"]),
            "scenario transfers cannot chain or cycle")
    transferred_counts = Counter(key(transfer["source"]) for transfer in transferred)
    covered = (expected & observed) | transferred_counts

    def entries(counter):
        return [{"file": path, "name": name, "count": count}
                for (path, name), count in sorted(counter.items())]

    return {"original_executed_cases": len(before), "after_executed_cases": len(after),
            "preserved_cases": sum(covered.values()), "transferred": transferred,
            "missing": entries(expected - covered), "new": entries(observed - expected),
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
    transfer_path = ROOT / "tests/Fixtures/ProcessCore/scenario-transfers.json"
    transfers = []
    if transfer_path.exists():
        transfer_document = json.loads(transfer_path.read_text())
        require(transfer_document.get("format") == "process-core-scenario-transfers/v1"
                and transfer_document.get("before_cases_sha256") == provenance["cases_sha256"],
                "scenario transfer format/baseline mismatch")
        transfers = transfer_document["transfers"]
        require(isinstance(transfers, list), "scenario transfers must be a list")
    comparison = compare_scenarios(original["cases"], cases, moves["moves"], transfers)
    if transfer_path.exists():
        comparison["transfer_fixture_sha256"] = hashlib.sha256(transfer_path.read_bytes()).hexdigest()
    comparison["before_evidence"] = provenance
    comparison["before_fixture_sha256"] = hashlib.sha256(original_path.read_bytes()).hexdigest()
    write_json(folder / "scenario-comparison.json", comparison)
    require(not comparison["missing"] and not comparison["duplicates"] and not comparison["nonpassing"],
            "original executed scenarios lost/changed/skipped; see scenario-comparison.json")
    return comparison


def single_test_path():
    value = os.environ.get("TEST_FILE", "")
    require(re.fullmatch(r"tests/(Unit|Feature|Integration)/[A-Za-z0-9_/]+Test\.php", value)
            and (ROOT / value).is_file() and (ROOT / value).resolve().is_relative_to(ROOT / "tests"),
            "TEST_FILE must name one repository test file")
    return value


def load_width():
    value = os.environ.get("WIDTH", "")
    require(value in {"10", "20"}, "WIDTH must be exactly 10 or 20")
    return int(value)


def shard_selection():
    shard = os.environ.get("SHARD", "")
    require(re.fullmatch(r"[1-4]/4", shard), "SHARD must be one of 1/4, 2/4, 3/4, 4/4")
    return shard


def validate_load_report(report, width):
    spec = importlib.util.spec_from_file_location("core_load_report", Path(__file__).with_name("load_report.py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.validate(report, width)


def prepare_tia(folder):
    home = f"/var/www/html/storage/app/testing/process-core/{folder.name}-tia"
    branch = os.environ.get("GITHUB_REF_NAME") or "main"
    run(["git", "check-ref-format", "refs/heads/" + branch])
    repo_pattern = r"[A-Za-z0-9_-][A-Za-z0-9_.-]*/[A-Za-z0-9_-][A-Za-z0-9_.-]*"
    repository = os.environ.get("GITHUB_REPOSITORY")
    if not repository:
        origin = run(["git", "config", "--get", "remote.origin.url"], check=False).stdout.strip()
        match = re.fullmatch(r"(?:https://github\.com/|ssh://git@github\.com/|git@github\.com:)(" +
                             repo_pattern + r")", origin)
        repository = match[1].removesuffix(".git") if match else ""
    require(re.fullmatch(repo_pattern, repository), "TIA requires a GitHub repository identity")
    compose("exec", "-T", "app", "sh", "-ec", '''
        mkdir -p "$(dirname "$1")"
        mkdir "$1"
        cp -R /var/www/html/.git "$1/git"
        git --git-dir="$1/git" update-ref "refs/heads/$2" HEAD
        git --git-dir="$1/git" symbolic-ref HEAD "refs/heads/$2"
        git --git-dir="$1/git" remote add origin "$3"
        git --git-dir="$1/git" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main
        git --git-dir="$1/git" --work-tree=/var/www/html read-tree HEAD
    ''', "tia", home, branch, "https://github.com/" + repository + ".git")
    return home


def export_tia(folder, home):
    recorded = folder / "tia-recording"
    compose("cp", f"app:{home}/.pest/tia", str(recorded))
    graphs = list(recorded.glob("*/graph.json"))
    require(len(graphs) == 1, "Pest TIA must record exactly one graph")
    baseline = folder / "tia-baseline"
    baseline.mkdir()
    shutil.copy2(graphs[0], baseline / graphs[0].name)
    coverage = graphs[0].with_name("coverage.bin.gz")
    if coverage.is_file():
        shutil.copy2(coverage, baseline / coverage.name)


def suite(folder, name):
    selected = [single_test_path()] if name == "file" else None
    shard = shard_selection() if name == "shard" else None
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
    # The full retained project corpus rebuilds Laravel/Filament thousands of times. Keep the
    # isolated regression runner finite without inheriting the app worker's 256 MiB CLI default.
    command = ["php", "-d", "memory_limit=1G"] if name in {"regression", "shard", "tia"} else []
    if name == "tia":
        # Pest restarts with just pcov.directory unless it already matches the project root.
        command += ["-d", "pcov.enabled=1", "-d", "pcov.directory=/var/www/html",
                    "-d", "pcov.exclude=~(vendor|storage|node_modules)~"]
    command += ["./vendor/bin/pest", "--compact", "--log-junit=" + junit]
    command += ["--tia", "--fresh", "--ci"] if name == "tia" else ["--no-tia"]
    if shard is not None:
        command += ["--ci", "--shard=" + shard]
    command += selected if selected is not None else (["--testsuite=Unit"] if name == "unit" else SUITE_PATHS.get(name, []))
    options = []
    if name == "tia":
        tia_home = prepare_tia(folder)
        options += ["-e", "HOME=" + tia_home, "-e", "GIT_DIR=" + tia_home + "/git",
                    "-e", "GIT_WORK_TREE=/var/www/html"]
    small_load = name == "file" and selected == ["tests/Integration/ProcessCore/Load/LoadTest.php"]
    if name == "load":
        options = ["-e", "PROCESS_CORE_LOAD_WIDTH=" + str(load_width()),
                   "-e", "PROCESS_CORE_LOAD_SECONDS=1800", "-e",
                   "PROCESS_CORE_EVIDENCE_PATH=/var/www/html/storage/app/testing/process-core/" + folder.name + "-load.json"]
    elif small_load:
        options = ["-e", "PROCESS_CORE_EVIDENCE_PATH=/var/www/html/storage/app/testing/process-core/" + folder.name + "-load.json"]
    result = compose("exec", "-T", *options, "app", *command, check=False)
    (folder / (name + ".log")).write_text(result.stdout + result.stderr)
    junit_file = folder / "junit.xml"
    copied = compose("cp", f"app:/var/www/html/{junit}", str(junit_file), check=False)
    require(copied.returncode == 0 and junit_file.is_file() and junit_file.stat().st_size > 0,
            f"{name} produced no JUnit report (Pest exit {result.returncode}); see {name}.log")
    tree = ET.parse(junit_file)
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
    if name == "tia":
        export_tia(folder, tia_home)
    if small_load or name == "load":
        compose("cp", "app:/var/www/html/storage/app/testing/process-core/" + folder.name + "-load.json", str(folder / "load.json"))
    if name == "load":
        proof = validate_load_report(json.loads((folder / "load.json").read_text()), load_width())
        write_json(folder / "load-validation.json", proof)
        return {"executed": len(cases), "exit": result.returncode, "load": proof}
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
    return {"executed": len(cases), "exit": result.returncode,
            **({"shard": shard} if shard is not None else {})}


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
                          ("docs-integrity", ["./vendor/bin/pest", "--no-tia",
                                              "--compact", "tests/Unit/Docs/DocsIntegrityTest.php"]),
                          ("schema-docs", ["php", "tests/Support/ProcessCore/verify-baseline.php"])]:
        result = compose("exec", "-T", "app", *command, check=False)
        (folder / (name + ".log")).write_text(result.stdout + result.stderr)
        results[name] = result.returncode
    results["composer-audit"] = composer_audit(folder)
    require(not any(results.values()), f"quality failed: {results}; see run logs")
    return results


def release_quality(folder):
    readiness()
    stop_workers()
    verify_fixture_inputs()
    prepare_runtime()
    result = run(["env", "COMPOSE_PROJECT_NAME=" + PROJECT, "ENV_FILE=" + str(ENV_FILE),
                  "RELEASE_QUALITY_EVIDENCE_DIR=" + str(folder / "release-quality"),
                  "bash", ROOT / "docker/scripts/collect-release-quality-evidence.sh"], check=False)
    (folder / "release-quality.log").write_text(result.stdout + result.stderr)
    require(result.returncode == 0, "release quality failed; see release-quality.log")


def collect(folder):
    spec = importlib.util.spec_from_file_location("core_acceptance_report", Path(__file__).with_name("acceptance_report.py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    current = json.loads((folder / "inputs.json").read_text())
    candidate = {"head": run(["git", "rev-parse", "HEAD"]).stdout.strip(),
                 "input_tree_sha256": digest(current)}
    manifest = module.build(ROOT, REPORTS, folder, validate_load_report, candidate)
    write_json(folder / "summary.json", manifest)
    require(not manifest["errors"], "missing/stale acceptance evidence; see summary.json")


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
        if name == "load":
            result["width"] = load_width()  # Reject invalid input before reset or any mutation.
        if name in {"install", "e2e", "faults", "load", "regression"} or command == "core-quality":
            result["runtime"] = acceptance_runtime()
        if name == "shard":
            shard_selection()
        if name in RESET_SUITES | {"reset", "install", "release"}:
            clock = install_clock()
            result["install_clock"] = clock
            write_json(folder / "install-clock.json", clock)
        if name == "up":
            identity(allow_empty=True)  # Validate all pre-existing resources before mutation.
            result["runtime_metadata"] = prepare_metadata(folder)
            prebuilt = os.environ.get("CORE_TEST_PREBUILT", "0")
            require(prebuilt in {"0", "1"}, "CORE_TEST_PREBUILT must be 0 or 1")
            if not (ROOT / "vendor/autoload.php").is_file():
                if prebuilt == "0":
                    compose("build", "app")
                require(composer_on_bridge(folder, "composer-install", "install", "--no-scripts", "--no-interaction",
                                           "--no-progress", "--prefer-dist", install=True) == 0,
                        "Composer installation failed; see composer-install.log")
            compose("up", "-d", "--no-build" if prebuilt == "1" else "--build", "--wait",
                    "app", "core-postgres", "core-redis", "object-storage",
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
        elif name in RESET_SUITES | {"file", "unit", "release"}:
            if name in RESET_SUITES | {"release"}:
                reset_folder = folder / "clean-reset"
                reset_folder.mkdir()
                reset(reset_folder, clock=clock)
            if name == "release":
                release_quality(folder)
            else:
                result["tests"] = suite(folder, name)
        elif command == "core-quality":
            result["quality"] = quality(folder)
        elif name == "report":
            collect(folder)
        elif name == "down":
            identity(allow_empty=True)
            compose("--profile", "workers", "--profile", "browser", "down", "--volumes", "--remove-orphans")
        else:
            raise GuardError("target has no implementation: " + command)
        result["exit"] = 0
    except (GuardError, ValueError, OSError, ET.ParseError) as exc:
        result.update(exit=1, error=str(exc))
    write_json(folder / "result.json", result)
    print(json.dumps({"exit": result["exit"], "evidence": str(folder.relative_to(ROOT))}))
    return result["exit"]


def acceptance_runtime():
    observed = identity()
    configured = config_identity()["services"]
    images = {service: inspect_json("image", configuration["image"])["Id"]
              for service, configuration in configured.items() if service != "browser"}
    for service, value in observed["services"].items():
        expected = (inspect_json("image", configured[service]["image"])["Id"]
                    if service == "browser" else images[service])
        require(inspect_json("container", value["id"])["Image"] == expected,
                "stale running acceptance image")
    metadata = json.loads((RUNTIME_INPUTS / "metadata.json").read_text())
    inputs = verify_fixture_inputs()
    require(metadata["dependencies"] == inputs["repositories"], "stale acceptance runtime snapshot")
    # Browser profile не участвует в общем runtime gates; созданный browser проверен выше.
    app_image = images.get("app")
    require(app_image and all(images[s] == app_image for s in ("horizon", "scheduler") if s in images),
            "mixed worker/application images")
    installed = compose("exec", "-T", "app", "php", "-r", r'''
        $result = [];
        foreach (['axiomasoft/proxyma', 'axiomasoft/proxyma-filament'] as $name) {
            $base = '/var/www/html/vendor/'.$name;
            if (!is_dir($base) || is_link($base)) { throw new RuntimeException('unsafe installed package'); }
            $files = ['composer.json' => hash_file('sha256', $base.'/composer.json')];
            foreach (['src', 'config', 'database', 'resources'] as $directory) {
                if (!is_dir($base.'/'.$directory)) { continue; }
                if (is_link($base.'/'.$directory)) { throw new RuntimeException('unsafe package directory'); }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                    $base.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isLink()) { throw new RuntimeException('unsafe package file'); }
                    if ($file->isFile()) { $files[substr($file->getPathname(), strlen($base) + 1)] =
                        hash_file('sha256', $file->getPathname()); }
                }
            }
            ksort($files);
            $result[$name] = $files;
        }
        echo json_encode($result, JSON_THROW_ON_ERROR);
    ''')
    return {"dependencies": metadata["dependencies"], "images": images,
            "installed_packages": json.loads(installed.stdout),
            "config_sha256": observed["config_sha256"]}


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1] if len(sys.argv) == 2 else ""))
    except (GuardError, ValueError, OSError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
