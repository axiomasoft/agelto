#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
for script in release-production rollback-production restore-production rotate-production-secrets; do bash -n "$root/docker/scripts/$script.sh"; done
"$root/docker/scripts/release-production.sh" check | grep -Fx backup >/dev/null
"$root/docker/scripts/rollback-production.sh" check | grep -Fx stop >/dev/null
"$root/docker/scripts/restore-production.sh" check --target agelto_rehearsal
! "$root/docker/scripts/restore-production.sh" check --target production >/dev/null 2>&1
bash "$root/docker/tests/secret-rotation-contract.sh"
! rg -n -- 'git pull|docker build|down --volumes|eval|\|\| true|pg_restore --clean' "$root/docker/scripts" >/dev/null
echo 'Release and recovery contract passed'
