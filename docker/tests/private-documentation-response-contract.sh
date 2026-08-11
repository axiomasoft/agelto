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

"${compose[@]}" exec -T app php artisan test \
    tests/Unit/Documentation/PrivateDocumentationLocatorTest.php \
    tests/Feature/Documentation/PrivateDocumentationAccessTest.php \
    tests/Feature/Documentation/PrivateDocumentationResponseTest.php
"${compose[@]}" exec -T app vendor/bin/pint --test \
    app/Services/Documentation \
    app/Http/Controllers/Documentation \
    config/documentation.php \
    routes/web.php \
    tests/Unit/Documentation/PrivateDocumentationLocatorTest.php \
    tests/Feature/Documentation/PrivateDocumentationAccessTest.php \
    tests/Feature/Documentation/PrivateDocumentationResponseTest.php
"${compose[@]}" exec -T app vendor/bin/phpstan analyse \
    app/Services/Documentation \
    app/Http/Controllers/Documentation

route_json=$("${compose[@]}" exec -T app php artisan route:list --path=admin/documentation --json)
printf '%s' "$route_json" | php -r '$routes = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(count($routes) === 1 && ($routes[0]["method"] ?? null) === "GET|HEAD" && ($routes[0]["action"] ?? null) === "App\\Http\\Controllers\\Documentation\\PrivateDocumentationController" ? 0 : 1);'
[[ $(grep -Fc 'response()->file(' "$root_dir/app/Http/Controllers/Documentation/PrivateDocumentationController.php") -eq 1 ]]
! rg -n 'Storage::|storage_path\(|public_path\(|X-Accel-Redirect' \
    "$root_dir/app/Http/Controllers/Documentation/PrivateDocumentationController.php" \
    "$root_dir/routes/web.php"
git -C "$root_dir" diff --check

echo 'Private documentation response contract passed'
