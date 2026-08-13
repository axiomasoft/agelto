#!/usr/bin/env bash
set -Eeuo pipefail

readonly PLAN_ID='2026.08.09-RELEASE-READINESS'
readonly PLAN_DIR="plans/${PLAN_ID}"
readonly REPORT="${PLAN_DIR}/findings/P10-production-readiness-audit.md"
readonly TEST_DATABASE='agelto_test'

fail() {
    printf 'P10.4 production-readiness contract: FAIL — %s\n' "$1" >&2
    exit 1
}

require_report() {
    rg -Fq -- "$1" "$REPORT" || fail "missing report evidence: $1"
}

cd "$(git rev-parse --show-toplevel)"

[[ -f "$REPORT" ]] || fail 'report is missing'
[[ -x "$0" ]] || fail 'carrier is not executable'
[[ "$(sed -n 's/^\*\*Status:\*\* //p' "${PLAN_DIR}/phases/P10/P10.3.state.md")" == '🟢 Done' ]] \
    || fail 'P10.3 is not terminal green'
git diff --quiet -- app config database routes resources/views bootstrap composer.json composer.lock \
    || fail 'product/config scope is dirty'

[[ "$TEST_DATABASE" =~ ^[a-z0-9_]+_test$ ]] || fail 'unsafe test database name'
docker compose ps --status running postgres | rg -q 'postgres' || fail 'postgres is not running'
docker compose exec -T postgres sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null
docker compose exec -T postgres sh -lc 'psql -U "$POSTGRES_USER" -d postgres -Atqc "SELECT 1 FROM pg_database WHERE datname = '\''agelto_test'\''"' | rg -xq '1' \
    || fail 'isolated test database is absent'

for root in database/migrations app/Models app/Jobs config tests; do
    [[ -e "$root" ]] || fail "inventory root is unavailable: $root"
done

migrations="$(rg --files database/migrations -g '*.php' | LC_ALL=C sort)"
models="$(rg --files app/Models -g '*.php' | LC_ALL=C sort)"
jobs="$(rg --files app/Jobs -g '*.php' | LC_ALL=C sort)"
[[ "$(printf '%s\n' "$migrations" | sed '/^$/d' | wc -l | tr -d ' ')" == '71' ]] || fail 'migration inventory changed'
[[ "$(printf '%s\n' "$models" | sed '/^$/d' | wc -l | tr -d ' ')" == '56' ]] || fail 'model inventory changed'
[[ "$(printf '%s\n' "$jobs" | sed '/^$/d' | wc -l | tr -d ' ')" == '7' ]] || fail 'queue-job inventory changed'
printf 'P10.4 inventory: migrations=71 models=56 jobs=7\n'
printf '%s\n' "$migrations" "$models" "$jobs" >/dev/null

rg -q 'prc_ai_analyses_active_version_step_unique' database/migrations/2026_07_04_100025_create_prc_ai_analyses_table.php
rg -q 'prc_document_versions_parent_archive_entry_path_unique' database/migrations/2026_08_09_100090_add_archive_entry_path_to_prc_document_versions_table.php
rg -q 'DB::afterCommit' app/Actions/Procurement/Screening/ApplyScreeningDispositionAction.php
rg -q 'lockForUpdate' app/Actions/Procurement/Screening/ApplyScreeningDispositionAction.php
rg -q 'isExactRegistryRace' app/Services/Procurement/StatusMappingService.php
rg -q 'ShouldBeUniqueUntilProcessing' app/Jobs/Procurement/Analyzers/Estimate/EstimateExtractionJob.php
rg -q "'tries' => 1" config/horizon.php
rg -Fq "'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360)," config/queue.php
[[ "$(rg -n '\benv\(' app database routes -g '*.php' | wc -l | tr -d ' ')" == '4' ]] \
    || fail 'unexpected production env() inventory'
rg -q 'return env\(\$name, \$default\);' app/Support/SecretValue.php
rg -q '\$value = env\(\$envName\);' app/Services/Llm/Providers/OpenAiCompatibleProvider.php

for evidence in \
    '## Method and full coverage manifest' 'P10.4-R01' 'P10.4-R02' 'release blocker — fix in P10.5' \
    'P10.4-T01' 'D31' 'D34' 'D36' 'config:cache' '56 passed / 201 assertions'; do
    require_report "$evidence"
done

test_env=(-e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 -e "DB_DATABASE=${TEST_DATABASE}")
docker compose exec -T "${test_env[@]}" app php artisan migrate:fresh --force --no-interaction >/dev/null
docker compose exec -T "${test_env[@]}" app php artisan config:clear --no-interaction >/dev/null
docker compose exec -T "${test_env[@]}" app php artisan config:cache --no-interaction >/dev/null
docker compose exec -T "${test_env[@]}" app php artisan config:show queue.default --no-ansi | rg -q 'redis'
docker compose exec -T "${test_env[@]}" app php artisan config:show settings.cache.enabled --no-ansi | rg -q 'false'
docker compose exec -T "${test_env[@]}" app php artisan config:clear --no-interaction >/dev/null

# Run the child-process Crawler seam before ingest: P10.4-T01 records why the reverse
# order is a P11 topology defect, while each production seam remains deterministic here.
docker compose exec -T app ./vendor/bin/pest \
    tests/Feature/Crawler/CrawlerRunManagerTest.php \
    tests/Unit/Architecture/JobRetryContractGuardTest.php \
    tests/Unit/Config/QueueTimeoutInvariantTest.php \
    tests/Feature/Procurement/ProcurementIngestConcurrencyTest.php \
    tests/Feature/Procurement/StatusMappingTest.php \
    tests/Feature/Procurement/Prepare/ArchivePipelineTest.php \
    tests/Feature/Procurement/Pipeline/LeaseTest.php \
    tests/Feature/Procurement/Analyzers/Estimate/EstimateExtractionJobTest.php \
    tests/Feature/Crawler/ProxyOutcomeConcurrencyTest.php \
    tests/Feature/Crawler/CrawlerProxySettingsStorageTest.php

task_root="$(codex plugin marketplace list --json | python3 -c '
import json
import pathlib
import sys
roots = [item["root"] for item in json.load(sys.stdin).get("marketplaces", []) if item.get("name") == "swissknifeman" and (pathlib.Path(item["root"]) / "packages/task/lib/task").is_dir()]
len(roots) == 1 or sys.exit("expected exactly one Swissknifeman marketplace root")
print(roots[0])
')"
python3 "$task_root/packages/task/scripts/plan-lint.py" "$PLAN_DIR"
python3 "$task_root/packages/task/scripts/plan-views.py" state --all --plan-dir "$PLAN_DIR" --check
python3 "$task_root/packages/task/scripts/plan-views.py" decisions --plan-dir "$PLAN_DIR" --check
python3 "$task_root/packages/task/scripts/plan-views.py" status --check --plan-dir "$PLAN_DIR"
python3 plans/verify-citations.py "$PLAN_DIR"

printf '%s\n' 'P10.4 production-readiness contract: PASS — two P10.5 release blockers and one P11 topology finding recorded; product/config tree unchanged.'
