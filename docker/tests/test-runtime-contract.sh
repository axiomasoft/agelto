#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
plan_dir="$root_dir/plans/archive/2026.08.09-RELEASE-READINESS"
baseline="$plan_dir/artifacts/P11-test-baseline.md"
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

cd "$root_dir"

test -f "$baseline"
grep -Fx '**Status:** 🟢 Done' "$plan_dir/phases/P11/P11.1.state.md" >/dev/null
rg -q 'pest\(\)->tia\(\)->locally\(\);' tests/Pest.php
! rg -q 'always\(\)|baselined\(\)|filtered\(\)' tests/Pest.php
rg -Fx 'pcov.enabled=0' docker/php/conf.d/pcov.ini >/dev/null
rg -Fx 'pcov.enabled=1' docker/php/conf.d/pcov-dev.ini >/dev/null
rg -F './docker/php/conf.d/pcov-dev.ini:/usr/local/etc/php/conf.d/pcov.ini:ro' docker-compose.yml >/dev/null
test ! -e tests/.pest/shards.json

for target in test:edit test:full test:profile test:coverage test:tia-reset test:tia-refresh test:shard; do
    make -n "$target" SHARD=1/2 >/dev/null
done

make -n test:full | rg -F 'php artisan test --ci --no-tia --compact' >/dev/null
make -n test:profile | rg -F 'php artisan test --ci --no-tia --compact --profile' >/dev/null
make -n test:coverage | rg -F -- '--coverage-clover=storage/app/coverage/clover.xml' >/dev/null
make -n test:tia-refresh | rg -F 'php artisan test --tia --fresh --ci --compact' >/dev/null
make -n test:shard SHARD=1/2 | rg -F -- '--shard=1/2' >/dev/null
rg -F 'make test:edit' docs/03_Dev/commands.md docs/03_Dev/process/testing.md >/dev/null
rg -F 'make test:full' docs/03_Dev/commands.md docs/03_Dev/process/testing.md >/dev/null

make test-preflight
make test:tia-reset
make test:tia-refresh
make test:edit

compose=(docker compose --project-directory "$root_dir" --project-name agelto_dev --env-file "$root_dir/.env" -f "$root_dir/docker-compose.yml")
testing_app=("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app)
evidence_dir="storage/app/p11-runtime-contract-$$"
mkdir -p "$evidence_dir"
trap 'rm -rf "$temp_dir" "$evidence_dir"' EXIT

run_serial() {
    local repetition="$1"
    local start end

    start=$(date +%s%N)
    "${testing_app[@]}" php artisan test --ci --no-tia --compact \
        --log-junit="$evidence_dir/serial-${repetition}.xml"
    end=$(date +%s%N)
    printf '%s\n' "$((end - start))" >"$temp_dir/serial-${repetition}.ns"
}

for repetition in 1 2 3; do
    run_serial "$repetition"
done

python3 - "$baseline" "$evidence_dir" "$temp_dir" <<'PY'
import re
import statistics
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

baseline = Path(sys.argv[1]).read_text()
evidence_dir = Path(sys.argv[2])
temp_dir = Path(sys.argv[3])
match = re.search(r'Median wall time is \*\*([0-9.]+) s\*\*', baseline)
if match is None:
    raise SystemExit('P11.1 serial median is absent')
serial_median = float(match.group(1))

def cases(path: Path) -> set[tuple[str, str, str]]:
    root = ET.parse(path).getroot()
    found = set()
    for case in root.iter('testcase'):
        status = next((child.tag for child in case if child.tag in {'failure', 'error', 'skipped'}), 'passed')
        found.add((case.attrib.get('classname', ''), case.attrib.get('name', ''), status))
    return found

serial_cases = cases(evidence_dir / 'serial-1.xml')
if not serial_cases:
    raise SystemExit('serial JUnit has no test cases')

serial_seconds = []
for repetition in range(1, 4):
    if cases(evidence_dir / f'serial-{repetition}.xml') != serial_cases:
        raise SystemExit(f'serial repetition {repetition} diverges from the serial name/status set')
    serial_seconds.append(int((temp_dir / f'serial-{repetition}.ns').read_text()) / 1_000_000_000)

measured_median = statistics.median(serial_seconds)
if measured_median > serial_median * 1.25:
    raise SystemExit(f'serial median {measured_median:.2f}s exceeds the 125% guard against {serial_median:.2f}s')

print(f'P11.2 selected serial: median {measured_median:.2f}s; {len(serial_cases)} exact cases.')
PY

rm -rf "$evidence_dir"
trap 'rm -rf "$temp_dir"' EXIT

echo 'P11 test runtime contract passed'
