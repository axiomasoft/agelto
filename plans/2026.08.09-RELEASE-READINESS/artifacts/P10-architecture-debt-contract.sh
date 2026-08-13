#!/usr/bin/env bash
set -Eeuo pipefail

readonly PLAN_ID='2026.08.09-RELEASE-READINESS'
readonly PLAN_DIR="plans/${PLAN_ID}"
readonly REPORT="${PLAN_DIR}/findings/P10-architecture-debt-audit.md"
readonly BASELINE_HEAD='469acf640fc95944a3accc378445190dbca2a9ba'

fail() {
    printf 'P10.3 architecture/debt contract: FAIL — %s\n' "$1" >&2
    exit 1
}

require() {
    rg -Fq -- "$1" "$REPORT" || fail "missing report evidence: $1"
}

cd "$(git rev-parse --show-toplevel)"

[[ -f "$REPORT" ]] || fail 'report is missing'
[[ -x "$0" ]] || fail 'carrier is not executable'
[[ "$(sed -n 's/^\*\*Status:\*\* //p' "${PLAN_DIR}/phases/P10/P10.2.state.md")" == '🟢 Done' ]] \
    || fail 'P10.2 is not terminal green'

git diff --quiet "$BASELINE_HEAD" -- app config routes resources/views bootstrap composer.json composer.lock \
    || fail 'product/config tree differs from P10.1 baseline'
[[ -z "$(git status --porcelain=v1 -- app config routes resources/views bootstrap composer.json composer.lock)" ]] \
    || fail 'product/config scope is dirty'

for path in \
    app config routes database/migrations tests \
    vendor/axioma-studio/azguard-core vendor/axioma-studio/azguard-filament; do
    [[ -e "$path" ]] || fail "inventory root is unavailable: $path"
done

for evidence in \
    '## Method and boundary' \
    'Static analysis' \
    'Git hotspot/history' \
    'Tests and coverage seams' \
    'Caller / route / config inventory' \
    '## Debt register' \
    'P10.3-D01' \
    'P10.3-D02' \
    'Primary type' \
    'Impact' \
    'Effort' \
    'Owner / trigger' \
    'Disposition' \
    'Regression seam' \
    '## Accepted / false-positive ledger' \
    '## Release-blocker boundary' \
    'P10.2-S01' \
    'P10.2-S03' \
    'P10.5 handoff'; do
    require "$evidence"
done

# Static-analysis and caller proof for D01: no consumer exists outside its declaration.
[[ "$(rg -n --glob '*.php' 'GracefullyStoppable' app tests --glob '!app/Console/Concerns/GracefullyStoppable.php' | wc -l | tr -d ' ')" == '0' ]] \
    || fail 'GracefullyStoppable has a consumer; D01 needs reclassification'
rg -q '^trait GracefullyStoppable$' app/Console/Concerns/GracefullyStoppable.php
git log --format='%H' --follow -- app/Console/Concerns/GracefullyStoppable.php | head -n 1 | grep -q '^6d70a1f2'

# D02 must remain documentation-only: source and schema prove the historical defect was resolved.
! rg -n --glob '*.php' 'section_number' \
    app/Repositories/Procurement/Analyzers/Estimate \
    app/Services/Procurement/Analyzers/Estimate
rg -Fq "expect(\$columns)->not->toHaveKey('section_number')" tests/Feature/Procurement/Estimate/EstimateSchemaTest.php
git cat-file -e '576794be^{commit}'
git merge-base --is-ancestor 576794be HEAD

# One implementation is deliberate at the Llm/Procurement boundary, not speculative abstraction.
rg -q 'interface OutboundProxy' app/Services/Llm/Contracts/OutboundProxy.php
rg -q 'implements OutboundProxy' app/Services/Procurement/Proxy/PoolOutboundProxy.php
rg -q 'private OutboundProxy \$outboundProxy' app/Services/Llm/Providers/PrismGeminiProvider.php
rg -q 'new class implements OutboundProxy' tests/Unit/Llm/PrismGeminiProviderTest.php

docker compose exec -T app ./vendor/bin/pest \
    tests/Unit/Architecture/DomainLayoutTest.php \
    tests/Unit/Architecture/JobRetryContractGuardTest.php \
    tests/Feature/Procurement/Estimate/EstimateSchemaTest.php \
    tests/Feature/Crawler/ProxyBindingResolverTest.php \
    tests/Feature/Llm/GeminiProxyRoutingTest.php

task_root="$(codex plugin marketplace list --json | python3 -c '
import json
import pathlib
import sys

roots = [
    item["root"]
    for item in json.load(sys.stdin).get("marketplaces", [])
    if item.get("name") == "swissknifeman"
    and (pathlib.Path(item["root"]) / "packages/task/lib/task").is_dir()
]
len(roots) == 1 or sys.exit("expected exactly one Swissknifeman marketplace root")
print(roots[0])
')"

python3 "$task_root/packages/task/scripts/plan-lint.py" "$PLAN_DIR"
python3 "$task_root/packages/task/scripts/plan-views.py" state --all --plan-dir "$PLAN_DIR" --check
python3 "$task_root/packages/task/scripts/plan-views.py" decisions --plan-dir "$PLAN_DIR" --check
python3 "$task_root/packages/task/scripts/plan-views.py" status --check --plan-dir "$PLAN_DIR"
python3 plans/verify-citations.py "$PLAN_DIR"

printf '%s\n' 'P10.3 architecture/debt contract: PASS — two deferred XS debts, no new release blocker, product/config tree unchanged.'
