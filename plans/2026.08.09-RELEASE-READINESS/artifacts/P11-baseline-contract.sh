#!/usr/bin/env bash
set -euo pipefail

readonly PLAN_ID='2026.08.09-RELEASE-READINESS'
readonly TREE_SHA='48aa8f58be9197d83d7ab502cd6088ce42719fd4'
readonly SERIAL_DIGEST='29daf6fa6e82839f8f7081e370d65ebda45692c6b2937d1fbf387f43254820a5'

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"
plan_dir="plans/$PLAN_ID"
baseline="$plan_dir/artifacts/P11-test-baseline.md"
matrix="$plan_dir/artifacts/P11-risk-test-matrix.md"
evidence_dir='storage/app/p11-evidence-48aa8f58'

fail() {
    printf 'P11 baseline contract: FAIL: %s\n' "$*" >&2
    exit 1
}

require_literal() {
    local file="$1"
    local literal="$2"
    grep -Fq -- "$literal" "$file" || fail "$file misses: $literal"
}

[[ -f "$baseline" ]] || fail 'baseline artifact missing'
[[ -f "$matrix" ]] || fail 'risk matrix missing'
[[ "$(git rev-parse "$TREE_SHA^{commit}")" == "$TREE_SHA" ]] || fail 'measurement tree is unavailable'

for state in \
    P0/P0.4 P0/P0.6 P1/P1.5 P2/P2.6 P3/P3.5 P4/P4.5 P5/P5.5 \
    P6/P6.6 P7/P7.6 P8/P8.5 P9/P9.6 P10/P10.6; do
    state_file="$plan_dir/phases/${state}.state.md"
    [[ -f "$state_file" ]] || fail "missing endpoint state: $state_file"
    grep -Eq '^\*\*Status:\*\* (🟢 Done|🟠 Done with deviations)$' "$state_file" \
        || fail "endpoint is not terminal: $state_file"
done

python3 - "$plan_dir" <<'PY'
import subprocess
import sys

plan_dir = sys.argv[1] + '/'
raw = subprocess.run(
    ['git', 'status', '--porcelain=v1', '-z'], check=True, capture_output=True,
).stdout.split(b'\0')
index = 0
while index < len(raw):
    record = raw[index]
    index += 1
    if not record:
        continue
    if len(record) < 4:
        raise SystemExit(f'invalid porcelain record: {record!r}')
    status = record[:2].decode('ascii')
    paths = [record[3:].decode('utf-8', 'surrogateescape')]
    if 'R' in status or 'C' in status:
        if index >= len(raw) or not raw[index]:
            raise SystemExit('rename/copy porcelain record misses the second path')
        paths.append(raw[index].decode('utf-8', 'surrogateescape'))
        index += 1
    for path in paths:
        if path != 'CLAUDE.md' and not path.startswith(plan_dir):
            raise SystemExit(f'product/unowned path is dirty: {path}')
PY

make test-preflight >/dev/null

compose=(docker compose --env-file .env -f docker/docker-compose.yml -f docker/docker-compose.dev.yml -p agelto_dev)
"${compose[@]}" exec -T app composer show --locked --format=json | python3 -c '
import json, sys
expected = {
    "laravel/framework": "v13.25.0",
    "pestphp/pest": "v5.0.4",
    "pestphp/pest-plugin-laravel": "v5.0.1",
    "pestphp/pest-plugin-agent": "v5.0.0",
    "phpunit/phpunit": "13.2.6",
    "brianium/paratest": "v7.23.1",
    "laravel/pao": "v1.1.3",
    "laravel/agent-detector": "v2.0.2",
}
actual = {row["name"]: row["version"] for row in json.load(sys.stdin)["locked"]}
wrong = {name: (version, actual.get(name)) for name, version in expected.items()
         if actual.get(name) != version}
if wrong:
    raise SystemExit(f"locked package graph mismatch: {wrong}")
'

require_literal "$baseline" "$TREE_SHA"
require_literal "$baseline" '50 Unit files + 213 Feature files = 263 PHP test files'
require_literal "$baseline" '1,569 passed / 7,084 assertions'
require_literal "$baseline" 'Median wall time is **89.97 s**'
require_literal "$baseline" "$SERIAL_DIGEST"
require_literal "$baseline" '20,246 statements / 17,872 covered (**88.27%**)'
require_literal "$baseline" '0 executed / 1,569 replayed'
require_literal "$baseline" 'Both explicit parallel candidates were repeated three times'
require_literal "$baseline" 'parallel is rejected on this tree'
require_literal "$baseline" 'exact union, 1,569 unique; 0 missing/extra/duplicate'
require_literal "$baseline" '**provisional 2-way sharding**'

python3 - "$matrix" "$plan_dir/decisions" <<'PY'
import pathlib
import re
import sys

matrix_path = pathlib.Path(sys.argv[1])
decisions_dir = pathlib.Path(sys.argv[2])
rows = {}
for line in matrix_path.read_text(encoding='utf-8').splitlines():
    match = re.match(r'^\| (D(?:19|2[0-9]|3[0-9]|4[0-2])) \|(.*)\|$', line)
    if not match:
        continue
    decision_id = match.group(1)
    if decision_id in rows:
        raise SystemExit(f'duplicate risk row: {decision_id}')
    cells = [cell.strip() for cell in match.group(2).split('|')]
    if len(cells) != 5 or any(not cell for cell in cells):
        raise SystemExit(f'incomplete risk row: {decision_id}')
    risk, severity, evidence, status, owner = cells
    if severity not in {'Critical', 'High', 'Medium'}:
        raise SystemExit(f'invalid severity for {decision_id}: {severity}')
    if status not in {'Covered', 'Partial', 'Gap'}:
        raise SystemExit(f'invalid status for {decision_id}: {status}')
    if evidence in {'—', '-', 'PENDING'} or owner in {'—', '-', 'PENDING'}:
        raise SystemExit(f'placeholder evidence/owner for {decision_id}')
    rows[decision_id] = {'severity': severity, 'status': status}

expected = {f'D{number}' for number in range(19, 43)}
if set(rows) != expected:
    raise SystemExit(f'risk row set mismatch: missing={sorted(expected-set(rows))}, extra={sorted(set(rows)-expected)}')
for decision_id in sorted(expected, key=lambda value: int(value[1:])):
    files = list(decisions_dir.glob(f'{decision_id}-*.md'))
    if len(files) != 1:
        raise SystemExit(f'decision carrier count for {decision_id}: {len(files)}')
    text = files[0].read_text(encoding='utf-8')
    if f'id: {decision_id}\n' not in text or '## Решение\n' not in text:
        raise SystemExit(f'decision carrier malformed: {files[0]}')
    if decision_id == 'D37' and 'status: superseded\n' not in text:
        raise SystemExit('D37 must remain superseded')
    if decision_id != 'D37' and 'status: accepted\n' not in text:
        raise SystemExit(f'{decision_id} must remain accepted')

gaps = {key for key, row in rows.items()
        if row['severity'] in {'Critical', 'High'} and row['status'] == 'Gap'}
partials = {key for key, row in rows.items() if row['status'] == 'Partial'}
if gaps != {'D41', 'D42'} or partials != {'D34', 'D40'}:
    raise SystemExit(f'risk disposition mismatch: gaps={sorted(gaps)}, partials={sorted(partials)}')
PY

[[ -d "$evidence_dir" ]] || fail "raw evidence directory missing: $evidence_dir"
python3 - "$baseline" "$evidence_dir" "$SERIAL_DIGEST" <<'PY'
import hashlib
import pathlib
import re
import statistics
import sys
import xml.etree.ElementTree as ET

baseline = pathlib.Path(sys.argv[1])
root = pathlib.Path(sys.argv[2])
expected_digest = sys.argv[3]

expected_files = set()
for number in range(1, 6):
    expected_files.update({f'serial-{number}.xml', f'serial-{number}.time'})
expected_files.update({'profile.xml', 'profile.txt', 'profile.time',
                       'coverage-clover.xml', 'coverage-junit.xml', 'coverage.txt', 'coverage.time',
                       'tia-fresh-initial-failure.txt', 'tia-fresh-initial-failure.time',
                       'tia-fresh.xml', 'tia-fresh.txt', 'tia-fresh.time',
                       'tia-replay.xml', 'tia-replay.txt', 'tia-replay.time'})
for workers in (2, 4):
    for run in range(1, 4):
        expected_files.update({f'parallel-{workers}-{run}.txt', f'parallel-{workers}-{run}.time'})
for current, total in ((1, 1), (1, 2), (2, 2), (1, 4), (2, 4), (3, 4), (4, 4)):
    expected_files.update({f'shard-{current}-{total}.xml', f'shard-{current}-{total}.txt',
                           f'shard-{current}-{total}.time'})

text = baseline.read_text(encoding='utf-8')
match = re.search(r'<!-- raw-manifest:start -->\n```text\n(.*?)\n```\n<!-- raw-manifest:end -->',
                  text, flags=re.S)
if match is None:
    raise SystemExit('raw evidence manifest markers are missing')
manifest = {}
for line in match.group(1).splitlines():
    sha256, size, name = line.split()
    if name in manifest or not re.fullmatch(r'[0-9a-f]{64}', sha256) or not size.isdigit():
        raise SystemExit(f'invalid raw manifest row: {line}')
    manifest[name] = (sha256, int(size))
if set(manifest) != expected_files:
    raise SystemExit(
        f'raw manifest set mismatch: missing={sorted(expected_files-set(manifest))}, '
        f'extra={sorted(set(manifest)-expected_files)}')
actual_files = {path.name for path in root.iterdir() if path.is_file()}
if actual_files != expected_files:
    raise SystemExit(
        f'raw evidence directory set mismatch: missing={sorted(expected_files-actual_files)}, '
        f'extra={sorted(actual_files-expected_files)}')
for name, (expected_sha, expected_size) in manifest.items():
    path = root / name
    if not path.is_file():
        raise SystemExit(f'raw evidence file missing: {path}')
    if path.stat().st_size != expected_size:
        raise SystemExit(f'raw evidence size mismatch: {name}')
    if hashlib.sha256(path.read_bytes()).hexdigest() != expected_sha:
        raise SystemExit(f'raw evidence hash mismatch: {name}')


def cases(paths):
    rows = []
    for path in paths:
        for case in ET.parse(path).getroot().iter('testcase'):
            status = 'passed'
            if case.find('failure') is not None:
                status = 'failure'
            elif case.find('error') is not None:
                status = 'error'
            elif case.find('skipped') is not None:
                status = 'skipped'
            rows.append((case.get('classname', ''), case.get('name', ''), status))
    return rows


def junit_summary(path):
    suite = ET.parse(path).getroot().find('testsuite')
    if suite is None:
        raise SystemExit(f'JUnit root suite missing: {path}')
    return {name: int(suite.get(name, '0'))
            for name in ('tests', 'assertions', 'errors', 'failures', 'skipped')}


def measured_wall(name):
    value = (root / f'{name}.time').read_text()
    match = re.search(r'^(?:wall|elapsed)=([0-9]+(?:\.[0-9]+)?)\b', value, flags=re.M)
    if match is None:
        raise SystemExit(f'timing value missing: {name}.time')
    return float(match.group(1))


def digest(rows):
    payload = '\n'.join('\t'.join(row) for row in sorted(rows)).encode()
    return hashlib.sha256(payload).hexdigest()


serial = cases([root / 'serial-1.xml'])
if len(serial) != 1569 or any(row[2] != 'passed' for row in serial):
    raise SystemExit('serial raw evidence is not 1569 green tests')
if junit_summary(root / 'serial-1.xml') != {
        'tests': 1569, 'assertions': 7084, 'errors': 0, 'failures': 0, 'skipped': 0}:
    raise SystemExit('serial JUnit count/assertion/status mismatch')
if digest(serial) != expected_digest:
    raise SystemExit('serial normalized digest mismatch')
full_junits = ('serial-2.xml', 'serial-3.xml', 'serial-4.xml', 'serial-5.xml',
               'profile.xml', 'coverage-junit.xml', 'tia-fresh.xml', 'tia-replay.xml',
               'shard-1-1.xml')
for name in full_junits:
    if digest(cases([root / name])) != expected_digest:
        raise SystemExit(f'name/status parity mismatch: {name}')
    if junit_summary(root / name) != {
            'tests': 1569, 'assertions': 7084, 'errors': 0, 'failures': 0, 'skipped': 0}:
        raise SystemExit(f'full JUnit count/assertion/status mismatch: {name}')
serial_set = set(serial)
for total in (2, 4):
    shard_paths = [root / f'shard-{current}-{total}.xml' for current in range(1, total + 1)]
    union = cases(shard_paths)
    if len(union) != 1569 or len(set(union)) != 1569 or set(union) != serial_set:
        raise SystemExit(f'shard union mismatch: {total}')
    if digest(union) != expected_digest:
        raise SystemExit(f'shard normalized digest mismatch: {total}')
    summaries = [junit_summary(path) for path in shard_paths]
    if (sum(value['tests'] for value in summaries) != 1569
            or sum(value['assertions'] for value in summaries) != 7084
            or any(value['errors'] or value['failures'] or value['skipped']
                   for value in summaries)):
        raise SystemExit(f'shard JUnit count/assertion/status mismatch: {total}')
for workers in (2, 4):
    for run in range(1, 4):
        output = (root / f'parallel-{workers}-{run}.txt').read_text(errors='replace')
        timing = (root / f'parallel-{workers}-{run}.time').read_text()
        if "The use statement with non-compound name 'RuntimeException' has no effect" not in output:
            raise SystemExit(f'parallel failure signature mismatch: {workers}/{run}')
        if 'exit=1' not in timing:
            raise SystemExit(f'parallel exit evidence mismatch: {workers}/{run}')
if 'could not determine the default branch' not in (
        root / 'tia-fresh-initial-failure.txt').read_text(errors='replace').lower():
    raise SystemExit('initial TIA failure signature mismatch')
if 'exit=1' not in (root / 'tia-fresh-initial-failure.time').read_text():
    raise SystemExit('initial TIA failure exit evidence mismatch')

ledger = {}
for line in text.splitlines():
    match = re.match(r'^\| ([a-z0-9-]+) \| `.*` \| ([01]) / ([0-9]+\.[0-9]+) \|', line)
    if match:
        ledger[match.group(1)] = (int(match.group(2)), float(match.group(3)))
expected_runs = ({f'serial-{number}' for number in range(1, 6)}
                 | {'profile', 'coverage', 'tia-fresh', 'tia-replay'}
                 | {f'parallel-{workers}-{run}' for workers in (2, 4) for run in range(1, 4)}
                 | {f'shard-{current}-{total}' for current, total in
                    ((1, 1), (1, 2), (2, 2), (1, 4), (2, 4), (3, 4), (4, 4))})
if set(ledger) != expected_runs:
    raise SystemExit(f'run ledger set mismatch: {sorted(set(ledger) ^ expected_runs)}')
for run, (exit_code, declared_wall) in ledger.items():
    actual_wall = measured_wall(run)
    if declared_wall != actual_wall:
        raise SystemExit(f'ledger wall mismatch: {run}: {declared_wall} != {actual_wall}')
    timing = (root / f'{run}.time').read_text()
    recorded_exit = re.search(r'^exit=([0-9]+)$', timing, flags=re.M)
    if recorded_exit is not None and int(recorded_exit.group(1)) != exit_code:
        raise SystemExit(f'ledger exit mismatch: {run}')
    if run.startswith('parallel-') and exit_code != 1:
        raise SystemExit(f'parallel ledger must remain red: {run}')
    if not run.startswith('parallel-') and exit_code != 0:
        raise SystemExit(f'green evidence ledger exit mismatch: {run}')

serial_walls = [measured_wall(f'serial-{number}') for number in range(1, 6)]
median = statistics.median(serial_walls)
if (f'Median wall time is **{median:.2f} s**; range is '
        f'{min(serial_walls):.2f}–{max(serial_walls):.2f} s.') not in text:
    raise SystemExit('serial median/range prose does not match timing evidence')
replay_reduction = (1 - measured_wall('tia-replay') / median) * 100
if f'Replay reduced wall time by {replay_reduction:.1f}%' not in text:
    raise SystemExit('TIA replay reduction prose does not match timing evidence')
if '1569 replayed' not in re.sub(
        r'\x1b\[[0-9;]*m', '', (root / 'tia-replay.txt').read_text(errors='replace')):
    raise SystemExit('TIA replay count missing from raw output')

shard2 = [measured_wall('shard-1-2'), measured_wall('shard-2-2')]
shard4 = [measured_wall(f'shard-{number}-4') for number in range(1, 5)]
sum2, sum4 = sum(shard2), sum(shard4)
critical2, critical4 = max(shard2), max(shard4)
improvement2, improvement4 = (1 - critical2 / median) * 100, (1 - critical4 / median) * 100
cost2, cost4 = sum2 / median * 100, sum4 / median * 100
expected_fragments = (
    f'| 2× | {shard2[0]:.2f}, {shard2[1]:.2f} | {sum2:.2f} | {critical2:.2f} |',
    f'| 4× | {shard4[0]:.2f}, {shard4[1]:.2f}, {shard4[2]:.2f}, {shard4[3]:.2f} '
    f'| {sum4:.2f} | {critical4:.2f} |',
    f'2 shards have a projected {improvement2:.1f}% wall reduction at {cost2:.1f}% runner',
    f'4 shards project {improvement4:.1f}% at {cost4:.1f}% cost.',
)
for fragment in expected_fragments:
    if fragment not in text:
        raise SystemExit(f'shard metric prose mismatch: {fragment}')

metrics = ET.parse(root / 'coverage-clover.xml').getroot().find('.//project/metrics')
if metrics is None:
    raise SystemExit('Clover project metrics missing')
actual_metrics = {key: int(metrics.get(key, '0')) for key in (
    'files', 'loc', 'ncloc', 'classes', 'methods', 'coveredmethods', 'statements',
    'coveredstatements', 'elements', 'coveredelements')}
expected_metrics = {'files': 703, 'loc': 60092, 'ncloc': 50612, 'classes': 579,
                    'methods': 2283, 'coveredmethods': 1525, 'statements': 20246,
                    'coveredstatements': 17872, 'elements': 22529, 'coveredelements': 19397}
if actual_metrics != expected_metrics:
    raise SystemExit(f'Clover totals mismatch: {actual_metrics}')
method_pct = 100 * actual_metrics['coveredmethods'] / actual_metrics['methods']
statement_pct = 100 * actual_metrics['coveredstatements'] / actual_metrics['statements']
element_pct = 100 * actual_metrics['coveredelements'] / actual_metrics['elements']
coverage_fragment = (
    f"2,283 methods / 1,525 covered ({method_pct:.2f}%),\n"
    f"  20,246 statements / 17,872 covered (**{statement_pct:.2f}%**), "
    f"22,529 elements / 19,397 covered ({element_pct:.2f}%).")
if coverage_fragment not in text:
    raise SystemExit('Clover percentage prose does not match raw metrics')
PY

task_root="${TASK_MARKETPLACE_ROOT:-}"
if [[ -z "$task_root" ]] && command -v codex >/dev/null 2>&1; then
    task_root="$(codex plugin marketplace list --json | python3 -c 'import json, pathlib, sys; data=json.load(sys.stdin); roots=[x["root"] for x in data.get("marketplaces", []) if x.get("name")=="swissknifeman" and (pathlib.Path(x["root"])/"packages/task/scripts/plan-lint.py").is_file()]; len(roots)==1 or sys.exit(1); print(roots[0])')"
fi
[[ -n "$task_root" && -f "$task_root/packages/task/scripts/plan-lint.py" ]] \
    || fail 'Swissknifeman Task marketplace root is unavailable'

python3 "$task_root/packages/task/scripts/plan-lint.py" "$plan_dir" >/dev/null
python3 "$task_root/packages/task/scripts/plan-views.py" state --plan-dir "$plan_dir" --all --check >/dev/null
python3 "$task_root/packages/task/scripts/plan-views.py" decisions --plan-dir "$plan_dir" --check >/dev/null
python3 "$task_root/packages/task/scripts/plan-views.py" status --plan-dir "$plan_dir" --check >/dev/null
python3 plans/verify-citations.py "$plan_dir" --strict >/dev/null

printf 'P11 baseline contract: OK (%s; serial=%s; risks=D19-D42)\n' "$TREE_SHA" "$SERIAL_DIGEST"
