#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root_dir"

ci_workflow=.github/workflows/ci.yml
tia_workflow=.github/workflows/tia-baseline.yml
core_setup=.github/actions/setup-core-test/action.yml
vendor_setup=.github/actions/setup-vendors/action.yml

for required_file in "$ci_workflow" "$tia_workflow" "$core_setup" "$vendor_setup" .github/CODEOWNERS docker/scripts/coverage-gate.py; do
    test -f "$required_file" || { echo "Missing CI carrier: $required_file" >&2; exit 1; }
done

docker run --rm -v "$root_dir:/workspace:ro" -w /workspace --entrypoint actionlint \
    rhysd/actionlint@sha256:ef8299f97635c4c30e2298f48f30763ab782a4ad2c95b744649439a039421e36 \
    -no-color .github/workflows/ci.yml .github/workflows/tia-baseline.yml

grep -q '^  pull_request:$' "$ci_workflow"
grep -q '^  push:$' "$ci_workflow"
grep -q '^permissions:$' "$ci_workflow"
grep -q '^  contents: read$' "$ci_workflow"
grep -q '^concurrency:$' "$ci_workflow"
grep -q '^  cancel-in-progress: true$' "$ci_workflow"
! grep -rnE 'pull_request_target|write-all|continue-on-error|verify:[[:space:]]*false|secrets\.[A-Za-z_]+.*cache|cache.*secrets' .github/workflows

python3 - <<'PY'
import re
from pathlib import Path

for path in [*Path('.github/workflows').glob('*.yml'), *Path('.github/actions').glob('*/action.yml')]:
    for number, line in enumerate(path.read_text().splitlines(), start=1):
        if 'uses:' not in line:
            continue
        reference = line.split('uses:', 1)[1].split('#', 1)[0].strip()
        if reference.startswith('./'):
            continue
        if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)?@[0-9a-f]{40}', reference):
            raise SystemExit(f'{path}:{number}: action is not pinned to a full SHA')
PY

grep -Eq 'actions/cache/restore@[0-9a-f]{40}' "$core_setup"
grep -Eq 'actions/cache/save@[0-9a-f]{40}' "$core_setup"
grep -Fq "github.event_name == 'push'" "$ci_workflow"
grep -Eq "runner\.os.*runner\.arch.*hashFiles\('composer\.lock', 'pnpm-lock\.yaml', 'pnpm-workspace\.yaml'\)" "$core_setup"
grep -Fq 'make core-test-up' "$core_setup"
grep -Fq 'vendor_manifest.py --rows' "$vendor_setup"
grep -Fq 'path: local/vendors/azguard' "$vendor_setup"
grep -Fq 'path: local/vendors/proxyma' "$vendor_setup"
[[ $(grep -Fc 'persist-credentials: false' "$vendor_setup") -eq 2 ]]

python3 - <<'PYCODE'
import re
from pathlib import Path

workflows = Path('.github/workflows')
ci = (workflows / 'ci.yml').read_text()
tia = (workflows / 'tia-baseline.yml').read_text()
jobs = dict(re.findall(r'^  ([a-z][a-z0-9-]+):\n((?:^ {4,}.*\n|^\n)*)', ci.split('jobs:\n', 1)[1], re.M))
for name, target in {'quality': 'core-quality', 'tests': 'core-test-shard',
                     'release-quality': 'core-test-release', 'faults': 'core-test-faults',
                     'load': 'core-test-load'}.items():
    assert './.github/actions/setup-core-test' in jobs[name], f'{name} must boot the isolated rig'
    assert f'run: make {target}' in jobs[name], f'{name} must run {target}'
for event in ('schedule', 'workflow_dispatch', 'release'):
    for name in ('release-quality', 'load'):
        condition = re.search(r'^    if: (.*)$', jobs[name], re.M).group(1)
        assert f"github.event_name == '{event}'" in condition
        assert "'pull_request'" not in condition and "'push'" not in condition
assert "if: github.event_name != 'pull_request'" in jobs['faults']
assert 'shard: [1, 2, 3, 4]' in jobs['tests'] and 'max-parallel: 4' in jobs['tests']
assert "SHARD: ${{ matrix.shard }}/4" in jobs['tests']
assert 'ENV_FILE: .env.example' in ci and 'ENV_FILE: .env.example' in tia
assert 'run: make bootstrap MODE=dev' not in ci + tia
assert 'make core-test-tia' in tia and './.github/actions/setup-core-test' in tia
# DAG: path routing -> cheap preflight -> rig jobs -> one required gate.
assert 'dorny/paths-filter@' in jobs['changes']
for name in ('quality', 'tests', 'faults', 'e2e'):
    assert re.search(r'^    needs: \[changes, preflight\]$', jobs[name], re.M), f'{name} must wait for preflight'
for name in ('release-quality', 'load'):
    assert re.search(r'^    needs: preflight$', jobs[name], re.M), f'{name} must wait for preflight'
for step in ('ci-contract.sh', 'unittest discover -s docker/tests/process-core', 'pint --test', 'phpstan analyse'):
    assert step in jobs['preflight'], f'preflight must run {step}'
gate = jobs['ci-gate']
assert '    if: always()' in gate and 'toJSON(needs)' in gate
declared = set(jobs) - {'ci-gate'}
gate_needs = set(re.search(r'^    needs: \[(.*)\]$', gate, re.M).group(1).split(', '))
assert gate_needs == declared, f'ci-gate must need every job: {sorted(declared - gate_needs)}'
assert all('timeout-minutes:' in body for body in jobs.values()), 'every job needs a timeout'
PYCODE
grep -Fq 'coverage_contract' docker/scripts/check-release-quality.py
grep -Fq 'DocsIntegrityTest.php' docker/tests/process-core/rig.py
grep -Fq '"audit", "--locked", "--no-interaction"' docker/tests/process-core/rig.py
grep -Fq 'clean-install-e2e.sh' "$ci_workflow"
grep -Fq 'E2E_ARTIFACT_DIR: .ci-artifacts' "$ci_workflow"
grep -Fq 'E2E_ARTIFACT_DIR' docker/tests/clean-install-e2e.sh
grep -Fq 'export IMAGE_TAG="$image_tag"' docker/tests/clean-install-e2e.sh
grep -Fq 'playwright-report/index.html' "$ci_workflow"
grep -Fq 'playwright-report/**' "$ci_workflow"
grep -Fq 'test-results/**' "$ci_workflow"
[[ $(grep -Fc 'uses: ./.github/actions/setup-vendors' "$ci_workflow") -eq 3 ]]
grep -Fq 'make image:build' "$ci_workflow"
grep -Fq 'docker build --build-arg SOURCE_SHA=${{ github.sha }} -t agelto/postgres:' "$ci_workflow"
grep -Fq 'pnpm audit --audit-level=high' "$ci_workflow"
[[ $(grep -Ec 'aquasecurity/trivy-action@[0-9a-f]{40}' "$ci_workflow") -eq 6 ]]
grep -Fq 'include-hidden-files: true' "$tia_workflow"
grep -Fq 'pest-tia-baseline' "$tia_workflow"
grep -Fq 'baselined()' tests/Pest.php
grep -q 'test.*junit' docker/Makefile
grep -q 'test.*tia-baseline-export' docker/Makefile
grep -Fq -- '--ci --no-tia' docker/Makefile

! grep -Eq 'AZGUARD_CI_TOKEN|ci-private-dependency-preflight' "$ci_workflow" "$tia_workflow"

grep -Fq 'prod:storage:init' docker/tests/clean-install-e2e.sh
grep -Eq 'vendor_manifest\.py.*--rows' docker/tests/clean-install-e2e.sh
if grep -q 'estimates/files/.*\.gsfx' docker/compose.core-test.yml; then
    echo 'The isolated rig must use the tracked GSFX fixture' >&2
    exit 1
fi


echo 'CI contract passed'
