#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
manifest="$root_dir/docker/private-vendors.lock.json"
checkout="$root_dir/local/vendors/azguard"
mode='sync'
composer_check=false

usage() {
    echo 'Usage: vendor-sync.sh [--check] [--composer-check]' >&2
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --check) mode='check'; shift ;;
        --composer-check) composer_check=true; shift ;;
        *) usage; exit 64 ;;
    esac
done

[[ -f "$manifest" ]] || { echo "Vendor manifest is required: $manifest" >&2; exit 1; }

mapfile -t manifest_values < <(python3 - "$manifest" <<'PY'
import json
import re
import sys

path = sys.argv[1]
try:
    value = json.load(open(path, encoding='utf-8'))
except (OSError, json.JSONDecodeError) as error:
    raise SystemExit(f"Invalid vendor manifest: {error}")

if set(value) != {'version', 'repository', 'commit', 'packages'} or value['version'] != 1:
    raise SystemExit('Vendor manifest must be version 1 with only version/repository/commit/packages')
repository = value['repository']
if not isinstance(repository, dict) or set(repository) != {'host', 'path'}:
    raise SystemExit('Vendor manifest repository must contain only host and path')
host, repo_path = repository['host'], repository['path']
if not isinstance(host, str) or not re.fullmatch(r'[A-Za-z0-9.-]+', host):
    raise SystemExit('Vendor manifest host is invalid')
if not isinstance(repo_path, str) or not re.fullmatch(r'[A-Za-z0-9._-]+/[A-Za-z0-9._-]+', repo_path):
    raise SystemExit('Vendor manifest repository path is invalid')
commit = value['commit']
if not isinstance(commit, str) or not re.fullmatch(r'[0-9a-f]{40}', commit):
    raise SystemExit('Vendor manifest commit must be a full lowercase SHA-1')
packages = value['packages']
if not isinstance(packages, list) or not packages:
    raise SystemExit('Vendor manifest packages must be a non-empty list')
seen_names, seen_paths = set(), set()
for package in packages:
    if not isinstance(package, dict) or set(package) != {'name', 'path'}:
        raise SystemExit('Each vendor package must contain only name and path')
    name, package_path = package['name'], package['path']
    if (not isinstance(name, str) or not re.fullmatch(r'[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*', name)
            or not isinstance(package_path, str)
            or not re.fullmatch(r'packages/[A-Za-z0-9._-]+', package_path)
            or name in seen_names or package_path in seen_paths):
        raise SystemExit('Vendor manifest package allowlist is invalid')
    seen_names.add(name)
    seen_paths.add(package_path)
print(host)
print(repo_path)
print(commit)
for package in packages:
    print(package['path'])
PY
)

host=${manifest_values[0]}
repo_path=${manifest_values[1]}
commit=${manifest_values[2]}
package_paths=("${manifest_values[@]:3}")

normalize_origin() {
    local origin=$1 normalized=''

    [[ "$origin" != *'://'[^/]*'@'* && "$origin" != *@*:* ]] || return 1
    case "$origin" in
        "https://$host/$repo_path"|"https://$host/$repo_path.git"|"http://$host/$repo_path"|"http://$host/$repo_path.git"|"git@$host:$repo_path"|"git@$host:$repo_path.git"|"ssh://git@$host/$repo_path"|"ssh://git@$host/$repo_path.git")
            normalized="$host/$repo_path"
            ;;
    esac
    [[ "$normalized" == "$host/$repo_path" ]] || return 1
    printf '%s\n' "$normalized"
}

require_checkout() {
    [[ -d "$checkout/.git" ]] || { echo "Vendor checkout is missing: $checkout" >&2; return 1; }

    local origin
    origin=$(git -C "$checkout" config --get remote.origin.url) || { echo 'Vendor checkout has no origin' >&2; return 1; }
    normalize_origin "$origin" >/dev/null || { echo 'Vendor checkout origin does not match the manifest' >&2; return 1; }

    local package_path
    for package_path in "${package_paths[@]}"; do
        [[ -f "$checkout/$package_path/composer.json" ]] || { echo "Vendor package is missing: $package_path" >&2; return 1; }
        if [[ -n $(git -C "$checkout" status --porcelain --untracked-files=all -- "$package_path") ]]; then
            echo "Vendor package is dirty: $package_path" >&2
            return 1
        fi
    done

    local head
    head=$(git -C "$checkout" rev-parse HEAD)
    [[ "$head" == "$commit" ]] || { echo "Vendor checkout is pinned to $head, expected $commit" >&2; return 1; }
}

if [[ "$mode" == 'check' ]]; then
    require_checkout
else
    if [[ ! -e "$checkout" ]]; then
        mkdir -p "$(dirname "$checkout")"
        git clone "https://$host/$repo_path.git" "$checkout"
        git -C "$checkout" checkout --detach "$commit"
    else
        [[ -d "$checkout/.git" ]] || { echo "Vendor checkout is not a Git checkout: $checkout" >&2; exit 1; }
        if [[ -n $(git -C "$checkout" status --porcelain --untracked-files=all) ]]; then
            echo 'Vendor checkout is dirty; sync refuses to overwrite it' >&2
            exit 1
        fi
        git -C "$checkout" fetch --quiet origin "$commit"
        git -C "$checkout" checkout --detach "$commit"
    fi
    require_checkout
fi

if [[ "$composer_check" == true ]]; then
    (
        cd "$root_dir"
        composer validate --no-check-publish
        composer install --dry-run --no-dev --no-scripts --no-interaction --no-progress --prefer-dist
    )
fi

echo "Vendor checkout verified at $commit"
