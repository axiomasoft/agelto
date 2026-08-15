#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
bash -n "$root/docker/scripts/rotate-production-secrets.sh"
"$root/docker/scripts/rotate-production-secrets.sh" check | grep -F 'APP DB REDIS MINIO' >/dev/null
! rg -n -- 'eval|\|\| true|password|token' "$root/docker/scripts/rotate-production-secrets.sh" >/dev/null
echo 'Secret rotation contract passed'
