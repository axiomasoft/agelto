#!/bin/sh
# Prepares the S3-compatible object store (Silo/MinIO) for the application:
# both buckets, the agelto-app-rw policy, the application user with that policy, and a
# final check that the application credentials can list both buckets.
#
# Runs inside the app container (it ships `mc`), started by dev:storage-init and
# prod:storage:init. Every step is idempotent, so the target can be rerun.
#
# Usage: storage-bootstrap.sh [default-estimates-bucket]
#   The argument is used only when AWS_ESTIMATES_BUCKET is unset (the dev environment).
#
# Environment: AWS_ENDPOINT, AWS_BUCKET, AWS_ESTIMATES_BUCKET, AWS_ACCESS_KEY_ID,
# AWS_SECRET_ACCESS_KEY_FILE, MINIO_ROOT_USER (default agelto).
set -eu

ADMIN_ALIAS=agelto-bootstrap
APP_ALIAS=agelto-app
POLICY_NAME=agelto-app-rw
ROOT_PASSWORD_FILE=/run/secrets/minio_root_password
POLICY_TEMPLATE=/run/agelto/storage/app-policy.json

fail() {
    printf 'storage-bootstrap: %s\n' "$*" >&2
    exit 1
}

# Runs one mc command against the private config directory and names the step on failure.
mc_step() {
    description=$1
    shift
    MC_CONFIG_DIR="$mc_config" mc "$@" >/dev/null || fail "$description failed"
}

require_env() {
    eval "value=\${$1:-}"
    [ -n "$value" ] || fail "$1 is not set"
}

require_bucket_name() {
    case $2 in
        '') fail "$1 is not set" ;;
        *[!a-z0-9.-]*) fail "$1 '$2' is not a valid bucket name" ;;
    esac
}

read_secret() {
    [ -r "$1" ] || fail "secret file $1 is not readable"
    cat "$1"
}

check_inputs() {
    for name in AWS_ENDPOINT AWS_BUCKET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY_FILE; do
        require_env "$name"
    done
    estimates_bucket=${AWS_ESTIMATES_BUCKET:-${default_estimates_bucket}}
    require_bucket_name AWS_BUCKET "$AWS_BUCKET"
    require_bucket_name AWS_ESTIMATES_BUCKET "$estimates_bucket"
    for file in "$ROOT_PASSWORD_FILE" "$AWS_SECRET_ACCESS_KEY_FILE" "$POLICY_TEMPLATE"; do
        [ -r "$file" ] || fail "$file is not readable"
    done
}

# mc keeps aliases and credentials in its config directory; use a private one per run.
prepare_config() {
    mc_config=$(mktemp -d) || fail "cannot create a temporary mc config directory"
    trap 'rm -rf "$mc_config"' EXIT
}

# Secrets are read into variables first: a failed assignment stops the script under set -e,
# while a failed substitution inside an argument list would not.
connect_admin() {
    root_password=$(read_secret "$ROOT_PASSWORD_FILE")
    mc_step "connecting as the storage root user" alias set "$ADMIN_ALIAS" "$AWS_ENDPOINT" \
        "${MINIO_ROOT_USER:-agelto}" "$root_password"
}

ensure_buckets() {
    for bucket in "$AWS_BUCKET" "$estimates_bucket"; do
        mc_step "creating bucket $bucket" mb --ignore-existing "$ADMIN_ALIAS/$bucket"
    done
}

ensure_policy() {
    sed -e "s/@AWS_BUCKET@/$AWS_BUCKET/g" -e "s/@AWS_ESTIMATES_BUCKET@/$estimates_bucket/g" \
        "$POLICY_TEMPLATE" > "$mc_config/app-policy.json" || fail "rendering the $POLICY_NAME policy failed"
    mc_step "creating policy $POLICY_NAME" admin policy create "$ADMIN_ALIAS" "$POLICY_NAME" "$mc_config/app-policy.json"
}

ensure_app_user() {
    app_secret=$(read_secret "$AWS_SECRET_ACCESS_KEY_FILE")
    mc_step "creating the application user" admin user add "$ADMIN_ALIAS" "$AWS_ACCESS_KEY_ID" "$app_secret"
}

# `policy entities` exits 0 even when the user has no mapping, so read its JSON instead of
# its exit code. Attach only when the user is missing, because older MinIO rejects a
# repeated attach.
ensure_policy_attached() {
    if MC_CONFIG_DIR="$mc_config" mc admin policy entities "$ADMIN_ALIAS" --policy "$POLICY_NAME" \
        --user "$AWS_ACCESS_KEY_ID" --json 2>/dev/null | grep -Fq "\"user\":\"$AWS_ACCESS_KEY_ID\""; then
        return 0
    fi
    mc_step "attaching $POLICY_NAME to the application user" admin policy attach "$ADMIN_ALIAS" "$POLICY_NAME" \
        --user "$AWS_ACCESS_KEY_ID"
}

verify_app_access() {
    mc_step "connecting as the application user" alias set "$APP_ALIAS" "$AWS_ENDPOINT" \
        "$AWS_ACCESS_KEY_ID" "$app_secret"
    for bucket in "$AWS_BUCKET" "$estimates_bucket"; do
        mc_step "listing bucket $bucket with the application credentials" ls "$APP_ALIAS/$bucket"
    done
}

default_estimates_bucket=${1:-}
check_inputs
prepare_config
connect_admin
ensure_buckets
ensure_policy
ensure_app_user
ensure_policy_attached
verify_app_access
