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

docker compose exec -T app php artisan test \
    tests/Feature/Llm/GeminiProxyRoutingTest.php \
    tests/Feature/Infra/StorageLifecycleTest.php \
    tests/Feature/Procurement/Ai/AnalysisStoreRepositoryConcurrencyTest.php \
    tests/Unit/Llm/OpenAiCompatibleProviderTest.php
