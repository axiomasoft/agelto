#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
compose=(docker compose --project-directory "$root_dir" --project-name "${COMPOSE_PROJECT_NAME:-agelto_dev}" --env-file "$root_dir/${ENV_FILE:-.env}" -f "$root_dir/docker-compose.yml")
test_database=$(sed -n 's/.*<env name="DB_DATABASE" value="\([a-z0-9_]*\)".*/\1/p' "$root_dir/phpunit.xml" | head -n 1)

[[ "$test_database" =~ ^[a-z0-9_]+_test$ ]] || {
    echo "Unsafe test database name: ${test_database:-missing}" >&2
    exit 2
}

"${compose[@]}" ps --status running postgres | grep -q postgres
"${compose[@]}" exec -T postgres sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
"${compose[@]}" exec -T postgres sh -lc "psql -U \"\$POSTGRES_USER\" -d postgres -Atqc \"SELECT 1 FROM pg_database WHERE datname = '${test_database}'\"" | grep -Fx 1 >/dev/null

testing_app=("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE="$test_database" app)

"${testing_app[@]}" php artisan migrate --force
"${testing_app[@]}" php artisan migrate:status --env=testing --no-interaction | grep -F '100089' | grep -F 'Ran' >/dev/null
"${testing_app[@]}" php artisan migrate:status --env=testing --no-interaction | grep -F '100090' | grep -F 'Ran' >/dev/null
"${testing_app[@]}" php artisan test --no-tia \
    tests/Feature/Procurement/Prepare/GsfxStrategyTest.php \
    tests/Feature/Procurement/Prepare/ScanStrategyTest.php \
    tests/Feature/Procurement/Prepare/SpreadsheetStrategyTest.php \
    tests/Feature/Procurement/Prepare/ArchivePipelineTest.php \
    tests/Feature/Procurement/Prepare/DocumentFormatContractTest.php \
    tests/Unit/Procurement/Prepare/ArchiveEntryValidatorTest.php
"${testing_app[@]}" php artisan test --no-tia
"${testing_app[@]}" vendor/bin/pint --test \
    app/Services/Procurement/Prepare/GsfxStrategy.php \
    app/Services/Procurement/Prepare/PreparationStrategy.php \
    tests/Feature/Procurement/Prepare/GsfxStrategyTest.php \
    tests/Feature/Procurement/Prepare/ScanStrategyTest.php \
    tests/Feature/Procurement/Prepare/SpreadsheetStrategyTest.php

! rg -n 'ZipArchive::extractTo\(' "$root_dir/app"
! rg -n 'indexChild\(|storeRepository|procurementStore' "$root_dir/app/Services/Procurement/Prepare/GsfxStrategy.php"

task_root=${TASK_ROOT:-/home/vostrikov/.codex/plugins/cache/swissknifeman/task/0.12.0}
python3 "$task_root/scripts/plan-lint.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --baseline HEAD
python3 "$task_root/scripts/plan-views.py" state --all --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/scripts/plan-views.py" decisions --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/scripts/plan-views.py" status --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$root_dir/plans/verify-citations.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --strict
git -C "$root_dir" diff --check

echo 'P6.7 release remediation passed'
