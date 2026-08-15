#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
for script in production-profile-check backup-production backup-watchdog backup-alert; do bash -n "$root/docker/scripts/$script.sh"; done
grep -F '127.0.0.1:8081' "$root/docker/host/nginx/agelto.conf" >/dev/null
grep -F 'proxy_set_header Host' "$root/docker/host/nginx/agelto.conf" >/dev/null
grep -F 'Persistent=true' "$root/docker/host/systemd/agelto-backup.timer" >/dev/null
grep -F 'OnCalendar=' "$root/docker/host/systemd/agelto-backup.timer" >/dev/null
! rg -n -- '--no-check-certificate|curl \| sh|eval|\|\| true|pg_restore --clean' "$root/docker/scripts" "$root/docker/host" >/dev/null
bash "$root/docker/scripts/backup-production.sh" check
echo 'Ingress and backup contract passed'
