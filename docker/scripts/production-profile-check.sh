#!/usr/bin/env bash
set -euo pipefail
[[ ${1:-} == check && ${2:-} == --profile && ${3:-} = /* ]] || { echo 'usage: production-profile-check.sh check --profile /absolute/path' >&2; exit 2; }
profile=$3
[[ -f $profile && ! -L $profile ]] || { echo 'profile must be a regular file' >&2; exit 1; }
[[ $(stat -c '%a' "$profile") =~ ^[0-6]00$ ]] || { echo 'profile must be owner-only' >&2; exit 1; }
while IFS= read -r line || [[ -n $line ]]; do [[ -z $line || $line == \#* || $line =~ ^[A-Z][A-Z0-9_]*= ]] || exit 1; done < "$profile"
echo 'profile check passed'
