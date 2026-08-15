#!/usr/bin/env bash
set -euo pipefail
mode=${1:-check}; state=${STATE_FILE:-}
[[ $mode == check ]] || { echo 'apply requires a future operator rehearsal authority' >&2; exit 2; }
stages=(preflight data migration seed-base initial-admin workers assets readiness doctor browser)
for stage in "${stages[@]}"; do printf '%s\n' "$stage"; done
[[ -z $state ]] || printf '%s\n' 'check-only' > "$state"
