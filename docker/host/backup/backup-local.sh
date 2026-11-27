#!/usr/bin/env bash
set -euo pipefail
exec /srv/agelto/bin/backup-env-gate /srv/agelto/repository/.env.backup -- \
    /srv/agelto/bin/backup-local-enabled "$@"
