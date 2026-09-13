#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly PROVISIONER="$ROOT_DIR/docker/scripts/host-provision.sh"
readonly PREFLIGHT="$ROOT_DIR/docker/scripts/host-preflight.sh"

fail() { printf '[FAIL] %s\n' "$*" >&2; exit 1; }
pass() { printf '[PASS] %s\n' "$*"; }
expect_fail() { "$@" >/dev/null 2>&1 && fail "expected failure: $*" || pass 'unsafe fixture rejected'; }

bash -n "$PROVISIONER" "$PREFLIGHT"
grep -q '"log-driver": "local"' "$ROOT_DIR/docker/host/docker-daemon.json"
grep -q '"iptables": true' "$ROOT_DIR/docker/host/docker-daemon.json"
grep -Fq '"${APP_BIND_ADDRESS:-127.0.0.1}:${APP_PORT:-8080}:8080"' "$ROOT_DIR/docker-compose.prod.yml"
grep -qx 'PermitRootLogin no' "$ROOT_DIR/docker/host/sshd_config.d/90-agelto.conf"
grep -qx 'PasswordAuthentication no' "$ROOT_DIR/docker/host/sshd_config.d/90-agelto.conf"
grep -qx 'AuthenticationMethods publickey' "$ROOT_DIR/docker/host/sshd_config.d/90-agelto.conf"
pass 'HOST-SYNTAX shell, JSON and key-only SSH contract'

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
key="$tmp_dir/deploy.pub"
profile="$tmp_dir/profile"
printf '%s\n' 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKey agelto-test' > "$key"
chmod 0600 "$key"
printf '%s\n' 'DEPLOY_USER=deploy' "DEPLOY_AUTHORIZED_KEY_FILE=$key" 'SSH_PORT=22' 'PUBLIC_HTTP_PORT=80' 'PUBLIC_HTTPS_PORT=443' 'APP_BIND_ADDRESS=127.0.0.1' 'APP_BIND_PORT=8080' 'DISK_MIN_FREE_GB=20' 'DOCKER_DATA_ROOT=/var/lib/docker' "REGISTRY_PASSWORD_FILE=$tmp_dir/registry-password" "BACKUP_ALERT_URL_FILE=$tmp_dir/backup-alert-url" > "$profile"
chmod 0600 "$profile"

cp "$profile" "$tmp_dir/weak-profile"
chmod 0644 "$tmp_dir/weak-profile"
expect_fail bash "$PROVISIONER" check --profile "$tmp_dir/weak-profile"
printf '%s\n' 'UNKNOWN=value' >> "$profile"
expect_fail bash "$PROVISIONER" check --profile "$profile"
sed -i '$d' "$profile"
printf '%s\n' 'DEPLOY_USER=deploy' >> "$profile"
expect_fail bash "$PROVISIONER" check --profile "$profile"
sed -i '$d' "$profile"
printf '%s\n' 'APP_BIND_ADDRESS=0.0.0.0' >> "$profile"
expect_fail bash "$PROVISIONER" check --profile "$profile"
sed -i '$d' "$profile"
pass 'PROFILE closed keys, duplicate, permissions and public-bind fixtures'

grep -Fq 'Ubuntu 24.04 is required' "$PROVISIONER"
grep -Fq 'unsupported architecture' "$PROVISIONER"
grep -Fq 'sshd -t' "$ROOT_DIR/docs/operators/production/ubuntu-host.md"
grep -Fq 'prove a new key-only login' "$ROOT_DIR/docs/operators/production/ubuntu-host.md"
grep -Fq 'Docker TCP API listener detected' "$PROVISIONER"
grep -Fq 'unexpected public TCP listener' "$PROVISIONER"
grep -Fq 'CHECK completed without mutation' "$PROVISIONER"
grep -Fq 'DOCKER-USER' "$ROOT_DIR/docs/operators/production/ubuntu-host.md"
pass 'HOST-SAFETY wrong OS, SSH cutover, port, Docker-TCP and no-mutation guards'

if rg -n 'printf.*PROFILE|echo.*PROFILE' "$ROOT_DIR/docker/scripts/host-provision.sh" | rg -vq 'values redacted'; then
    fail 'profile values may be printed'
fi
pass 'HOST-REDACTION profile values are not logged'
