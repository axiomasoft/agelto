#!/usr/bin/env bash
set -euo pipefail
[[ ${1:-} == check && ${2:-} == --target && ${3:-} =~ _rehearsal$ ]] || { echo 'restore requires an isolated *_rehearsal target' >&2; exit 2; }
echo 'isolated restore contract check passed'
