#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

docker compose exec -T app php artisan test \
    tests/Feature/Health/ApplicationReadinessTest.php \
    tests/Feature/Doctor/ApplicationReadinessDiagnosticTest.php \
    tests/Feature/Doctor/ReleaseReadinessDiagnosticsTest.php
