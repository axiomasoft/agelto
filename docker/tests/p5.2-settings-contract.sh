#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
compose=(docker compose)

"${compose[@]}" exec -T app php artisan config:clear
"${compose[@]}" exec -T app php artisan migrate --env=testing --force
"${compose[@]}" exec -T app php artisan test --no-tia tests/Feature/Crawler/CrawlerProxySettingsStorageTest.php
"${compose[@]}" exec -T app php artisan config:cache
"${compose[@]}" exec -T app php artisan config:clear
"${compose[@]}" exec -T app vendor/bin/pint --test \
    app/Settings/Procurement/CrawlerProxySettings.php \
    app/Services/Procurement/Proxy/CrawlerProxySettingsResolver.php \
    database/migrations/2026_08_09_099999_create_prc_settings_table.php \
    database/settings/2026_08_09_100000_create_crawler_proxy_settings.php \
    tests/Feature/Crawler/CrawlerProxySettingsStorageTest.php
git -C "$root_dir" diff --check
