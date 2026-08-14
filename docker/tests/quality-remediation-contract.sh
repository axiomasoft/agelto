#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

matrix=plans/2026.08.09-RELEASE-READINESS/artifacts/P10-remediation-matrix.md
test -f "$matrix"

for audit_id in P10.2-S01 P10.2-S02 P10.2-S03 P10.3-D01 P10.3-D02 P10.4-R01 P10.4-R02 P10.4-T01; do
    rg -q "| ${audit_id} |" "$matrix"
done

! rg -q 'AI_GEMINI_PROXY|env-фолбэк' app/Services/Procurement/Proxy config/ai.php
! rg -q "\['alias', 'set'.*\['key'\].*\['secret'\]" app/Services/Storage/MinioLifecycleManager.php
! test -e app/Console/Concerns/GracefullyStoppable.php
test ! -e phpstan-baseline.neon
rg -q '^\s*level:\s*max\s*$' phpstan.neon
! rg -q 'excludePaths|ignoreErrors|baseline' phpstan.neon

make test-preflight

docker compose exec -T app php artisan test \
    tests/Feature/Llm/GeminiProxyRoutingTest.php \
    tests/Feature/Infra/StorageLifecycleTest.php \
    tests/Feature/Procurement/Ai/AnalysisStoreRepositoryConcurrencyTest.php \
    tests/Unit/Llm/OpenAiCompatibleProviderTest.php

if [[ "${P10_REMEDIATION_NESTED:-}" != '1' ]]; then
    docker compose exec -T app composer audit --locked --no-interaction
    docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
    docker compose exec -T app ./vendor/bin/pint --test
    make test-php

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
print(roots[0] + "/packages/task")
')"
    plan=plans/2026.08.09-RELEASE-READINESS
    python3 "$task_root/scripts/plan-lint.py" "$plan"
    python3 "$task_root/scripts/plan-views.py" state --all --plan-dir "$plan" --check
    python3 "$task_root/scripts/plan-views.py" decisions --plan-dir "$plan" --check
    python3 "$task_root/scripts/plan-views.py" status --plan-dir "$plan" --check
    python3 plans/verify-citations.py --strict "$plan"

    test -z "$(git status --porcelain=v1 -- app config database routes resources tests \
        composer.json composer.lock phpstan.neon rector.php Makefile docker/Makefile \
        docker/scripts docker/tests)"
fi
