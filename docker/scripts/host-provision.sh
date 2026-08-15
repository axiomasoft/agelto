#!/usr/bin/env bash
set -euo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly HOST_DIR="${SCRIPT_DIR%/scripts}/host"
readonly SSH_FRAGMENT_SOURCE="$HOST_DIR/sshd_config.d/90-agelto.conf"
readonly DAEMON_SOURCE="$HOST_DIR/docker-daemon.json"

mode=""
profile=""
confirm=0

usage() {
    printf '%s\n' 'Usage: host-provision.sh check|apply --profile /absolute/path [--confirm]'
}

fail() { printf '[FAIL] %s\n' "$*" >&2; exit 1; }
pass() { printf '[PASS] %s\n' "$*"; }

while (($#)); do
    case "$1" in
        check|apply) [[ -z "$mode" ]] || fail 'choose one mode'; mode="$1" ;;
        --profile) profile="${2:-}"; shift ;;
        --confirm) confirm=1 ;;
        *) usage >&2; exit 2 ;;
    esac
    shift
done
[[ -n "$mode" && -n "$profile" && "$profile" = /* ]] || { usage >&2; exit 2; }

declare -A PROFILE=()
readonly REQUIRED_KEYS=(DEPLOY_USER DEPLOY_AUTHORIZED_KEY_FILE SSH_PORT PUBLIC_HTTP_PORT PUBLIC_HTTPS_PORT APP_BIND_ADDRESS APP_BIND_PORT DISK_MIN_FREE_GB DOCKER_DATA_ROOT REGISTRY_PASSWORD_FILE BACKUP_ALERT_URL_FILE)
readonly SECRET_FILE_KEYS=(REGISTRY_PASSWORD_FILE BACKUP_ALERT_URL_FILE)

read_profile() {
    [[ -f "$profile" && ! -L "$profile" ]] || fail 'profile must be a regular non-symlink file'
    local permissions
    permissions="$(stat -c '%a' "$profile")"
    (( 8#${permissions: -2} == 0 )) || fail 'profile must not be group/world-readable'
    local line key value
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ -z "$line" || "$line" == \#* ]] && continue
        [[ "$line" == *=* ]] || fail 'profile lines must be KEY=value'
        key="${line%%=*}"; value="${line#*=}"
        [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || fail 'profile has invalid key'
        case " ${REQUIRED_KEYS[*]} " in *" $key "*) ;; *) fail "profile has unknown key $key";; esac
        [[ ! -v "PROFILE[$key]" ]] || fail "profile has duplicate key $key"
        [[ -n "$value" && "$value" != *CHANGE_ME* && "$value" != *'<'* && "$value" != *'>'* ]] || fail "profile has placeholder for $key"
        [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]] || fail "profile has control character in $key"
        PROFILE["$key"]="$value"
    done < "$profile"
    local key
    for key in "${REQUIRED_KEYS[@]}"; do [[ -v "PROFILE[$key]" ]] || fail "profile misses $key"; done
    [[ "${PROFILE[DEPLOY_USER]}" == deploy ]] || fail 'DEPLOY_USER must be deploy'
    [[ "${PROFILE[APP_BIND_ADDRESS]}" == 127.0.0.1 || "${PROFILE[APP_BIND_ADDRESS]}" == ::1 ]] || fail 'APP_BIND_ADDRESS must be loopback'
    [[ "${PROFILE[SSH_PORT]}" =~ ^[0-9]{1,5}$ && "${PROFILE[PUBLIC_HTTP_PORT]}" == 80 && "${PROFILE[PUBLIC_HTTPS_PORT]}" == 443 ]] || fail 'public ports must be SSH, 80 and 443 only'
    [[ "${PROFILE[DISK_MIN_FREE_GB]}" =~ ^[1-9][0-9]*$ ]] || fail 'DISK_MIN_FREE_GB must be positive'
    for key in "${SECRET_FILE_KEYS[@]}"; do [[ "${PROFILE[$key]}" = /* ]] || fail "$key must be an absolute path"; done
    pass 'PROFILE closed schema and permissions are valid (values redacted)'
}

check_platform() {
    # shellcheck disable=SC1091
    source /etc/os-release
    [[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 24.04 ]] || fail 'Ubuntu 24.04 is required'
    case "$(dpkg --print-architecture)" in amd64|arm64) ;; *) fail 'unsupported architecture';; esac
    pass 'HOST-OS Ubuntu 24.04 and supported architecture'
}

check_sources() {
    grep -qx 'PermitRootLogin no' "$SSH_FRAGMENT_SOURCE" && grep -qx 'PasswordAuthentication no' "$SSH_FRAGMENT_SOURCE" && grep -qx 'AuthenticationMethods publickey' "$SSH_FRAGMENT_SOURCE" || fail 'unsafe SSH fragment'
    grep -q '"log-driver": "local"' "$DAEMON_SOURCE" && grep -q '"iptables": true' "$DAEMON_SOURCE" && grep -q '"ip6tables": true' "$DAEMON_SOURCE" || fail 'unsafe Docker daemon configuration'
    ! grep -q '"hosts"' "$DAEMON_SOURCE" || fail 'Docker TCP API configuration is forbidden'
    pass 'HOST-SOURCES SSH and Docker configuration are safe'
}

check_docker_tcp() {
    ss -lntH | awk '{print $4}' | grep -Eq '(:2375|:2376)$' && fail 'Docker TCP API listener detected'
    docker context inspect default --format '{{.Endpoints.docker.Host}}' 2>/dev/null | grep -Eq '^tcp://' && fail 'Docker context uses TCP'
    pass 'DOCKER-TCP no TCP Docker API listener'
}

check_public_ports() {
    ss -lntH | awk '$4 !~ /^(127\\.0\\.0\\.1|\\[::1\\]|::1):/ {print $4}' | awk -F: '{print $NF}' | grep -Ev '^(22|80|443)$' >/dev/null && fail 'unexpected public TCP listener'
    pass 'PORTS only SSH/HTTP/HTTPS may listen publicly'
}

install_docker() {
    apt-get update
    apt-get install -y ca-certificates curl gnupg openssh-server ufw unattended-upgrades
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    install -m 0644 /dev/stdin /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    install -d -m 0755 /etc/docker
    sed "s#\"/var/lib/docker\"#\"${PROFILE[DOCKER_DATA_ROOT]}\"#" "$DAEMON_SOURCE" > /etc/docker/daemon.json
    grep -q '"log-driver": "local"' /etc/docker/daemon.json || fail 'Docker daemon configuration was not written'
    systemctl enable --now docker
    systemctl restart docker
}

install_deploy_user() {
    [[ -f "${PROFILE[DEPLOY_AUTHORIZED_KEY_FILE]}" && ! -L "${PROFILE[DEPLOY_AUTHORIZED_KEY_FILE]}" ]] || fail 'deploy public key must exist and not be a symlink'
    id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash deploy
    install -d -m 0700 -o deploy -g deploy /home/deploy/.ssh
    install -m 0600 -o deploy -g deploy "${PROFILE[DEPLOY_AUTHORIZED_KEY_FILE]}" /home/deploy/.ssh/authorized_keys
    install -m 0440 /dev/stdin /etc/sudoers.d/90-agelto-deploy-status <<'EOF'
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl status docker.service, /usr/bin/journalctl -u docker.service
EOF
    usermod -aG docker deploy
    pass 'DEPLOY installed; docker group membership is root-equivalent privilege'
}

main() {
    check_platform
    check_sources
    read_profile
    if [[ "$mode" == check ]]; then pass 'CHECK completed without mutation'; return; fi
    [[ "$EUID" -eq 0 && "$confirm" -eq 1 ]] || fail 'apply requires root and --confirm'
    install_docker
    install_deploy_user
    dpkg-reconfigure -f noninteractive unattended-upgrades
    systemctl enable --now apt-daily-upgrade.timer
    check_docker_tcp
    pass 'APPLY completed; follow the documented SSH and firewall steps, then reboot and run host:preflight'
}

main
