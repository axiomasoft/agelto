#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

bash -n "$root_dir/docker/scripts/vendor-sync.sh"
python3 -m json.tool "$root_dir/docker/private-vendors.lock.json" >/dev/null
git -C "$root_dir" check-ignore local/vendors/azguard/private-file >/dev/null
! git -C "$root_dir" check-ignore local/vendors/.gitkeep

# The same isolated fixture cases cover v1 migration, both v2 repositories, SHA/origin/
# dirty/path guards, offline copied installs and sanitized snapshots without network.
python3 -B -m unittest discover -s "$root_dir/docker/tests/process-core" -p test_rig.py -k VendorDeliveryTest -v

grep -F 'local/vendors/azguard/packages/core' "$root_dir/composer.json" >/dev/null
grep -F 'local/vendors/azguard/packages/filament' "$root_dir/composer.json" >/dev/null
! grep -F '../../packages/azguard' "$root_dir/composer.json"
grep -F 'COPY --exclude=local/vendors/azguard --exclude=local/vendors/proxyma' "$root_dir/docker/php/Dockerfile" >/dev/null
grep -F 'COPY --from=dependencies' "$root_dir/docker/php/Dockerfile" >/dev/null
! grep -F 'COPY --chown=www-data:www-data . /var/www/html' "$root_dir/docker/php/Dockerfile"
grep -F 'vendor:sync' "$root_dir/docker/Makefile" >/dev/null
grep -F 'vendor:check' "$root_dir/docker/Makefile" >/dev/null

bash "$root_dir/docker/scripts/vendor-sync.sh" --check --composer-check

image='agelto/vendor-contract:validation'
docker build --target application -t "$image" -f "$root_dir/docker/php/Dockerfile" "$root_dir"
! docker history --no-trunc "$image" | grep -E '(token@|password=|secret=)'
docker run --rm --entrypoint sh "$image" -c '
    test ! -e /var/www/html/local/vendors/azguard
    test ! -e /var/www/html/local/vendors/proxyma
    ! find /var/www/html -name .git -print -quit | grep -q .
    test -d /var/www/html/vendor/axiomasoft/azguard
    test -d /var/www/html/vendor/axiomasoft/azguard-filament
    test -d /var/www/html/vendor/axiomasoft/proxyma
    test -d /var/www/html/vendor/axiomasoft/proxyma-filament
    test ! -L /var/www/html/vendor/axiomasoft/proxyma
    test ! -L /var/www/html/vendor/axiomasoft/proxyma-filament
    ! find /var/www/html -name auth.json -o -name .env -o -name credentials -o -name id_ed25519 | grep -q .
'

echo 'Vendor contract passed'
