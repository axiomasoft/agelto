#!/usr/bin/env bash
set -euo pipefail
[[ ${1:-} == check ]] || { echo 'apply requires a future operator rehearsal authority' >&2; exit 2; }
echo 'backup contract: custom PostgreSQL dump, verified copy, then prune; no runtime action performed'
