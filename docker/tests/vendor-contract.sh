#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

bash -n "$root_dir/docker/scripts/vendor-sync.sh"
python3 -m json.tool "$root_dir/docker/private-vendors.lock.json" >/dev/null
git -C "$root_dir" check-ignore local/vendors/azguard/private-file >/dev/null
! git -C "$root_dir" check-ignore local/vendors/.gitkeep

fixture_root="$temp_dir/repo"
mkdir -p "$fixture_root/docker/scripts" "$fixture_root/docker"
cp "$root_dir/docker/scripts/vendor-sync.sh" "$fixture_root/docker/scripts/vendor-sync.sh"
cp "$root_dir/docker/private-vendors.lock.json" "$fixture_root/docker/private-vendors.lock.json"
chmod +x "$fixture_root/docker/scripts/vendor-sync.sh"

mkdir -p "$temp_dir/bin"
cat > "$temp_dir/bin/git" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$1" == 'clone' ]]; then
    target=${3:?}
    mkdir -p "$target/.git" "$target/packages/core" "$target/packages/filament"
    printf '%s\n' 'https://github.com/axiomasoft/azguard-private.git' > "$target/origin"
    fixture_root=$(cd "$target/../../.." && pwd -P)
    python3 - "$fixture_root/docker/private-vendors.lock.json" <<'PY' > "$target/head"
import json
import sys

print(json.load(open(sys.argv[1], encoding='utf-8'))['commit'])
PY
    : > "$target/packages/core/composer.json"
    : > "$target/packages/filament/composer.json"
    exit 0
fi

[[ "$1" == '-C' ]]
repo=$2
shift 2
case "$1" in
    config) cat "$repo/origin" ;;
    rev-parse) cat "$repo/head" ;;
    status)
        if [[ " $* " == *' packages/'* ]]; then
            [[ -f "$repo/package-dirty" ]] && printf ' M packages/core/composer.json\n'
        else
            [[ -f "$repo/tree-dirty" ]] && printf ' M README.md\n'
        fi
        ;;
    fetch) ;;
    checkout) printf '%s\n' "$3" > "$repo/head" ;;
    *) echo "unexpected fake git invocation: $*" >&2; exit 64 ;;
esac
EOF
chmod +x "$temp_dir/bin/git"

run_fixture() {
    PATH="$temp_dir/bin:$PATH" bash "$fixture_root/docker/scripts/vendor-sync.sh" "$@"
}

! run_fixture --check
[[ ! -e "$fixture_root/local/vendors/azguard" ]]
run_fixture
run_fixture --check

checkout="$fixture_root/local/vendors/azguard"
expected_sha=$(python3 - "$root_dir/docker/private-vendors.lock.json" <<'PY'
import json
import sys

print(json.load(open(sys.argv[1], encoding='utf-8'))['commit'])
PY
)
printf '%s\n' 'https://github.com/other/azguard.git' > "$checkout/origin"
! run_fixture --check
printf '%s\n' 'https://github.com/axiomasoft/azguard-private.git' > "$checkout/origin"
printf '%s\n' '0000000000000000000000000000000000000000' > "$checkout/head"
! run_fixture --check
printf '%s\n' "$expected_sha" > "$checkout/head"
touch "$checkout/package-dirty"
! run_fixture --check
rm "$checkout/package-dirty"
touch "$checkout/tree-dirty"
! run_fixture
rm "$checkout/tree-dirty"
run_fixture --check

grep -F 'local/vendors/azguard/packages/core' "$root_dir/composer.json" >/dev/null
grep -F 'local/vendors/azguard/packages/filament' "$root_dir/composer.json" >/dev/null
! grep -F '../../packages/azguard' "$root_dir/composer.json"
grep -F 'COPY --exclude=local/vendors/azguard' "$root_dir/docker/php/Dockerfile" >/dev/null
grep -F 'COPY --from=dependencies' "$root_dir/docker/php/Dockerfile" >/dev/null
! grep -F 'COPY --chown=www-data:www-data . /var/www/html' "$root_dir/docker/php/Dockerfile"
grep -F 'vendor:sync' "$root_dir/docker/Makefile" >/dev/null
grep -F 'vendor:check' "$root_dir/docker/Makefile" >/dev/null

bash "$root_dir/docker/scripts/vendor-sync.sh" --composer-check

image='agelto/vendor-contract:validation'
docker build --target application -t "$image" -f "$root_dir/docker/php/Dockerfile" "$root_dir"
! docker history --no-trunc "$image" | grep -E '(token@|password=|secret=)'
docker run --rm --entrypoint sh "$image" -c '
    test ! -e /var/www/html/local/vendors/azguard
    ! find /var/www/html -name .git -print -quit | grep -q .
    test -d /var/www/html/vendor/axioma-studio/azguard-core
    test -d /var/www/html/vendor/axioma-studio/azguard-filament
'

echo 'Vendor contract passed'
