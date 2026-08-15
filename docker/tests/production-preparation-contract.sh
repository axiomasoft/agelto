#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
for carrier in deploy-repositories-contract ingress-backup-contract first-production-deploy-contract release-recovery-contract command-reference-contract; do bash "$root/docker/tests/$carrier.sh"; done
report="$root/plans/2026.08.09-RELEASE-READINESS/artifacts/P12-production-preparation-report.md"
grep -Fx 'PREPARATION_READY' "$report" >/dev/null
! rg -n -- 'production READY|successful deploy|issued certificate|GitHub auth succeeded' "$root/plans/2026.08.09-RELEASE-READINESS/artifacts/P12-production-preparation-report.md" >/dev/null
python3 -m json.tool "$root/plans/2026.08.09-RELEASE-READINESS/artifacts/P12-evidence-manifest.json" >/dev/null
echo 'Production preparation contract passed'
