#!/usr/bin/env bash
set -euo pipefail
[[ ${1:-} == check ]] || { echo 'apply requires future authority' >&2; exit 2; }
printf '%s\n' backup preflight drain promote compatibility readiness canary
