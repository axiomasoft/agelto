#!/usr/bin/env bash
set -euo pipefail
exec /usr/local/bin/agelto-backup-watchdog check --status-file /srv/agelto/backups/latest.status --max-age 129600
