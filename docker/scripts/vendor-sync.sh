#!/usr/bin/env bash
set -euo pipefail
root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
for argument in "$@"; do
    case "$argument" in
        --check|--composer-check) ;;
        *) echo 'Usage: vendor-sync.sh [--check] [--composer-check]' >&2; exit 64 ;;
    esac
done
exec python3 -B "$root_dir/docker/scripts/vendor_manifest.py" --root "$root_dir" "$@"
