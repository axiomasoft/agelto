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

grep -Fx '**Status:** 🟢 Done' "$root_dir/plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.2.state.md" >/dev/null
"${testing_app[@]}" php artisan migrate --force
"${testing_app[@]}" php artisan test --no-tia \
    tests/Feature/Procurement/StatusMappingApplicabilityMigrationTest.php \
    tests/Feature/Procurement/StatusMappingTest.php \
    tests/Feature/Procurement/StatusMappingSeederTest.php
"${testing_app[@]}" vendor/bin/pint --test \
    app/Enums/Procurement/StatusMappingApplicability.php \
    app/Models/Procurement/Platform/StatusMapping.php \
    app/Dto/Procurement/StatusResolution.php \
    database/factories/Procurement/Platform/StatusMappingFactory.php \
    database/migrations/2026_08_09_100092_add_applicability_to_prc_status_mappings_table.php \
    tests/Feature/Procurement/StatusMappingApplicabilityMigrationTest.php

task_root=${TASK_ROOT:-/home/vostrikov/.codex/plugins/cache/swissknifeman/task/0.12.0}
python3 "$task_root/scripts/plan-lint.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --baseline HEAD --gate new
python3 "$task_root/scripts/plan-views.py" --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" state --all --check
python3 "$task_root/scripts/plan-views.py" --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" decisions --check
python3 "$task_root/scripts/plan-views.py" --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" status --check
python3 "$task_root/scripts/plan-views.py" --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS" bundle --item P8.3 --check
python3 "$root_dir/plans/verify-citations.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --strict
git -C "$root_dir" diff --check

echo 'P8.3 status mapping contract passed'
