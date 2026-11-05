#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

ci_workflow=.github/workflows/ci.yml
tia_workflow=.github/workflows/tia-baseline.yml

for required_file in "$ci_workflow" "$tia_workflow" .github/dependabot.yml .github/CODEOWNERS docker/scripts/coverage-gate.py; do
    test -f "$required_file" || { echo "Missing CI carrier: $required_file" >&2; exit 1; }
done

docker run --rm -v "$root_dir:/workspace:ro" -w /workspace --entrypoint actionlint \
    rhysd/actionlint@sha256:ef8299f97635c4c30e2298f48f30763ab782a4ad2c95b744649439a039421e36 \
    -no-color .github/workflows/ci.yml .github/workflows/tia-baseline.yml

rg -q '^  pull_request:$' "$ci_workflow"
rg -q '^  push:$' "$ci_workflow"
rg -q '^permissions:$' "$ci_workflow"
rg -q '^  contents: read$' "$ci_workflow"
rg -q '^concurrency:$' "$ci_workflow"
rg -q '^  cancel-in-progress: true$' "$ci_workflow"
! rg -n 'pull_request_target|write-all|continue-on-error|verify:\s*false|secrets\.[A-Za-z_]+.*cache|cache.*secrets' .github/workflows

python3 - <<'PY'
import re
from pathlib import Path

for path in Path('.github/workflows').glob('*.yml'):
    for number, line in enumerate(path.read_text().splitlines(), start=1):
        if 'uses:' not in line:
            continue
        reference = line.split('uses:', 1)[1].split('#', 1)[0].strip()
        if reference.startswith('./'):
            continue
        if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)?@[0-9a-f]{40}', reference):
            raise SystemExit(f'{path}:{number}: action is not pinned to a full SHA')
PY

rg -q 'actions/cache/restore@[0-9a-f]{40}' "$ci_workflow"
rg -q 'actions/cache/save@[0-9a-f]{40}' "$ci_workflow"
rg -q 'if: github.event_name != .pull_request.' "$ci_workflow"
rg -q 'runner\.os.*runner\.arch.*hashFiles\(.composer\.lock.' "$ci_workflow"
rg -q 'runner\.os.*runner\.arch.*hashFiles\(.pnpm-lock.yaml.' "$ci_workflow"
rg -q 'make quality' "$ci_workflow"
rg -q 'make test:junit' "$ci_workflow"
rg -q 'make test:coverage' "$ci_workflow"
[[ $(rg -c 'make bootstrap MODE=dev ENV_FILE=\.env\.example' "$ci_workflow") -eq 4 ]]
rg -q -- '--critical-file app/Services/Procurement/Proxy/ProxyResolver.php' "$ci_workflow"
rg -q 'clean-install-e2e\.sh' "$ci_workflow"
rg -q 'E2E_ARTIFACT_DIR: .ci-artifacts' "$ci_workflow"
rg -q 'E2E_ARTIFACT_DIR' docker/tests/clean-install-e2e.sh
rg -q 'export IMAGE_TAG="\$image_tag"' docker/tests/clean-install-e2e.sh
rg -q 'playwright-report/index\.html' "$ci_workflow"
rg -q 'playwright-report/\*\*' "$ci_workflow"
rg -q 'test-results/\*\*' "$ci_workflow"
[[ $(rg -c 'run: make vendor:sync' "$ci_workflow") -eq 2 ]]
rg -q 'make image:build' "$ci_workflow"
rg -q 'docker build -t agelto/postgres:' "$ci_workflow"
rg -q 'composer audit --locked' "$ci_workflow"
rg -q 'pnpm audit --audit-level=high' "$ci_workflow"
[[ $(rg -c 'aquasecurity/trivy-action@[0-9a-f]{40}' "$ci_workflow") -eq 5 ]]
rg -q 'make bootstrap MODE=dev ENV_FILE=\.env\.example' "$tia_workflow"
rg -q 'make test:tia-refresh' "$tia_workflow"
rg -q 'make test:tia-baseline-export' "$tia_workflow"
rg -q 'include-hidden-files: true' "$tia_workflow"
rg -q 'pest-tia-baseline' "$tia_workflow"
rg -q 'baselined\(\)' tests/Pest.php
rg -q 'test.*junit' docker/Makefile
rg -q 'test.*tia-baseline-export' docker/Makefile
rg -q -- '--ci --no-tia' docker/Makefile

! rg -q 'AZGUARD_CI_TOKEN|ci-private-dependency-preflight' "$ci_workflow" "$tia_workflow"
rg -q 'run: make vendor:sync' "$ci_workflow"


echo 'CI contract passed'
