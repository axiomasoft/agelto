#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
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
    tests/Feature/Filament/CrawlerProxySettingsPageTest.php \
    tests/Feature/Filament/CrawlerFormTest.php \
    tests/Feature/Filament/ProxyResourceTest.php
"${compose[@]}" exec -T app vendor/bin/pint --test \
    app/Actions/Procurement/Proxy/UpdateCrawlerProxySettingsAction.php \
    app/Dto/Actions/Procurement/Proxy/UpdateCrawlerProxySettingsCommand.php \
    app/Filament/Pages/CrawlerProxySettingsPage.php \
    app/Filament/Resources/Procurement/Crawler/Crawlers/Schemas/CrawlerForm.php \
    app/Models/Procurement/Crawler/Crawler.php \
    tests/Feature/Filament/CrawlerProxySettingsPageTest.php \
    tests/Feature/Filament/CrawlerFormTest.php \
    tests/Feature/Filament/ProxyResourceTest.php

! rg -n 'username|password|proxy_url|default.*url' \
    "$root_dir/app/Filament/Pages/CrawlerProxySettingsPage.php" \
    "$root_dir/resources/views/filament/pages/crawler-proxy-settings-page.blade.php"
! rg -n 'Filament\\(Tables|Forms)\\Actions' \
    "$root_dir/app/Filament/Pages/CrawlerProxySettingsPage.php" \
    "$root_dir/app/Filament/Resources/Procurement/Crawler/Crawlers/Schemas/CrawlerForm.php"
! rg -n 'defaultProxyId\s*=|->save\(' "$root_dir/app/Filament/Pages/CrawlerProxySettingsPage.php"
rg -F 'UpdateCrawlerProxySettingsAction::class' "$root_dir/app/Filament/Pages/CrawlerProxySettingsPage.php" >/dev/null
git -C "$root_dir" diff --check

echo 'P5.4 proxy Filament contract passed'
