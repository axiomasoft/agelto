#!/usr/bin/env bash

set -euo pipefail

readonly ALLOWED_KEYS=(
    DEPLOY_USER
    DEPLOY_AUTHORIZED_KEY_FILE
    SSH_PORT
    PUBLIC_HTTP_PORT
    PUBLIC_HTTPS_PORT
    APP_BIND_ADDRESS
    APP_BIND_PORT
    DISK_MIN_FREE_GB
    DOCKER_DATA_ROOT
    REGISTRY_PASSWORD_FILE
    BACKUP_ALERT_URL_FILE
    AGELTO_REPOSITORY
    AGELTO_CANDIDATE_SHA
    AGELTO_CHECKOUT_DIR
    GITHUB_ED25519_HOST_KEY
)

fail() {
    printf '[FAIL] PROFILE %s\n' "$*" >&2
    exit 1
}

usage() {
    printf 'Usage: production-profile-check.sh check --profile /absolute/path\n' >&2
}

[[ ${1:-} == check && ${2:-} == --profile && ${3:-} = /* && $# -eq 3 ]] || {
    usage
    exit 2
}

readonly profile=$3
[[ -f $profile && ! -L $profile ]] || fail 'must be a regular non-symlink file'

permissions=$(stat -c '%a' "$profile")
(( (8#${permissions: -3} & 8#077) == 0 )) || fail 'must not be group/world-accessible'

declare -A values=()
while IFS= read -r line || [[ -n $line ]]; do
    [[ -z $line || $line == \#* ]] && continue
    [[ $line != *$'\r'* && $line == *=* ]] || fail 'contains a malformed or control-character line'
    key=${line%%=*}
    value=${line#*=}
    [[ $key =~ ^[A-Z][A-Z0-9_]*$ ]] || fail 'contains an invalid key'
    case " ${ALLOWED_KEYS[*]} " in
        *" $key "*) ;;
        *) fail "contains unknown key $key" ;;
    esac
    [[ ! -v "values[$key]" ]] || fail "contains duplicate key $key"
    [[ -n $value && $value != *CHANGE_ME* && $value != *'<'* && $value != *'>'* ]] || fail "contains placeholder for $key"
    [[ $value != *$'\t'* ]] || fail "contains a control character in $key"
    values["$key"]=$value
done < "$profile"

for key in "${ALLOWED_KEYS[@]}"; do
    [[ -v "values[$key]" ]] || fail "misses $key"
done

[[ ${values[DEPLOY_USER]} == deploy ]] || fail 'DEPLOY_USER must be deploy'
[[ ${values[DEPLOY_AUTHORIZED_KEY_FILE]} = /* ]] || fail 'DEPLOY_AUTHORIZED_KEY_FILE must be absolute'
[[ ${values[SSH_PORT]} =~ ^[0-9]{1,5}$ ]] || fail 'SSH_PORT must be numeric'
(( values[SSH_PORT] >= 1 && values[SSH_PORT] <= 65535 )) || fail 'SSH_PORT is out of range'
[[ ${values[PUBLIC_HTTP_PORT]} == 80 && ${values[PUBLIC_HTTPS_PORT]} == 443 ]] || fail 'public web ports must be 80/443'
[[ ${values[APP_BIND_ADDRESS]} == 127.0.0.1 || ${values[APP_BIND_ADDRESS]} == ::1 ]] || fail 'APP_BIND_ADDRESS must be loopback'
[[ ${values[APP_BIND_PORT]} =~ ^[0-9]{1,5}$ ]] || fail 'APP_BIND_PORT must be numeric'
(( values[APP_BIND_PORT] >= 1 && values[APP_BIND_PORT] <= 65535 )) || fail 'APP_BIND_PORT is out of range'
[[ ${values[DISK_MIN_FREE_GB]} =~ ^[1-9][0-9]*$ ]] || fail 'DISK_MIN_FREE_GB must be positive'
[[ ${values[DOCKER_DATA_ROOT]} = /* && ${values[DOCKER_DATA_ROOT]} != *'/../'* ]] || fail 'DOCKER_DATA_ROOT must be an absolute contained path'
for key in REGISTRY_PASSWORD_FILE BACKUP_ALERT_URL_FILE; do
    [[ ${values[$key]} = /* ]] || fail "$key must be absolute"
done
[[ ${values[AGELTO_REPOSITORY]} =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || fail 'AGELTO_REPOSITORY must be owner/repository'
[[ ${values[AGELTO_CANDIDATE_SHA]} =~ ^[0-9a-f]{40}$ ]] || fail 'AGELTO_CANDIDATE_SHA must be a full lowercase SHA-1'
[[ ${values[AGELTO_CHECKOUT_DIR]} = /srv/agelto/* && ${values[AGELTO_CHECKOUT_DIR]} != *'/../'* ]] || fail 'AGELTO_CHECKOUT_DIR must be below /srv/agelto'
[[ ${values[GITHUB_ED25519_HOST_KEY]} =~ ^AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+/=]+$ ]] || fail 'GITHUB_ED25519_HOST_KEY must be an Ed25519 key body'

printf '[PASS] PROFILE closed schema, permissions and production boundaries are valid (values redacted)\n'
