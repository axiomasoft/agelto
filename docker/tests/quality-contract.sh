#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

test ! -e phpstan-baseline.neon
rg -q '^\s*level:\s*max\s*$' phpstan.neon
! rg -q 'excludePaths|ignoreErrors|baseline' phpstan.neon

make -n test-php | rg -q 'artisan test --compact --no-tia'
make -n quality | rg -q 'docker/tests/quality-contract.sh'

docker compose exec -T app composer audit --locked --no-interaction
docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec -T app ./vendor/bin/pint --test
QUALITY_REMEDIATION_NESTED=1 bash docker/tests/quality-remediation-contract.sh
make test-php
