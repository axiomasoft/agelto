#!/usr/bin/env bash
set -euo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly PROVISIONER="$SCRIPT_DIR/host-provision.sh"
[[ "${1:-}" == --profile && -n "${2:-}" && $# -eq 2 ]] || { printf 'Usage: host-preflight.sh --profile /absolute/path\n' >&2; exit 2; }

bash "$PROVISIONER" check --profile "$2"
command -v sshd >/dev/null || { printf '[FAIL] PREFLIGHT sshd missing\n' >&2; exit 1; }
sshd -t
systemctl is-active --quiet ufw || { printf '[FAIL] PREFLIGHT UFW inactive\n' >&2; exit 1; }
ufw status | grep -q '80/tcp' && ufw status | grep -q '443/tcp' || { printf '[FAIL] PREFLIGHT UFW web rules missing\n' >&2; exit 1; }
systemctl is-active --quiet docker || { printf '[FAIL] PREFLIGHT Docker inactive\n' >&2; exit 1; }
docker context inspect default --format '{{.Endpoints.docker.Host}}' | grep -qx 'unix:///var/run/docker.sock' || { printf '[FAIL] PREFLIGHT Docker must use Unix socket\n' >&2; exit 1; }
docker info --format '{{.LoggingDriver}}' | grep -qx local || { printf '[FAIL] PREFLIGHT Docker local log rotation missing\n' >&2; exit 1; }
ss -lntH | awk '{print $4}' | grep -Eq '(:2375|:2376)$' && { printf '[FAIL] PREFLIGHT Docker TCP listener\n' >&2; exit 1; }
ss -lntH | awk '$4 !~ /^(127\\.0\\.0\\.1|\\[::1\\]|::1):/ {print $4}' | awk -F: '{print $NF}' | grep -Ev '^(22|80|443)$' >/dev/null && { printf '[FAIL] PREFLIGHT unexpected public listener\n' >&2; exit 1; }
iptables -S DOCKER-USER | grep -q -- '-j DROP' || { printf '[FAIL] PREFLIGHT DOCKER-USER drop rule missing\n' >&2; exit 1; }
timedatectl show -p NTPSynchronized --value | grep -qx yes || { printf '[FAIL] PREFLIGHT time is not synchronized\n' >&2; exit 1; }
systemctl is-enabled --quiet apt-daily-upgrade.timer || { printf '[FAIL] PREFLIGHT unattended updates timer disabled\n' >&2; exit 1; }
printf '[PASS] PREFLIGHT effective SSH, UFW, Docker socket/firewall/logging, listeners, time and updates\n'
