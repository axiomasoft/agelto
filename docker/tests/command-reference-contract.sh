#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
script="$root/docker/scripts/check-command-reference.py"
python3 "$script" render
python3 "$script" check
python3 "$script" agent-json | python3 -c 'import json,sys; assert json.load(sys.stdin)["schema"] == "agelto.command-reference/v1"'
grep -F 'make repositories:prepare' "$root/plans/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md" >/dev/null
echo 'Command reference contract passed'
