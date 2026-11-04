#!/usr/bin/env python3

from __future__ import annotations

import argparse
import hashlib
import json
import re
from dataclasses import asdict, dataclass
from pathlib import Path


SCRIPT_MODES = {
    "docker/scripts/deploy-repositories.sh": ("prepare", "sync", "fixture"),
    "docker/scripts/production-profile-check.sh": ("check",),
    "docker/scripts/backup-production.sh": ("check", "fixture", "apply"),
    "docker/scripts/backup-watchdog.sh": ("check",),
    "docker/scripts/backup-alert.sh": ("check", "apply"),
    "docker/scripts/first-production-deploy.sh": ("check", "fixture", "apply"),
    "docker/scripts/release-production.sh": ("check", "fixture", "apply"),
    "docker/scripts/rollback-production.sh": ("check", "fixture", "apply"),
    "docker/scripts/restore-production.sh": ("check", "fixture", "apply"),
    "docker/scripts/rotate-production-secrets.sh": ("check", "fixture", "apply"),
}
TARGET_RE = re.compile(r"^([A-Za-z0-9_.%\\:-]+):(?:\s|$)")
VARIABLE_RE = re.compile(r"\$\(([A-Z][A-Z0-9_]*)\)")


@dataclass(frozen=True)
class CommandRow:
    command: str
    audience: str
    environment: str
    prerequisites: str
    mutation: str
    inputs: str
    output: str
    exit_contract: str
    resume: str
    evidence: str


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def unescape_target(value: str) -> str:
    return value.replace("\\:", ":")


def parse_makefile(path: Path, text: str | None = None) -> tuple[list[str], dict[str, list[str]]]:
    text = path.read_text(encoding="utf-8") if text is None else text
    phony: list[str] = []
    definitions: dict[str, list[str]] = {}
    current: str | None = None

    for line in text.splitlines():
        if line.startswith(".PHONY:"):
            phony.extend(unescape_target(token) for token in line.removeprefix(".PHONY:").split())
            current = None
            continue
        match = TARGET_RE.match(line)
        if match:
            current = unescape_target(match.group(1))
            if current in definitions:
                raise SystemExit(f"duplicate target definition: {current}")
            definitions[current] = []
            continue
        if line.startswith("\t") and current is not None:
            definitions[current].append(line[1:])
            continue
        if line and not line.startswith(("#", " ", "\t")):
            current = None

    duplicates = sorted({name for name in phony if phony.count(name) > 1})
    if duplicates:
        raise SystemExit(f"duplicate .PHONY targets: {', '.join(duplicates)}")
    missing = sorted(set(phony) - definitions.keys())
    if missing:
        raise SystemExit(f".PHONY targets without recipes: {', '.join(missing)}")
    unlisted = sorted(name for name in definitions if name not in phony and name not in {"%", "FORCE"})
    if unlisted:
        raise SystemExit(f"public targets missing from .PHONY: {', '.join(unlisted)}")
    if not phony:
        raise SystemExit("empty .PHONY inventory")
    return phony, definitions


def validate_root_makefile(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    # The isolated rig has an explicit root entrypoint; all other targets delegate.
    core = re.search(r"^CORE_TARGETS := (?P<names>core-[a-z0-9-]+(?: core-[a-z0-9-]+)*)\n"
                     r"\.PHONY: \$\(CORE_TARGETS\)\n\$\(CORE_TARGETS\):\n"
                     r"\t@python3 docker/tests/process-core/rig\.py \$@\n", text, re.MULTILINE)
    core_targets = core.group("names").split() if core else []
    if len(core_targets) != len(set(core_targets)):
        raise SystemExit("duplicate isolated rig targets")
    delegated = text[:core.start()] + text[core.end():] if core else text
    _, definitions = parse_makefile(path, delegated)
    if set(definitions) != {"help", "%", "FORCE"}:
        raise SystemExit("root Makefile must contain only help, catch-all delegation and FORCE")
    if "$(MAKE) -f docker/Makefile" not in text:
        raise SystemExit("root Makefile does not delegate to docker/Makefile")
    normal_targets = [match.group(1) for line in text.splitlines() if (match := TARGET_RE.match(line)) and not line.startswith(".")]
    if not normal_targets or normal_targets[0] != "help":
        raise SystemExit("help must be the first normal root target")
    return core_targets


def validate_recipes(definitions: dict[str, list[str]]) -> None:
    forbidden = re.compile(r"curl\s*\|\s*sh|\beval\b|\|\|\s*true|git\s+pull|down\s+--volumes")
    for target, lines in definitions.items():
        recipe = "\n".join(lines)
        if target in {"help", "FORCE"}:
            continue
        if not lines:
            raise SystemExit(f"target has no recipe: {target}")
        if forbidden.search(recipe):
            raise SystemExit(f"unguarded destructive/unsafe recipe: {target}")
        if target.startswith("prod:") and "COMPOSE_DEV" in recipe:
            raise SystemExit(f"production target uses development Compose: {target}")
        if target.startswith("dev:") and "COMPOSE_PROD" in recipe:
            raise SystemExit(f"development target uses production Compose: {target}")
        if re.search(r"echo[^\n]*\$\((?:PASSWORD|TOKEN|SECRET)[A-Z0-9_]*\)", recipe):
            raise SystemExit(f"target may print a secret variable: {target}")
    migrate = "\n".join(definitions.get("prod:migrate", []))
    if "OWNER_GATE" not in migrate or "--force" not in migrate:
        raise SystemExit("prod:migrate lacks its explicit owner gate")
    host_apply = "\n".join(definitions.get("host:apply", []))
    if "CONFIRM" not in host_apply or "--confirm" not in host_apply:
        raise SystemExit("host:apply lacks its explicit confirmation gate")


def classify_target(name: str, recipe: str) -> CommandRow:
    operator = name.startswith(("host:", "repositories:", "prod:", "preparation:"))
    audience = "operator" if operator else "developer/agent"
    if name.startswith(("host:", "repositories:")):
        environment = "Ubuntu host / future rehearsal"
    elif name.startswith("prod:"):
        environment = "production / future rehearsal"
    elif name.startswith(("test", "quality", "documentation:")):
        environment = "local/CI"
    else:
        environment = "local development"

    local_checks = ("help", "check", "preflight", "ps", "logs", "quality", "test", "doctor", "config")
    mutation = "read-only/check" if any(token in name for token in local_checks) else "mutation"
    if name in {"dev:shell", "dev:artisan", "dev:composer", "agent:artisan"}:
        mutation = "interactive/command-dependent"

    variables = sorted(
        value
        for value in set(VARIABLE_RE.findall(recipe))
        if value not in {"ROOT_DIR", "DOCKER_DIR", "COMPOSE_DEV", "COMPOSE_PROD", "AGENT_ENV"}
    )
    inputs = ", ".join(variables) if variables else "none"
    prerequisites = "explicit " + inputs if variables else "repository checkout"
    if "OWNER_GATE" in recipe or "CONFIRM" in recipe:
        prerequisites += "; owner confirmation"
    elif operator and mutation != "read-only/check":
        prerequisites += "; follow-up rehearsal authority"

    if name.startswith("test"):
        output = "test result/evidence"
    elif name.startswith("documentation:"):
        output = "documentation artifact/check"
    elif mutation == "read-only/check":
        output = "deterministic check result"
    else:
        output = "declared runtime mutation"

    evidence = "blank runtime evidence template" if "future rehearsal" in prerequisites else "command output / CI artifact"
    return CommandRow(
        command=f"make {name}",
        audience=audience,
        environment=environment,
        prerequisites=prerequisites,
        mutation=mutation,
        inputs=inputs,
        output=output,
        exit_contract="0=green; nonzero=stop",
        resume="repair the failing check/stage and rerun the same command",
        evidence=evidence,
    )


def validate_script_modes(root: Path) -> list[CommandRow]:
    rows: list[CommandRow] = []
    for relative, modes in SCRIPT_MODES.items():
        path = root / relative
        if not path.is_file() or path.is_symlink():
            raise SystemExit(f"missing or unsafe command script: {relative}")
        text = path.read_text(encoding="utf-8")
        for mode in modes:
            if not re.search(rf"(?<![A-Za-z0-9_-]){re.escape(mode)}(?![A-Za-z0-9_-])", text):
                raise SystemExit(f"declared script mode is absent: {relative} {mode}")
            external = mode in {"prepare", "sync", "apply"}
            rows.append(
                CommandRow(
                    command=f"{relative} {mode}",
                    audience="operator" if external else "developer/agent",
                    environment="production / future rehearsal" if external else "local fixture/check",
                    prerequisites="follow-up rehearsal authority" if external else "repository checkout and explicit fixture inputs",
                    mutation="mutation" if external else "read-only/hermetic fixture",
                    inputs="closed named flags; secret values only through owner-only files",
                    output="redacted state/check result",
                    exit_contract="0=green; 1=contract failure; 2=usage/authority stop",
                    resume="preserve identity/state, repair, and rerun the same mode",
                    evidence="blank runtime evidence template" if external else "local contract output only",
                )
            )
    return rows


def inventory(root: Path) -> tuple[list[CommandRow], dict[str, str]]:
    root_makefile = root / "Makefile"
    docker_makefile = root / "docker/Makefile"
    core_targets = validate_root_makefile(root_makefile)
    phony, definitions = parse_makefile(docker_makefile)
    validate_recipes(definitions)
    rows = [classify_target(name, "\n".join(definitions[name])) for name in sorted(phony)]
    rows.extend(CommandRow(
        command=f"make {name}", audience="developer/agent", environment="isolated test rig",
        prerequisites="exact Compose/database identity guards and local fixture inputs",
        mutation=("report-only" if name == "core-test-report" else "isolated checks"
                  if name in {"core-test-env", "core-quality"} else "isolated runtime mutation"),
        inputs="core rig configuration; TEST_FILE for a targeted test; WIDTH=10|20 for load",
        output="test evidence / rig result", exit_contract="0=green; nonzero=stop",
        resume="repair the failing rig stage and rerun the same command",
        evidence="local contract output only",
    ) for name in core_targets)
    rows.extend(validate_script_modes(root))
    commands = [row.command for row in rows]
    if len(commands) != len(set(commands)):
        raise SystemExit("duplicate normalized command rows")
    sources = {"Makefile": sha256(root_makefile), "docker/Makefile": sha256(docker_makefile)}
    sources.update({relative: sha256(root / relative) for relative in SCRIPT_MODES})
    return rows, sources


def render(rows: list[CommandRow], sources: dict[str, str]) -> str:
    source_fingerprint = hashlib.sha256(json.dumps(sources, sort_keys=True).encode()).hexdigest()
    lines = [
        "# Source-derived command matrix",
        "",
        "> Generated from the root delegation, `docker/Makefile` `.PHONY` registry and declared production script modes.",
        f"> Source fingerprint: `{source_fingerprint}`. External/mutating rows are not executed by generation.",
        "",
        "| Command | Audience | Environment | Prerequisites | Mutation | Inputs | Output | Exit | Resume | Evidence |",
        "|:--|:--|:--|:--|:--|:--|:--|:--|:--|:--|",
    ]
    for row in rows:
        values = [str(value).replace("|", "\\|") for value in asdict(row).values()]
        values[0] = f"`{values[0]}`"
        lines.append("| " + " | ".join(values) + " |")
    return "\n".join(lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("render", "check", "agent-json"))
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    root = args.root.resolve()
    if args.mode != "agent-json" and args.output is None:
        parser.error("--output is required for render/check; use a current temporary artifact")
    output = args.output.resolve() if args.output else None
    rows, sources = inventory(root)
    content = render(rows, sources)
    if args.mode == "render":
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(content, encoding="utf-8")
    elif args.mode == "check":
        if not output.is_file() or output.read_text(encoding="utf-8") != content:
            raise SystemExit("command matrix is stale")
    else:
        payload = {
            "schema": "agelto.command-reference/v1",
            "source_sha256": hashlib.sha256(json.dumps(sources, sort_keys=True).encode()).hexdigest(),
            "commands": [asdict(row) for row in rows],
        }
        rendered = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
        if str(root) in rendered:
            raise SystemExit("agent output contains an absolute repository path")
        print(rendered)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
