#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
plan_dir="$root_dir/plans/2026.08.09-RELEASE-READINESS"
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

cd "$root_dir"

grep -Fx '**Status:** 🟢 Done' "$plan_dir/phases/P11/P11.2.state.md" >/dev/null
rg -F '<server name="DB_DATABASE" value="agelto_test"/>' phpunit.xml >/dev/null
rg -F '<env name="DB_DATABASE" value="agelto_test"/>' phpunit.xml >/dev/null
rg -F "'DB_DATABASE' => 'agelto_test'" tests/bootstrap.php >/dev/null
rg -F 'TEST_TOKEN' tests/bootstrap.php >/dev/null
rg -F 'TMPDIR' tests/bootstrap.php tests/Support/TestFilesystemIsolation.php >/dev/null
rg -F 'assertBootstrapTestDatabase' tests/TestCase.php app/Providers/AppServiceProvider.php >/dev/null
rg -F 'setUpTestDatabaseBeforeMigrating' app/Providers/AppServiceProvider.php >/dev/null
rg -F 'tearDownProcess' app/Providers/AppServiceProvider.php >/dev/null
rg -F 'assertParallelTestDatabase' tests/Support/TestEnvironmentGuard.php >/dev/null
rg -F 'storage/framework/testing' tests/Support/TestFilesystemIsolation.php >/dev/null
rg -F 'CODEX_THREAD_ID' docker/Makefile >/dev/null
rg -F 'CLAUDE_CODE' docker/Makefile >/dev/null
rg -F 'CURSOR_AGENT' docker/Makefile >/dev/null
rg -F 'AI_AGENT' docker/Makefile >/dev/null
! rg -i 'waaseyaa/agent-output|agent-output' composer.json composer.lock >/dev/null

make -n test:parallel PROCESSES=2 | rg -F -- '--parallel --processes=2 --no-tia --compact' >/dev/null
make -n test:parallel PROCESSES=4 | rg -F -- '--parallel --processes=4 --no-tia --compact' >/dev/null
if make test:parallel PROCESSES=3 >/dev/null 2>&1; then
    echo 'test:parallel accepted unsupported process count' >&2
    exit 1
fi

compose=(docker compose --project-directory "$root_dir" --project-name agelto_dev --env-file "$root_dir/.env" -f "$root_dir/docker-compose.yml")
testing_app=("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app)
agent_testing_app=("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres -e CODEX_THREAD_ID=p11-isolation-contract app)
focused_filter='TestEnvironmentGuardTest|TestFilesystemIsolationTest|PaoContractTest'

"${testing_app[@]}" php artisan test --no-tia --compact --filter="$focused_filter"
"${testing_app[@]}" ./vendor/bin/pest --parallel --processes=2 --no-tia --compact --filter="$focused_filter"
"${testing_app[@]}" ./vendor/bin/pest --parallel --processes=4 --no-tia --compact --filter="$focused_filter"

if find "$root_dir/storage/framework/testing" -mindepth 1 -maxdepth 1 -type d -print -quit | grep -q .; then
    echo 'parallel/serial teardown left a process root behind' >&2
    exit 1
fi

expect_agent_json() {
    local result="$1"
    local output="$2"
    python3 - "$result" "$output" <<'PY'
import json
import sys

expected, path = sys.argv[1:]
payload = json.loads(open(path, encoding='utf-8').read())
if payload.get('result') != expected or not isinstance(payload.get('tool'), str):
    raise SystemExit(f'PAO schema mismatch: {payload!r}')
PY
}

"${agent_testing_app[@]}" php artisan test --no-tia --filter=PaoContractTest >"$temp_dir/pest-success.json"
expect_agent_json passed "$temp_dir/pest-success.json"

if "${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres -e CODEX_THREAD_ID=p11-isolation-contract -e P11_PAO_SABOTAGE=1 app php artisan test --no-tia --filter=PaoContractTest >"$temp_dir/pest-failure.json"; then
    echo 'PAO sabotage unexpectedly passed' >&2
    exit 1
fi
expect_agent_json failed "$temp_dir/pest-failure.json"

"${agent_testing_app[@]}" ./vendor/bin/paratest --configuration=docker/tests/pao-paratest.xml --processes=2 >"$temp_dir/paratest-success.json"
expect_agent_json passed "$temp_dir/paratest-success.json"

"${agent_testing_app[@]}" ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G >"$temp_dir/phpstan-success.json"
expect_agent_json passed "$temp_dir/phpstan-success.json"

"${agent_testing_app[@]}" php artisan test --no-tia --filter=PaoContractTest >"$temp_dir/artisan-success.json"
expect_agent_json passed "$temp_dir/artisan-success.json"

"${agent_testing_app[@]}" php artisan about >"$temp_dir/artisan-human.txt"
rg -F 'Laravel Version' "$temp_dir/artisan-human.txt" >/dev/null
if python3 - "$temp_dir/artisan-human.txt" <<'PY'
import json
import sys

try:
    json.loads(open(sys.argv[1], encoding='utf-8').read())
except json.JSONDecodeError:
    raise SystemExit(0)

raise SystemExit('generic Artisan command unexpectedly used PAO JSON')
PY
then
    :
else
    exit 1
fi

make quality
make documentation:check
python3 "$root_dir/plans/verify-citations.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --strict

marketplace_root=/home/vostrikov/projects/packages/swissknifeman
python3 "$marketplace_root/packages/task/scripts/plan-lint.py" "$plan_dir"
python3 "$marketplace_root/packages/task/scripts/plan-views.py" state --all --plan-dir "$plan_dir" --check
python3 "$marketplace_root/packages/task/scripts/plan-views.py" decisions --plan-dir "$plan_dir" --check
python3 "$marketplace_root/packages/task/scripts/plan-views.py" status --plan-dir "$plan_dir" --check

echo 'P11 test isolation contract passed'
