#!/usr/bin/env bash
set -euo pipefail
[[ ${1:-} == check ]] || exit 2
printf '%s\n' stop compatibility previous-digest promote readiness
