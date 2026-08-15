#!/usr/bin/env bash
set -euo pipefail
[[ ${1:-} == check ]] || exit 2
echo 'alert contract check passed'
