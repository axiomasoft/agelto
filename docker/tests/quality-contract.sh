#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

test ! -e phpstan-baseline.neon
rg -q '^\s*level:\s*max\s*$' phpstan.neon
! rg -q 'excludePaths|ignoreErrors|baseline' phpstan.neon
test -f rector.php
rg -q '"rector/rector": "2\.6\.2"' composer.json
rg -q '"driftingly/rector-laravel": "2\.5\.0"' composer.json

make -n test-php | rg -q 'artisan test --compact --no-tia'
make -n quality | rg -q 'docker/tests/quality-contract.sh'

docker compose exec -T app composer audit --locked --no-interaction
docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec -T app ./vendor/bin/pint --test
docker compose exec -T app ./vendor/bin/rector process --dry-run --no-progress-bar
QUALITY_REMEDIATION_NESTED=1 bash docker/tests/quality-remediation-contract.sh
make test-php
