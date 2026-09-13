#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
compose=(docker compose --project-directory "$root_dir" --project-name "${COMPOSE_PROJECT_NAME:-agelto_dev}" --env-file "$root_dir/${ENV_FILE:-.env}" -f "$root_dir/docker-compose.yml")
test_database=$(sed -n 's/.*<env name="DB_DATABASE" value="\([a-z0-9_]*\)".*/\1/p' "$root_dir/phpunit.xml" | head -n 1)
plan_dir="$root_dir/plans/archive/2026.08.09-RELEASE-READINESS"
task_root=${TASK_ROOT:-/home/vostrikov/.codex/plugins/cache/swissknifeman/task/0.12.0}

[[ "$test_database" =~ ^[a-z0-9_]+_test$ ]] || {
    echo "Unsafe test database name: ${test_database:-missing}" >&2
    exit 2
}

grep -Fx '**Status:** 🟢 Done' "$plan_dir/phases/P8/P8.4.state.md" >/dev/null
"${compose[@]}" ps --status running postgres | grep -q postgres
"${compose[@]}" exec -T postgres sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
"${compose[@]}" exec -T postgres sh -lc "psql -U \"\$POSTGRES_USER\" -d postgres -Atqc \"SELECT 1 FROM pg_database WHERE datname = '${test_database}'\"" | grep -Fx 1 >/dev/null

testing_app=("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE="$test_database" app)

pint_paths=(
    database/factories/Procurement/ProcurementFactory.php
    database/factories/Procurement/Platform/RegistryFactory.php
    tests/Feature/Crawler/CsvBlockIngestorTest.php
    tests/Feature/Crawler/DetailAnalysisProcessorTest.php
    tests/Feature/Crawler/PlatformRequestBudgetTest.php
    tests/Feature/Crawler/ProxyBindingResolverTest.php
    tests/Feature/Procurement/ProcurementFactoryTest.php
    tests/Feature/Procurement/RevisionTest.php
    tests/Unit/Architecture/DomainLayoutTest.php
    tests/Unit/Architecture/PlatformExtensionContractTest.php
)

printf 'P8 Pint paths (%d):\n' "${#pint_paths[@]}"
printf ' - %s\n' "${pint_paths[@]}"

"${testing_app[@]}" php artisan migrate --force
"${testing_app[@]}" php artisan test --no-tia \
    tests/Feature/Procurement/ProcurementFactoryTest.php \
    tests/Unit/Crawler/PlatformAdapterRegistryTest.php \
    tests/Unit/Architecture/PlatformExtensionContractTest.php \
    tests/Feature/Procurement/PlatformExtensionContractTest.php \
    tests/Feature/Procurement/StatusMappingApplicabilityMigrationTest.php \
    tests/Feature/Procurement/StatusMappingTest.php \
    tests/Feature/Procurement/StatusMappingSeederTest.php \
    tests/Feature/Filament/CrawlerFormTest.php \
    tests/Feature/Filament/StatusMappingResourceTest.php
"${testing_app[@]}" php artisan config:cache
"${testing_app[@]}" php artisan config:clear
"${testing_app[@]}" vendor/bin/pint --test "${pint_paths[@]}"
"${testing_app[@]}" vendor/bin/phpstan analyse \
    database/factories/Procurement/ProcurementFactory.php \
    database/factories/Procurement/Platform/RegistryFactory.php \
    tests/Feature/Procurement/ProcurementFactoryTest.php \
    tests/Feature/Procurement/PlatformExtensionContractTest.php \
    tests/Unit/Architecture/PlatformExtensionContractTest.php
"${testing_app[@]}" php artisan test --no-tia

python3 - "$root_dir" <<'PY'
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
documents = (
    root / 'docs/dev/platform-extension.md',
    root / 'docs/dev/architecture.md',
    root / 'docs/dev/database.md',
    root / 'docs/workflow/crawling-and-discovery.md',
    root / 'docs/operators/reference/statuses.md',
)
for document in documents:
    for target in re.findall(r'\[[^]]+\]\(([^)#]+)', document.read_text(encoding='utf-8')):
        if not (document.parent / target).resolve().exists():
            raise SystemExit(f'Broken documentation link: {document.relative_to(root)} -> {target}')
PY
! rg -n 'PlatformOnboardingTest|plug-in loading|zero-code integration' \
    "$root_dir/docs/dev/platform-extension.md" \
    "$root_dir/docs/dev/architecture.md" \
    "$root_dir/docs/dev/database.md" \
    "$root_dir/docs/workflow/crawling-and-discovery.md" \
    "$root_dir/docs/operators/reference/statuses.md"

python3 "$task_root/scripts/plan-lint.py" "$plan_dir"
python3 "$task_root/scripts/plan-views.py" --plan-dir "$plan_dir" state --all --check
python3 "$task_root/scripts/plan-views.py" --plan-dir "$plan_dir" bundle --all --check
python3 "$task_root/scripts/plan-views.py" --plan-dir "$plan_dir" decisions --check
python3 "$task_root/scripts/plan-views.py" --plan-dir "$plan_dir" status --check
python3 "$root_dir/plans/verify-citations.py" "$plan_dir" --strict
git -C "$root_dir" diff --check

echo 'P8 platform extension contract passed'
