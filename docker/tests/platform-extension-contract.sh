#!/usr/bin/env bash
set -euo pipefail

if ! getent hosts postgres >/dev/null; then
  exec docker compose exec -T app bash docker/tests/platform-extension-contract.sh
fi

php artisan test tests/Unit/Crawler/PlatformAdapterRegistryTest.php tests/Unit/Architecture/PlatformExtensionContractTest.php tests/Feature/Procurement/PlatformExtensionContractTest.php tests/Feature/Procurement/StatusMappingTest.php tests/Feature/Procurement/StatusMappingApplicabilityMigrationTest.php tests/Feature/Filament/StatusMappingResourceTest.php
php artisan config:cache
php artisan config:clear
php artisan test --no-tia
vendor/bin/pint --test
vendor/bin/phpstan analyse
