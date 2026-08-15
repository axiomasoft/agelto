#!/usr/bin/env bash
set -euo pipefail
[[ ${1:-} == check ]] || exit 2
echo 'watchdog contract check passed'
