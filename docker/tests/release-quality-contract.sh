#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

test -f tests/performance-budget.json
test -x docker/scripts/collect-release-quality-evidence.sh
test -x docker/scripts/check-release-quality.py
rg -q 'pest\(\)->tia\(\)->locally\(\)->baselined\(\)' tests/Pest.php
rg -q -- '--ci --no-tia' docker/scripts/collect-release-quality-evidence.sh
! rg -q -- '--tia|test:edit|cache hit|replay' docker/scripts/collect-release-quality-evidence.sh
rg -q 'random_order_seeds' tests/performance-budget.json
rg -q 'selected_mode' tests/performance-budget.json
make -n test:release | rg -q 'release-quality-contract\.sh'
rg -q 'make core-test-release' .github/workflows/ci.yml

if [ "${RELEASE_QUALITY_STRUCTURE_ONLY:-0}" = 1 ]; then
    echo 'Release-quality contract structure passed; unset RELEASE_QUALITY_STRUCTURE_ONLY to collect executed evidence.'
else
    bash docker/scripts/collect-release-quality-evidence.sh
fi
