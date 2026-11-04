#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

! rg -q 'AI_GEMINI_PROXY|env-фолбэк' app/Services/Procurement/Proxy config/ai.php
! rg -q "\['alias', 'set'.*\['key'\].*\['secret'\]" app/Services/Storage/MinioLifecycleManager.php
! test -e app/Console/Concerns/GracefullyStoppable.php
test ! -e phpstan-baseline.neon
rg -q '^\s*level:\s*max\s*$' phpstan.neon
! rg -q 'excludePaths|ignoreErrors|baseline' phpstan.neon

make test-preflight

docker compose exec -T app php artisan test \
    tests/Feature/Infra/StorageLifecycleTest.php \
    tests/Unit/Llm/OpenAiCompatibleProviderTest.php
make core-test-file TEST_FILE=tests/Feature/ProcessCore/Network/ProviderOwnershipTest.php
make core-test-file TEST_FILE=tests/Integration/ProcessCore/Concurrency/AnalysisPlanningConcurrencyTest.php

if [[ "${QUALITY_REMEDIATION_NESTED:-}" != '1' ]]; then
    docker compose exec -T app composer audit --locked --no-interaction
    docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
    docker compose exec -T app ./vendor/bin/pint --test
    make test-php
    test -z "$(git status --porcelain=v1 -- app config database routes resources tests \
        composer.json composer.lock phpstan.neon rector.php Makefile docker/Makefile \
        docker/scripts docker/tests)"
fi
