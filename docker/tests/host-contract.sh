#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly PROVISIONER="$ROOT_DIR/docker/scripts/host-provision.sh"
readonly PREFLIGHT="$ROOT_DIR/docker/scripts/host-preflight.sh"
readonly PROFILE_CHECKER="$ROOT_DIR/docker/scripts/production-profile-check.sh"

fail() { printf '[FAIL] %s\n' "$*" >&2; exit 1; }
pass() { printf '[PASS] %s\n' "$*"; }

expect_profile_rejection() {
    local fixture=$1 diagnostic=$2 script output
    for script in "$PROVISIONER" "$PROFILE_CHECKER"; do
        if output=$(bash "$script" check --profile "$fixture" 2>&1); then
            fail "${script##*/} принял профиль с ошибкой: $diagnostic"
        fi
        grep -Fxq "[FAIL] PROFILE $diagnostic" <<< "$output" \
            || fail "${script##*/} не сообщил об ошибке профиля: $diagnostic"
    done
}

for script in "$PROVISIONER" "$PREFLIGHT" "$PROFILE_CHECKER"; do
    bash -n "$script"
done
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
cat > "$profile" <<EOF
DEPLOY_USER=deploy
DEPLOY_AUTHORIZED_KEY_FILE=$key
SSH_PORT=00008
PUBLIC_HTTP_PORT=80
PUBLIC_HTTPS_PORT=443
APP_BIND_ADDRESS=127.0.0.1
APP_BIND_PORT=8080
DISK_MIN_FREE_GB=20
DOCKER_DATA_ROOT=/var/lib/docker
REGISTRY_PASSWORD_FILE=$tmp_dir/registry-password
BACKUP_ALERT_URL_FILE=$tmp_dir/backup-alert-url
AGELTO_REPOSITORY=axiomasoft/agelto
AGELTO_CANDIDATE_SHA=0123456789abcdef0123456789abcdef01234567
AGELTO_CHECKOUT_DIR=/srv/agelto/repository
GITHUB_ED25519_HOST_KEY=AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
EOF
chmod 0600 "$profile"

if host_result=$(bash "$PROVISIONER" check --profile "$profile" 2>&1); then
    grep -Fxq '[PASS] CHECK completed without mutation' <<< "$host_result"
else
    # The isolated test container may use a different OS than the production host.
    grep -Fxq '[FAIL] Ubuntu 24.04 is required' <<< "$host_result" \
        || fail 'установщик отклонил корректный профиль'
fi
grep -q '^\[PASS\] PROFILE ' <<< "$host_result" || fail 'установщик не подтвердил проверку профиля'
bash "$PROFILE_CHECKER" check --profile "$profile" >/dev/null
pass 'профиль, принятый установщиком, проходит проверку службы бэкапа'

cp "$profile" "$tmp_dir/weak-profile"
chmod 0644 "$tmp_dir/weak-profile"
expect_profile_rejection "$tmp_dir/weak-profile" 'must not be group/world-accessible'
for entry in 'UNKNOWN=value' 'DEPLOY_USER=deploy'; do
    cp "$profile" "$tmp_dir/invalid-profile"
    printf '%s\n' "$entry" >> "$tmp_dir/invalid-profile"
    case "$entry" in
        UNKNOWN=*) expect_profile_rejection "$tmp_dir/invalid-profile" 'contains unknown key UNKNOWN' ;;
        DEPLOY_USER=*) expect_profile_rejection "$tmp_dir/invalid-profile" 'contains duplicate key DEPLOY_USER' ;;
    esac
done
for field in AGELTO_REPOSITORY AGELTO_CANDIDATE_SHA AGELTO_CHECKOUT_DIR GITHUB_ED25519_HOST_KEY; do
    sed "/^$field=/d" "$profile" > "$tmp_dir/invalid-profile"
    chmod 0600 "$tmp_dir/invalid-profile"
    expect_profile_rejection "$tmp_dir/invalid-profile" "misses $field"
done
while IFS='|' read -r field invalid_value diagnostic; do
    sed "s|^$field=.*|$field=$invalid_value|" "$profile" > "$tmp_dir/invalid-profile"
    expect_profile_rejection "$tmp_dir/invalid-profile" "$diagnostic"
done <<'EOF'
AGELTO_REPOSITORY|agelto|AGELTO_REPOSITORY must be owner/repository
AGELTO_CANDIDATE_SHA|cafef00d|AGELTO_CANDIDATE_SHA must be a full lowercase SHA-1
AGELTO_CHECKOUT_DIR|/srv/another-app|AGELTO_CHECKOUT_DIR must be below /srv/agelto
GITHUB_ED25519_HOST_KEY|ssh-rsa|GITHUB_ED25519_HOST_KEY must be an Ed25519 key body
APP_BIND_PORT|65536|APP_BIND_PORT is out of range
EOF
sed 's/^APP_BIND_ADDRESS=.*/APP_BIND_ADDRESS=0.0.0.0/' "$profile" > "$tmp_dir/invalid-profile"
expect_profile_rejection "$tmp_dir/invalid-profile" 'APP_BIND_ADDRESS must be loopback'
pass 'установщик и служба бэкапа отклоняют неполные и небезопасные профили'

grep -Fq 'Ubuntu 24.04 is required' "$PROVISIONER"
grep -Fq 'unsupported architecture' "$PROVISIONER"
grep -Fq 'sshd -t' "$ROOT_DIR/docs/operators/production/ubuntu-host.md"
grep -Fq 'prove a new key-only login' "$ROOT_DIR/docs/operators/production/ubuntu-host.md"
grep -Fq 'Docker TCP API listener detected' "$PROVISIONER"
grep -Fq 'unexpected public TCP listener' "$PROVISIONER"
grep -Fq 'CHECK completed without mutation' "$PROVISIONER"
grep -Fq 'DOCKER-USER' "$ROOT_DIR/docs/operators/production/ubuntu-host.md"
pass 'HOST-SAFETY wrong OS, SSH cutover, port, Docker-TCP and no-mutation guards'

if grep -En 'printf.*PROFILE|echo.*PROFILE' "$PROVISIONER" | grep -Fv 'values redacted'; then
    fail 'profile values may be printed'
fi
pass 'HOST-REDACTION profile values are not logged'
