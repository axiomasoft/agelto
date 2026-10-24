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
"${testing_app[@]}" php artisan test --no-tia \
    tests/Unit/Crawler/ProxyResolverTest.php \
    tests/Unit/Crawler/ProxyLeaseTest.php \
    tests/Feature/Crawler/CrawlerProxySettingsTest.php \
    tests/Feature/Crawler/ProxyBindingResolverTest.php \
    tests/Feature/Crawler/CsvSourceMethodTest.php \
    tests/Feature/Crawler/CsvBlockIngestorTest.php \
    tests/Feature/Document/DocumentDownloaderTest.php
"${testing_app[@]}" php artisan migrate:rollback --force --step=1
"${testing_app[@]}" php artisan migrate --force

! rg -n '\buse_proxy\b' "$root_dir/app" "$root_dir/config"
rg -n 'proxy_source' \
    "$root_dir/app/Dto/Procurement/Crawler/ListingBatch.php" \
    "$root_dir/app/Services/Procurement/CsvBlockIngestor.php" \
    "$root_dir/app/Services/Procurement/FetchLogger.php" >/dev/null
rg -F 'ProxyResolver' \
    "$root_dir/app/Services/Platforms/Eis/Csv/CsvSourceMethod.php" \
    "$root_dir/app/Services/Procurement/DocumentDownloader.php" >/dev/null

"${testing_app[@]}" vendor/bin/pint --test \
    app/Dto/Procurement/Crawler/ListingBatch.php \
    app/Dto/Procurement/Proxy/ProxyLease.php \
    app/Dto/Procurement/Proxy/ProxyRequirements.php \
    app/Enums/Procurement/Proxy/CrawlerProxyMode.php \
    app/Enums/Procurement/Proxy/ProxyRouteSource.php \
    app/Models/Procurement/Crawler/Crawler.php \
    app/Models/Procurement/Crawler/Fetch.php \
    app/Services/Procurement/CsvBlockIngestor.php \
    app/Services/Procurement/DocumentDownloader.php \
    app/Services/Platforms/Eis/Csv/CsvSourceMethod.php \
    app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php \
    app/Services/Procurement/PlatformRequestBudget.php \
    app/Services/Procurement/Proxy/ProxyBindingResolver.php \
    app/Services/Procurement/Proxy/ProxyResolver.php \
    tests/Unit/Crawler/ProxyResolverTest.php \
    tests/Unit/Crawler/ProxyLeaseTest.php \
    tests/Feature/Crawler/CrawlerProxySettingsTest.php \
    tests/Feature/Crawler/ProxyBindingResolverTest.php \
    tests/Feature/Crawler/CsvSourceMethodTest.php \
    tests/Feature/Crawler/CsvBlockIngestorTest.php \
    tests/Feature/Document/DocumentDownloaderTest.php
git -C "$root_dir" diff --check

echo 'P5.3 proxy resolver contract passed'
