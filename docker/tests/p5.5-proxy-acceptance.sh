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

"${compose[@]}" exec -T app php artisan migrate --env=testing --force
"${compose[@]}" exec -T app php artisan test --no-tia \
    tests/Unit/Crawler/ProxyResolverTest.php \
    tests/Unit/Crawler/ProxyLeaseTest.php \
    tests/Unit/Crawler/ProxyCircuitBreakerTest.php \
    tests/Feature/Crawler/CrawlerProxySettingsTest.php \
    tests/Feature/Crawler/ProxyBindingResolverTest.php \
    tests/Feature/Crawler/ProxyOutcomeConcurrencyTest.php \
    tests/Feature/Crawler/CsvSourceMethodTest.php \
    tests/Feature/Crawler/CardCollectDetailsTest.php \
    tests/Feature/Document/DocumentDownloaderTest.php \
    tests/Feature/Llm/GeminiProxyRoutingTest.php \
    tests/Feature/Filament/CrawlerProxySettingsPageTest.php \
    tests/Feature/Filament/CrawlerFormTest.php
"${compose[@]}" exec -T app php artisan migrate:rollback --env=testing --force --step=1
"${compose[@]}" exec -T app php artisan migrate --env=testing --force
# Focused feature tests above legitimately create fixture rows.  The final release
# suite must start from a clean, preflight-guarded *_test schema so seeders are not
# evaluated against rows left by another test class in this carrier.
"${compose[@]}" exec -T app php artisan migrate:fresh --env=testing --force
"${compose[@]}" exec -T app php artisan test --no-tia
"${compose[@]}" exec -T app vendor/bin/pint --test \
    app/Dto/Procurement/Proxy/ProxyLease.php \
    app/Services/Procurement/Eis/CsvSourceMethod.php \
    app/Services/Procurement/Proxy/ProxyResolver.php \
    tests/Unit/Crawler/ProxyResolverTest.php \
    tests/Feature/Crawler/ProxyAutoDisableTest.php \
    tests/Feature/Crawler/ProxyBindingResolverTest.php \
    tests/Feature/Crawler/ProxyOutcomeConcurrencyTest.php \
    tests/Feature/Crawler/CsvSourceMethodTest.php \
    tests/Feature/Document/DocumentDownloaderTest.php \
    tests/Feature/Llm/GeminiProxyRoutingTest.php
"${compose[@]}" exec -T app vendor/bin/phpstan analyse \
    app/Services/Procurement/Proxy/ProxyResolver.php \
    app/Services/Procurement/Eis/CsvSourceMethod.php \
    --no-progress --memory-limit=1G

! rg -n 'PLAYWRIGHT_MCP_PROXY_SERVER' "$root_dir/app" "$root_dir/config"
rg -F 'PLAYWRIGHT_MCP_PROXY_SERVER' "$root_dir/scripts/playwright-mcp.sh" >/dev/null
! rg -n '\buse_proxy\b' "$root_dir/app" "$root_dir/config"
rg -F "env('AI_GEMINI_PROXY')" "$root_dir/config/ai.php" >/dev/null
rg -F "config('ai.gemini.proxy')" "$root_dir/app/Services/Procurement/Proxy/PoolOutboundProxy.php" >/dev/null

task_root=$(codex plugin marketplace list --json | python3 -c '
import json
import pathlib
import sys

roots = [
    item["root"]
    for item in json.load(sys.stdin).get("marketplaces", [])
    if item.get("name") == "swissknifeman"
    and (pathlib.Path(item["root"]) / "packages/task/lib/task").is_dir()
]
if len(roots) != 1:
    raise SystemExit("task runtime unavailable: expected one swissknifeman marketplace")
print(roots[0])
')

python3 "$task_root/packages/task/scripts/plan-lint.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --baseline HEAD
python3 "$task_root/packages/task/scripts/plan-views.py" state --all --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/packages/task/scripts/plan-views.py" decisions --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/packages/task/scripts/plan-views.py" status --all --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/packages/task/scripts/plan-views.py" bundle --item P5.5 --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$root_dir/plans/verify-citations.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --strict
git -C "$root_dir" diff --check

echo 'P5.5 proxy acceptance passed'
