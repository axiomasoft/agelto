#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
script="$root/docker/scripts/first-production-deploy.sh"
bash -n "$script"
output=$("$script" check)
for stage in preflight data migration seed-base initial-admin workers assets readiness doctor browser; do grep -Fx "$stage" <<<"$output" >/dev/null; done
! "$script" apply >/dev/null 2>&1
! rg -n -- 'docker build|migrate:fresh|seed-demo|eval|\|\| true|down --volumes' "$script" >/dev/null
echo 'First deploy contract passed'
