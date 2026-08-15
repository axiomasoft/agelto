#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly script="$root/docker/scripts/check-command-reference.py"
readonly matrix="$root/plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md"

fail() {
    printf '[FAIL] P12.6 %s\n' "$*" >&2
    exit 1
}

expect_fail() {
    local label=$1
    shift
    if "$@" >/dev/null 2>&1; then
        fail "$label unexpectedly passed"
    fi
}

python3 - "$script" <<'PY'
import pathlib
import sys
compile(pathlib.Path(sys.argv[1]).read_text(), sys.argv[1], "exec")
PY
python3 "$script" render
first_hash=$(sha256sum "$matrix" | cut -d' ' -f1)
python3 "$script" render
[[ $(sha256sum "$matrix" | cut -d' ' -f1) == "$first_hash" ]] || fail 'render is not byte-idempotent'
python3 "$script" check
python3 "$script" agent-json > /tmp/agelto-command-reference-agent.json
python3 - "$root" /tmp/agelto-command-reference-agent.json <<'PY'
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
payload = json.load(open(sys.argv[2], encoding="utf-8"))
assert payload["schema"] == "agelto.command-reference/v1"
commands = payload["commands"]
assert len(commands) >= 80
assert len({row["command"] for row in commands}) == len(commands)
assert str(root) not in json.dumps(payload)
required = {"audience", "environment", "prerequisites", "mutation", "inputs", "output", "exit_contract", "resume", "evidence"}
assert all(required <= row.keys() for row in commands)
PY

make help >/dev/null
bash "$root/docker/scripts/backup-production.sh" check >/dev/null
bash "$root/docker/scripts/first-production-deploy.sh" check >/dev/null
bash "$root/docker/scripts/release-production.sh" check >/dev/null
bash "$root/docker/scripts/rollback-production.sh" check >/dev/null
bash "$root/docker/scripts/rotate-production-secrets.sh" check >/dev/null

fixture_root=$(mktemp -d)
trap 'find "$fixture_root" -depth -delete; rm -f /tmp/agelto-command-reference-agent.json' EXIT
base="$fixture_root/base"
mkdir -p "$base/docker/scripts"
cat > "$base/Makefile" <<'EOF'
MAKEFLAGS += --no-print-directory
.PHONY: help FORCE
help:
	@$(MAKE) -f docker/Makefile help
%: FORCE
	@$(MAKE) -f docker/Makefile $@
FORCE:
EOF
cat > "$base/docker/Makefile" <<'EOF'
.DEFAULT_GOAL := help
.PHONY: help safe\:check prod\:migrate host\:apply
help:
	@echo help
safe\:check:
	@echo checked
prod\:migrate:
	@test "$(OWNER_GATE)" = approved
	@echo --force
host\:apply:
	@test "$(CONFIRM)" = apply
	@echo --confirm
EOF
declare -A modes=(
    [deploy-repositories.sh]='prepare sync fixture'
    [production-profile-check.sh]='check'
    [backup-production.sh]='check fixture apply'
    [backup-watchdog.sh]='check'
    [backup-alert.sh]='check apply'
    [first-production-deploy.sh]='check fixture apply'
    [release-production.sh]='check fixture apply'
    [rollback-production.sh]='check fixture apply'
    [restore-production.sh]='check fixture apply'
    [rotate-production-secrets.sh]='check fixture apply'
)
for name in "${!modes[@]}"; do
    printf '#!/usr/bin/env bash\n# modes: %s\n' "${modes[$name]}" > "$base/docker/scripts/$name"
done
python3 "$script" render --root "$base" --output "$base/matrix.md"
python3 "$script" check --root "$base" --output "$base/matrix.md"

stale="$fixture_root/stale"
cp -a "$base" "$stale"
printf 'stale\n' >> "$stale/matrix.md"
expect_fail 'stale generated matrix' python3 "$script" check --root "$stale" --output "$stale/matrix.md"

duplicate="$fixture_root/duplicate"
cp -a "$base" "$duplicate"
printf '\n.PHONY: safe\\:check\n' >> "$duplicate/docker/Makefile"
expect_fail 'duplicate target inventory' python3 "$script" render --root "$duplicate" --output "$duplicate/matrix.md"

missing="$fixture_root/missing"
cp -a "$base" "$missing"
printf '\n.PHONY: missing\\:target\n' >> "$missing/docker/Makefile"
expect_fail 'missing target definition' python3 "$script" render --root "$missing" --output "$missing/matrix.md"

wrong_environment="$fixture_root/wrong-environment"
cp -a "$base" "$wrong_environment"
cat >> "$wrong_environment/docker/Makefile" <<'EOF'
.PHONY: dev\:bad
dev\:bad:
	@$(COMPOSE_PROD) ps
EOF
expect_fail 'wrong environment target' python3 "$script" render --root "$wrong_environment" --output "$wrong_environment/matrix.md"

destructive="$fixture_root/destructive"
cp -a "$base" "$destructive"
cat >> "$destructive/docker/Makefile" <<'EOF'
.PHONY: prod\:destroy
prod\:destroy:
	@docker compose down --volumes
EOF
expect_fail 'unguarded destructive target' python3 "$script" render --root "$destructive" --output "$destructive/matrix.md"

secret_output="$fixture_root/secret-output"
cp -a "$base" "$secret_output"
cat >> "$secret_output/docker/Makefile" <<'EOF'
.PHONY: secret\:bad
secret\:bad:
	@echo $(PASSWORD)
EOF
expect_fail 'secret-bearing output' python3 "$script" render --root "$secret_output" --output "$secret_output/matrix.md"

for document in \
    docs/03_Dev/commands.md \
    docs/03_Dev/docker/index.md \
    docs/03_Dev/process/deploy.md \
    docs/03_Dev/process/testing.md \
    docs/04_Operators/production/_index.md \
    docs/04_Operators/production/commands.md \
    docs/04_Operators/production/troubleshooting.md \
    docs/04_Operators/_index.md; do
    rg -q 'P12-command-matrix\.md' "$root/$document" || fail "$document does not link to the canonical matrix"
done
rg -q 'Local fixture' "$root/docs/04_Operators/production/commands.md"
rg -q 'runtime evidence' "$root/docs/04_Operators/production/commands.md"
git diff --check -- "$script" "$matrix" "$root/docker/tests/command-reference-contract.sh"

printf 'Command reference contract passed\n'
