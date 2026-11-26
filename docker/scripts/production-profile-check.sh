#!/usr/bin/env bash

readonly PRODUCTION_PROFILE_KEYS=(
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

production_profile_fail() {
    printf '[FAIL] PROFILE %s\n' "$*" >&2
    exit 1
}

# Fill the caller's associative array with the values that were validated.
production_profile_read() {
    local path=$1
    local -n values=$2
    local permissions line key value
    values=()

    [[ -f $path && ! -L $path ]] || production_profile_fail 'must be a regular non-symlink file'

    permissions=$(stat -c '%a' "$path")
    (( (8#${permissions: -3} & 8#077) == 0 )) || production_profile_fail 'must not be group/world-accessible'

    while IFS= read -r line || [[ -n $line ]]; do
        [[ -z $line || $line == \#* ]] && continue
        [[ $line != *$'\r'* && $line == *=* ]] || production_profile_fail 'contains a malformed or control-character line'
        key=${line%%=*}
        value=${line#*=}
        [[ $key =~ ^[A-Z][A-Z0-9_]*$ ]] || production_profile_fail 'contains an invalid key'
        case " ${PRODUCTION_PROFILE_KEYS[*]} " in
            *" $key "*) ;;
            *) production_profile_fail "contains unknown key $key" ;;
        esac
        [[ ! -v "values[$key]" ]] || production_profile_fail "contains duplicate key $key"
        [[ -n $value && $value != *CHANGE_ME* && $value != *'<'* && $value != *'>'* ]] || production_profile_fail "contains placeholder for $key"
        [[ $value != *$'\t'* ]] || production_profile_fail "contains a control character in $key"
        values["$key"]=$value
    done < "$path"

    for key in "${PRODUCTION_PROFILE_KEYS[@]}"; do
        [[ -v "values[$key]" ]] || production_profile_fail "misses $key"
    done

    [[ ${values[DEPLOY_USER]} == deploy ]] || production_profile_fail 'DEPLOY_USER must be deploy'
    [[ ${values[DEPLOY_AUTHORIZED_KEY_FILE]} = /* ]] || production_profile_fail 'DEPLOY_AUTHORIZED_KEY_FILE must be absolute'
    for key in SSH_PORT APP_BIND_PORT; do
        value=${values[$key]}
        [[ $value =~ ^[0-9]{1,5}$ ]] || production_profile_fail "$key must be numeric"
        (( 10#$value >= 1 && 10#$value <= 65535 )) || production_profile_fail "$key is out of range"
    done
    [[ ${values[PUBLIC_HTTP_PORT]} == 80 && ${values[PUBLIC_HTTPS_PORT]} == 443 ]] || production_profile_fail 'public web ports must be 80/443'
    [[ ${values[APP_BIND_ADDRESS]} == 127.0.0.1 || ${values[APP_BIND_ADDRESS]} == ::1 ]] || production_profile_fail 'APP_BIND_ADDRESS must be loopback'
    [[ ${values[DISK_MIN_FREE_GB]} =~ ^[1-9][0-9]*$ ]] || production_profile_fail 'DISK_MIN_FREE_GB must be positive'
    [[ ${values[DOCKER_DATA_ROOT]} = /* && ${values[DOCKER_DATA_ROOT]} != *'/../'* ]] || production_profile_fail 'DOCKER_DATA_ROOT must be an absolute contained path'
    for key in REGISTRY_PASSWORD_FILE BACKUP_ALERT_URL_FILE; do
        [[ ${values[$key]} = /* ]] || production_profile_fail "$key must be absolute"
    done
    [[ ${values[AGELTO_REPOSITORY]} =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || production_profile_fail 'AGELTO_REPOSITORY must be owner/repository'
    [[ ${values[AGELTO_CANDIDATE_SHA]} =~ ^[0-9a-f]{40}$ ]] || production_profile_fail 'AGELTO_CANDIDATE_SHA must be a full lowercase SHA-1'
    [[ ${values[AGELTO_CHECKOUT_DIR]} = /srv/agelto/* && ${values[AGELTO_CHECKOUT_DIR]} != *'/../'* ]] || production_profile_fail 'AGELTO_CHECKOUT_DIR must be below /srv/agelto'
    [[ ${values[GITHUB_ED25519_HOST_KEY]} =~ ^AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+/=]+$ ]] || production_profile_fail 'GITHUB_ED25519_HOST_KEY must be an Ed25519 key body'
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    set -euo pipefail
    [[ ${1:-} == check && ${2:-} == --profile && ${3:-} = /* && $# -eq 3 ]] || {
        printf 'Usage: production-profile-check.sh check --profile /absolute/path\n' >&2
        exit 2
    }
    declare -A checked_profile=()
    production_profile_read "$3" checked_profile
    printf '[PASS] PROFILE closed schema, permissions and production boundaries are valid (values redacted)\n'
fi
