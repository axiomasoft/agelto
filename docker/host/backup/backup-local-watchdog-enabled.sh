#!/usr/bin/env bash
set -euo pipefail
readonly status_file=/srv/agelto/backups/local/latest.status
[[ -f $status_file && ! -L $status_file ]] || { echo 'Agelto local backup status is absent' >&2; exit 1; }
completed_at=$(awk -F= '$1 == "completed_at" {print $2}' "$status_file")
[[ $completed_at =~ ^[0-9]+$ ]]
grep -qx 'verified_local=1' "$status_file"
now=$(date +%s)
(( now >= completed_at && now - completed_at <= 129600 )) || { echo 'Agelto local backup is stale' >&2; exit 1; }
echo 'Agelto LOCAL backup is fresh; off-host disaster recovery is not configured'
