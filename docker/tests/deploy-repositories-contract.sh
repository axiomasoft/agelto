#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
script="$root_dir/docker/scripts/deploy-repositories.sh"
example="$root_dir/docker/host/production-profile.example"

bash -n "$script"
grep -F 'IdentitiesOnly yes' "$script" >/dev/null
grep -F 'StrictHostKeyChecking yes' "$script" >/dev/null
grep -F 'ForwardAgent no' "$script" >/dev/null
grep -F 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU' "$script" >/dev/null
grep -F -- '--enrollment-confirmed' "$script" >/dev/null
grep -F 'candidate_vendor_verifier' "$script" >/dev/null
grep -F 'as_deploy "$candidate_vendor_verifier" --check' "$script" >/dev/null
! grep -Eq 'ssh-keyscan|StrictHostKeyChecking=no|ssh -A|git reset --hard|git clean|token@' "$script"

for key in AGELTO_REPOSITORY AGELTO_CANDIDATE_SHA AGELTO_CHECKOUT_DIR GITHUB_ED25519_HOST_KEY; do
    grep -q "^$key=" "$example"
done

temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT
profile="$temp_dir/profile"
cp "$example" "$profile"
sed -i \
    -e 's#^DEPLOY_AUTHORIZED_KEY_FILE=.*#DEPLOY_AUTHORIZED_KEY_FILE=/root/deploy.pub#' \
    -e 's#^REGISTRY_PASSWORD_FILE=.*#REGISTRY_PASSWORD_FILE=/root/registry#' \
    -e 's#^BACKUP_ALERT_URL_FILE=.*#BACKUP_ALERT_URL_FILE=/root/alert#' \
    -e 's#^AGELTO_REPOSITORY=.*#AGELTO_REPOSITORY=axiomasoft/agelto#' \
    -e 's#^AGELTO_CANDIDATE_SHA=.*#AGELTO_CANDIDATE_SHA=0123456789abcdef0123456789abcdef01234567#' \
    -e "s#^AGELTO_CHECKOUT_DIR=.*#AGELTO_CHECKOUT_DIR=$temp_dir/srv/agelto/repository#" \
    -e 's#^GITHUB_ED25519_HOST_KEY=.*#GITHUB_ED25519_HOST_KEY=AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl#' \
    "$profile"
chmod 0600 "$profile"

! bash "$script" sync --profile "$profile"
sed -i 's/^AGELTO_CANDIDATE_SHA=.*/AGELTO_CANDIDATE_SHA=not-a-sha/' "$profile"
! bash "$script" prepare --profile "$profile"

echo 'Deploy repositories contract passed'
