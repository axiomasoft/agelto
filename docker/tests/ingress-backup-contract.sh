#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly profile_checker="$root/docker/scripts/production-profile-check.sh"
readonly backup="$root/docker/scripts/backup-production.sh"
readonly watchdog="$root/docker/scripts/backup-watchdog.sh"
readonly alert="$root/docker/scripts/backup-alert.sh"

fail() {
    printf '[FAIL] P12.3 %s\n' "$*" >&2
    exit 1
}

pass() {
    printf '[PASS] P12.3 %s\n' "$*"
}

expect_fail() {
    local label=$1
    shift
    if "$@" >/dev/null 2>&1; then
        fail "$label unexpectedly passed"
    fi
}

for script in "$profile_checker" "$backup" "$watchdog" "$alert"; do
    bash -n "$script"
done
pass 'shell syntax'

fixture_root=$(mktemp -d)
trap 'find "$fixture_root" -depth -delete' EXIT
profile="$fixture_root/production-profile"
cat > "$profile" <<EOF
DEPLOY_USER=deploy
DEPLOY_AUTHORIZED_KEY_FILE=$fixture_root/deploy.pub
SSH_PORT=22
PUBLIC_HTTP_PORT=80
PUBLIC_HTTPS_PORT=443
APP_BIND_ADDRESS=127.0.0.1
APP_BIND_PORT=8080
DISK_MIN_FREE_GB=20
DOCKER_DATA_ROOT=/var/lib/docker
REGISTRY_PASSWORD_FILE=$fixture_root/registry-password
BACKUP_ALERT_URL_FILE=$fixture_root/backup-alert-url
AGELTO_REPOSITORY=axiomasoft/agelto
AGELTO_CANDIDATE_SHA=0123456789abcdef0123456789abcdef01234567
AGELTO_CHECKOUT_DIR=/srv/agelto/repository
GITHUB_ED25519_HOST_KEY=AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
EOF
chmod 0600 "$profile"
"$profile_checker" check --profile "$profile" >/dev/null

weak="$fixture_root/weak-profile"
cp "$profile" "$weak"
chmod 0644 "$weak"
expect_fail 'weak profile permissions' "$profile_checker" check --profile "$weak"
ln -s "$profile" "$fixture_root/profile-link"
expect_fail 'profile symlink' "$profile_checker" check --profile "$fixture_root/profile-link"
for mutation in \
    'UNKNOWN_KEY=value' \
    'DEPLOY_USER=deploy' \
    'APP_BIND_ADDRESS=0.0.0.0' \
    'AGELTO_CANDIDATE_SHA=CHANGE_ME'; do
    hostile="$fixture_root/hostile-profile"
    cp "$profile" "$hostile"
    case "$mutation" in
        APP_BIND_ADDRESS=*) sed -i "s/^APP_BIND_ADDRESS=.*/$mutation/" "$hostile" ;;
        AGELTO_CANDIDATE_SHA=*) sed -i "s/^AGELTO_CANDIDATE_SHA=.*/$mutation/" "$hostile" ;;
        *) printf '%s\n' "$mutation" >> "$hostile" ;;
    esac
    expect_fail "profile fixture $mutation" "$profile_checker" check --profile "$hostile"
done
pass 'closed profile, permissions, symlink, duplicate, placeholder and loopback fixtures'

nginx_fixture="$fixture_root/nginx"
mkdir -p "$nginx_fixture/logs"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=agelto.invalid' \
    -keyout "$nginx_fixture/key.pem" -out "$nginx_fixture/cert.pem" >/dev/null 2>&1
sed \
    -e 's/__AGELTO_FQDN__/agelto.invalid/g' \
    -e "s#__AGELTO_TLS_CERTIFICATE__#$nginx_fixture/cert.pem#g" \
    -e "s#__AGELTO_TLS_PRIVATE_KEY__#$nginx_fixture/key.pem#g" \
    -e 's/listen 80;/listen 18080;/g' \
    -e 's/listen \[::\]:80;/listen [::]:18080;/g' \
    -e 's/listen 443 ssl http2;/listen 18443 ssl http2;/g' \
    -e 's/listen \[::\]:443 ssl http2;/listen [::]:18443 ssl http2;/g' \
    "$root/docker/host/nginx/agelto.conf" > "$nginx_fixture/agelto.conf"
cat > "$nginx_fixture/nginx.conf" <<EOF
pid $nginx_fixture/nginx.pid;
error_log $nginx_fixture/error.log;
events {}
http {
    access_log $nginx_fixture/access.log;
    include $nginx_fixture/agelto.conf;
}
EOF
nginx -t -p "$nginx_fixture" -c "$nginx_fixture/nginx.conf" >/dev/null 2>&1
grep -F 'proxy_pass http://127.0.0.1:8080;' "$nginx_fixture/agelto.conf" >/dev/null
grep -F 'proxy_set_header Upgrade $http_upgrade;' "$nginx_fixture/agelto.conf" >/dev/null
grep -F 'proxy_set_header X-Forwarded-Proto https;' "$nginx_fixture/agelto.conf" >/dev/null
! rg -n 'proxy_pass http://(0\.0\.0\.0|app:|[a-z0-9_-]+:8080)' "$nginx_fixture/agelto.conf" >/dev/null
pass 'NGINX syntax, loopback upstream, forwarded headers and WebSocket policy'

units="$fixture_root/units"
mkdir "$units"
for unit in "$root"/docker/host/systemd/agelto-backup*.service "$root"/docker/host/systemd/agelto-backup*.timer; do
    sed -E \
        -e 's#^ExecStart=.*#ExecStart=/bin/true#' \
        -e 's#^User=deploy$#User=root#' \
        -e 's#^Group=deploy$#Group=root#' \
        "$unit" > "$units/$(basename "$unit")"
done
systemd-analyze verify --recursive-errors=yes "$units"/* >/dev/null 2>&1
grep -Fx 'Persistent=true' "$root/docker/host/systemd/agelto-backup.timer" >/dev/null
grep -F 'OnFailure=agelto-backup-alert@' "$root/docker/host/systemd/agelto-backup.service" >/dev/null
grep -F 'ConditionPathExists=/etc/agelto/operator-rehearsal-authority' "$root/docker/host/systemd/agelto-backup.service" >/dev/null
pass 'systemd service/timer calendar, catch-up, failure and authority templates'

adapter="$fixture_root/backup-adapter"
cat > "$adapter" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
stage=$1
output=$2
printf '%s\n' "$stage" >> "${FIXTURE_LOG:?}"
[[ ${FAIL_STAGE:-} != "$stage" ]] || exit 41
case "$stage" in
    postgres-custom-dump) printf 'PGDMP fixture\n' > "$output" ;;
    isolated-restore-verification) grep -F 'PGDMP fixture' "$output" >/dev/null ;;
    object-current-state-export) printf 'bucket/current-object sha256:fixture\n' > "$output" ;;
    config-secret-archive) printf 'encrypted-config-fixture\n' > "$output" ;;
    *) exit 42 ;;
esac
EOF
chmod 0700 "$adapter"
export FIXTURE_LOG="$fixture_root/adapter.log"

backup_root="$fixture_root/backups"
"$backup" fixture --fixture-root "$backup_root" --profile "$profile" --adapter "$adapter" --backup-id fixture-0001 --retention 2 >/dev/null
"$backup" fixture --fixture-root "$backup_root" --profile "$profile" --adapter "$adapter" --backup-id fixture-0002 --retention 1 >/dev/null
[[ ! -e $backup_root/local/fixture-0001 && -f $backup_root/offhost/fixture-0001/manifest.sha256 ]]
(cd "$backup_root/offhost/fixture-0002" && sha256sum --check manifest.sha256 >/dev/null)

FAIL_STAGE=object-current-state-export expect_fail 'adapter failure' \
    "$backup" fixture --fixture-root "$backup_root" --profile "$profile" --adapter "$adapter" --backup-id fixture-0003 --retention 1
[[ -d $backup_root/local/fixture-0002 ]]
exec 8>"$backup_root/.backup.lock"
flock -n 8
expect_fail 'backup lock contention' \
    "$backup" fixture --fixture-root "$backup_root" --profile "$profile" --adapter "$adapter" --backup-id fixture-0004 --retention 1
flock -u 8
pass 'custom dump, isolated verify, object/config set, lock and verified-copy-before-prune fixtures'

status="$fixture_root/latest.status"
cat > "$status" <<'EOF'
completed_at=1700000000
verified=1
offhost=1
manifest_sha256=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
EOF
"$watchdog" check --status-file "$status" --max-age 3600 --now 1700000300 >/dev/null
expect_fail 'stale backup' "$watchdog" check --status-file "$status" --max-age 100 --now 1700000300
sed -i 's/offhost=1/offhost=0/' "$status"
expect_fail 'unverified off-host backup' "$watchdog" check --status-file "$status" --max-age 3600 --now 1700000300

event="$fixture_root/event"
endpoint="$fixture_root/endpoint"
alert_log="$fixture_root/alert.log"
printf 'watchdog:stale:BACKUP_STALE\n' > "$event"
printf 'https://secret.invalid/hook/sentinel-value\n' > "$endpoint"
chmod 0600 "$endpoint"
alert_adapter="$fixture_root/alert-adapter"
cat > "$alert_adapter" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" > "${ALERT_LOG:?}"
EOF
chmod 0700 "$alert_adapter"
ALERT_LOG="$alert_log" "$alert" check --event-file "$event" --endpoint-file "$endpoint" --adapter "$alert_adapter" >/dev/null
! rg -n 'sentinel-value' "$alert_log" >/dev/null
chmod 0644 "$endpoint"
expect_fail 'weak alert endpoint permissions' "$alert" check --event-file "$event" --endpoint-file "$endpoint"
pass 'watchdog freshness/failure and secret-file alert redaction fixtures'

! rg -n -- '--no-check-certificate|curl[[:space:]]*\|[[:space:]]*sh|eval|\|\| true|pg_restore[[:space:]]+--clean' \
    "$root/docker/scripts/production-profile-check.sh" \
    "$root/docker/scripts/backup-production.sh" \
    "$root/docker/scripts/backup-watchdog.sh" \
    "$root/docker/scripts/backup-alert.sh" \
    "$root/docker/host/nginx" \
    "$root/docker/host/systemd" >/dev/null
rg -q 'https://nginx.org/en/docs/http/ngx_http_proxy_module.html' "$root/plans/2026.08.09-RELEASE-READINESS/findings/P12-local-preparation-rag-2026-08-15.md"
grep -F 'TEMPLATE — NO RUNTIME EVIDENCE' "$root/plans/2026.08.09-RELEASE-READINESS/artifacts/P12-backup-evidence.md" >/dev/null
grep -F 'current-state copying' "$root/docs/04_Operators/production/backups.md" >/dev/null
pass 'forbidden patterns, docs, blank evidence schema and official-source carrier'

printf 'Ingress and backup contract passed\n'
