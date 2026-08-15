#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly plan_id=2026.08.09-RELEASE-READINESS
readonly plan_dir="$root/plans/$plan_id"
readonly report="$plan_dir/artifacts/P12-production-preparation-report.md"
readonly brief="$plan_dir/artifacts/P12-operator-rehearsal-brief.md"
readonly tombstone="$plan_dir/artifacts/P12-final-readiness-report.md"
readonly manifest="$plan_dir/artifacts/P12-evidence-manifest.json"

fail() {
    printf '[FAIL] P12.7 %s\n' "$*" >&2
    exit 1
}

carriers=(
    docker/tests/deploy-repositories-contract.sh
    docker/tests/ingress-backup-contract.sh
    docker/tests/first-production-deploy-contract.sh
    docker/tests/release-recovery-contract.sh
    docker/tests/command-reference-contract.sh
)
for carrier in "${carriers[@]}"; do
    bash "$root/$carrier"
done

[[ $(grep -Fxc 'PREPARATION_READY' "$report") -eq 1 ]] || fail 'report does not contain exactly one binary preparation verdict'
grep -F 'P12-evidence-manifest.json' "$report" >/dev/null
for carrier in "${carriers[@]}"; do
    grep -F "bash $carrier" "$report" >/dev/null || fail "report misses exact command $carrier"
done
for limitation in 'dedicated Ubuntu host' 'DNS' 'GitHub' 'registry/CI' 'TLS' 'production deploy' 'scheduled backup' 'secret rotation' 'rollback' 'isolated restore' 'browser/Doctor' 'D45'; do
    grep -F "$limitation" "$report" >/dev/null || fail "report misses limitation $limitation"
done

python3 - "$root" "$manifest" <<'PY'
import hashlib
import json
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
manifest_path = pathlib.Path(sys.argv[2])
data = json.loads(manifest_path.read_text(encoding="utf-8"))
assert data["schema"] == "agelto.p12.evidence-manifest/v1"
assert data["verdict"] == "PREPARATION_READY"
assert data["runtime_receipts"] == []
expected_carriers = {
    "docker/tests/deploy-repositories-contract.sh",
    "docker/tests/ingress-backup-contract.sh",
    "docker/tests/first-production-deploy-contract.sh",
    "docker/tests/release-recovery-contract.sh",
    "docker/tests/command-reference-contract.sh",
}
expected_templates = {
    "plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-repository-evidence.md",
    "plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-backup-evidence.md",
    "plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-first-deploy-evidence.md",
    "plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-release-recovery-evidence.md",
}
assert {item["path"] for item in data["local_carriers"]} == expected_carriers
assert {item["path"] for item in data["runtime_evidence_templates"]} == expected_templates
items = data["local_carriers"] + data["runtime_evidence_templates"] + data["generated_artifacts"]
paths = [item["path"] for item in items]
assert len(paths) == len(set(paths))
for item in items:
    path = root / item["path"]
    assert path.is_file() and not path.is_symlink(), item["path"]
    assert re.fullmatch(r"[0-9a-f]{64}", item["sha256"])
    assert hashlib.sha256(path.read_bytes()).hexdigest() == item["sha256"], item["path"]
assert set(data["decision_trace"]) == {"D19", "D43", "D44", "D45", "D49", "D50", "D53", "D89", "D90"}
assert data["excluded_runtime_gates"]
PY

for template in \
    "$plan_dir/artifacts/P12-repository-evidence.md" \
    "$plan_dir/artifacts/P12-backup-evidence.md" \
    "$plan_dir/artifacts/P12-first-deploy-evidence.md" \
    "$plan_dir/artifacts/P12-release-recovery-evidence.md"; do
    grep -F 'TEMPLATE — NO RUNTIME EVIDENCE' "$template" >/dev/null || fail "$template is not marked as a blank runtime template"
done

for decision in D19 D43 D44 D45 D49 D50 D53 D89 D90; do
    grep -F "$decision" "$brief" >/dev/null || fail "follow-up brief misses $decision"
done
for gate in 'disposable Ubuntu' 'DNS' 'GitHub deploy-key' 'registry and CI' 'TLS' 'deployment' 'scheduled backup' 'alert delivery' 'secret rotation' 'rollback' 'isolated restore' 'browser' 'Doctor'; do
    grep -F "$gate" "$brief" >/dev/null || fail "follow-up brief misses $gate"
done
grep -F 'grants no authority' "$brief" >/dev/null
grep -F 'SUPERSEDED FOR CURRENT PLAN BY D89' "$tombstone" >/dev/null
if rg -n '(^|[^A-Z_])READY([^A-Z_]|$)' "$tombstone" >/dev/null; then
    fail 'compatibility pointer contains an unprefixed production readiness verdict'
fi

if rg -n -- '-----BEGIN (RSA |OPENSSH )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|successful deploy|issued certificate|GitHub auth succeeded|production READY' \
    "$plan_dir/artifacts/P12-"* "$report" "$brief" >/dev/null; then
    fail 'P12 artifacts contain a secret or fabricated runtime claim'
fi

python3 "$root/plans/verify-citations.py" "$plan_dir" --strict >/dev/null

task_root="$(codex plugin marketplace list --json | python3 -c 'import json,sys,pathlib; data=json.load(sys.stdin); roots=[item["root"] for item in data.get("marketplaces", []) if item.get("name")=="swissknifeman" and (pathlib.Path(item["root"])/"packages/task/lib/task").is_dir()]; len(roots)==1 or sys.exit("task runtime unavailable"); print(roots[0]+"/packages/task")')"
python3 "$task_root/scripts/plan-lint.py" "$plan_dir"
python3 "$task_root/scripts/plan-views.py" state --plan-dir "$plan_dir" --all --check
python3 "$task_root/scripts/plan-views.py" decisions --plan-dir "$plan_dir" --check
python3 "$task_root/scripts/plan-views.py" status --plan-dir "$plan_dir" --check

scoped_paths=(
    docker/scripts/deploy-repositories.sh
    docker/scripts/production-profile-check.sh
    docker/scripts/backup-production.sh
    docker/scripts/backup-watchdog.sh
    docker/scripts/backup-alert.sh
    docker/scripts/first-production-deploy.sh
    docker/scripts/release-production.sh
    docker/scripts/rollback-production.sh
    docker/scripts/restore-production.sh
    docker/scripts/rotate-production-secrets.sh
    docker/scripts/check-command-reference.py
    docker/tests/deploy-repositories-contract.sh
    docker/tests/ingress-backup-contract.sh
    docker/tests/first-production-deploy-contract.sh
    docker/tests/release-recovery-contract.sh
    docker/tests/secret-rotation-contract.sh
    docker/tests/command-reference-contract.sh
    docker/tests/production-preparation-contract.sh
    docker/host/nginx/agelto.conf
    docker/host/systemd
    docs/03_Dev
    docs/04_Operators
    "plans/$plan_id/artifacts"
)
git -C "$root" diff --quiet -- "${scoped_paths[@]}" || fail 'tracked preparation scope is not committed'
git -C "$root" diff --check -- "${scoped_paths[@]}"

printf 'PREPARATION_READY\n'
