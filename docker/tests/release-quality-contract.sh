#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

test -f tests/performance-budget.json
test -x docker/scripts/collect-release-quality-evidence.sh
test -x docker/scripts/check-release-quality.py

rg -q '^\*\*Status:\*\* 🟢 Done$' plans/2026.08.09-RELEASE-READINESS/phases/P11/P11.5.state.md
rg -q '^\| D42 .*\| Covered \|' plans/2026.08.09-RELEASE-READINESS/artifacts/P11-risk-test-matrix.md
rg -q 'pest\(\)->tia\(\)->locally\(\)->baselined\(\)' tests/Pest.php
rg -q -- '--ci --no-tia' docker/scripts/collect-release-quality-evidence.sh
! rg -q -- '--tia|test:edit|cache hit|replay' docker/scripts/collect-release-quality-evidence.sh
rg -q 'random_order_seeds' tests/performance-budget.json
rg -q 'selected_mode' tests/performance-budget.json
rg -q 'release-quality-contract\.sh' .github/workflows/ci.yml
rg -q 'collect-release-quality-evidence\.sh' .github/workflows/ci.yml

if [ "${RELEASE_QUALITY_EXECUTE:-0}" = 1 ]; then
    bash docker/scripts/collect-release-quality-evidence.sh
else
    echo 'Release-quality contract structure passed; set RELEASE_QUALITY_EXECUTE=1 to collect executed evidence.'
fi
