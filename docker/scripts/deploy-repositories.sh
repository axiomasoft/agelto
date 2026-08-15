#!/usr/bin/env bash

set -euo pipefail

readonly script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly root_dir="${script_dir%/scripts}"
readonly vendor_manifest="$root_dir/private-vendors.lock.json"

mode=''
profile=''
enrollment_confirmed=false
fixture_root=''
adapter=''

usage() {
    printf '%s\n' 'Usage: deploy-repositories.sh prepare|sync --profile /absolute/path [--enrollment-confirmed]' >&2
    printf '%s\n' '       deploy-repositories.sh fixture --profile /absolute/path --fixture-root /tmp/path --adapter /tmp/fake-tool' >&2
}

fail() { printf '[FAIL] %s\n' "$*" >&2; exit 1; }
pass() { printf '[PASS] %s\n' "$*"; }

while (($#)); do
    case "$1" in
        prepare|sync|fixture) [[ -z "$mode" ]] || fail 'choose one mode'; mode="$1" ;;
        --profile) profile="${2:-}"; shift ;;
        --enrollment-confirmed) enrollment_confirmed=true ;;
        --fixture-root) fixture_root="${2:-}"; shift ;;
        --adapter) adapter="${2:-}"; shift ;;
        *) usage; exit 2 ;;
    esac
    shift
done

[[ -n "$mode" && "$profile" = /* ]] || { usage; exit 2; }

declare -A PROFILE=()
readonly REQUIRED_KEYS=(DEPLOY_USER AGELTO_REPOSITORY AGELTO_CANDIDATE_SHA AGELTO_CHECKOUT_DIR GITHUB_ED25519_HOST_KEY)
readonly ALLOWED_KEYS=(DEPLOY_USER DEPLOY_AUTHORIZED_KEY_FILE SSH_PORT PUBLIC_HTTP_PORT PUBLIC_HTTPS_PORT APP_BIND_ADDRESS APP_BIND_PORT DISK_MIN_FREE_GB DOCKER_DATA_ROOT REGISTRY_PASSWORD_FILE BACKUP_ALERT_URL_FILE AGELTO_REPOSITORY AGELTO_CANDIDATE_SHA AGELTO_CHECKOUT_DIR GITHUB_ED25519_HOST_KEY)

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
        case " ${ALLOWED_KEYS[*]} " in *" $key "*) ;; *) fail "profile has unknown key $key" ;; esac
        case " ${REQUIRED_KEYS[*]} " in *" $key "*) ;; *) continue ;; esac
        [[ ! -v "PROFILE[$key]" ]] || fail "profile has duplicate key $key"
        [[ -n "$value" && "$value" != *CHANGE_ME* && "$value" != *'<'* && "$value" != *'>'* ]] || fail "profile has placeholder for $key"
        PROFILE["$key"]="$value"
    done < "$profile"

    for key in "${REQUIRED_KEYS[@]}"; do [[ -v "PROFILE[$key]" ]] || fail "profile misses $key"; done
    [[ "${PROFILE[DEPLOY_USER]}" == deploy ]] || fail 'DEPLOY_USER must be deploy'
    [[ "${PROFILE[AGELTO_REPOSITORY]}" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || fail 'AGELTO_REPOSITORY must be owner/repository'
    [[ "${PROFILE[AGELTO_CANDIDATE_SHA]}" =~ ^[0-9a-f]{40}$ ]] || fail 'AGELTO_CANDIDATE_SHA must be a full lowercase SHA-1'
    [[ "${PROFILE[AGELTO_CHECKOUT_DIR]}" = /srv/agelto/* && "${PROFILE[AGELTO_CHECKOUT_DIR]}" != */..* ]] || fail 'AGELTO_CHECKOUT_DIR must be a safe /srv/agelto path'
    [[ "${PROFILE[GITHUB_ED25519_HOST_KEY]}" =~ ^AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+/=]+$ ]] || fail 'GITHUB_ED25519_HOST_KEY must be an Ed25519 public key body'
}

deploy_home() { printf '/home/%s\n' "${PROFILE[DEPLOY_USER]}"; }
ssh_dir() { printf '%s/.ssh\n' "$(deploy_home)"; }
ssh_config() { printf '%s/config\n' "$(ssh_dir)"; }
known_hosts() { printf '%s/known_hosts\n' "$(ssh_dir)"; }

as_deploy() {
    runuser -u "${PROFILE[DEPLOY_USER]}" -- env "HOME=$(deploy_home)" "$@"
}

key_path() { printf '%s/%s_deploy_ed25519\n' "$(ssh_dir)" "$1"; }

assert_key_pair() {
    local path=$1
    if [[ -e "$path" || -e "$path.pub" ]]; then
        [[ -f "$path" && ! -L "$path" && -f "$path.pub" && ! -L "$path.pub" ]] || fail "incomplete or unsafe key pair: $path"
        [[ "$(stat -c '%a' "$path")" == 600 && "$(stat -c '%a' "$path.pub")" == 644 ]] || fail "key permissions must be 0600/0644: $path"
        return
    fi

    as_deploy ssh-keygen -q -t ed25519 -N '' -C "agelto ${2} deploy key" -f "$path"
    chmod 0600 "$path"
    chmod 0644 "$path.pub"
}

write_ssh_trust() {
    install -d -m 0700 -o "${PROFILE[DEPLOY_USER]}" -g "${PROFILE[DEPLOY_USER]}" "$(ssh_dir)"

    local trust_file fingerprint
    trust_file="$(mktemp)"
    trap 'rm -f "$trust_file"' RETURN
    printf 'github.com ssh-ed25519 %s\n' "${PROFILE[GITHUB_ED25519_HOST_KEY]}" > "$trust_file"
    fingerprint="$(ssh-keygen -lf "$trust_file" -E sha256 | awk '{print $2}')"
    [[ "$fingerprint" == 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU' ]] || fail 'GitHub Ed25519 host key does not match the verified fingerprint'
    install -m 0600 -o "${PROFILE[DEPLOY_USER]}" -g "${PROFILE[DEPLOY_USER]}" "$trust_file" "$(known_hosts)"

    local config_file
    config_file="$(mktemp)"
    trap 'rm -f "$trust_file" "$config_file"' RETURN
    cat > "$config_file" <<EOF
Host github-agelto
    HostName github.com
    HostKeyAlias github.com
    User git
    IdentityFile $(key_path agelto)
    IdentitiesOnly yes
    IdentityAgent none
    ForwardAgent no
    StrictHostKeyChecking yes
    UserKnownHostsFile $(known_hosts)

Host github-azguard
    HostName github.com
    HostKeyAlias github.com
    User git
    IdentityFile $(key_path azguard)
    IdentitiesOnly yes
    IdentityAgent none
    ForwardAgent no
    StrictHostKeyChecking yes
    UserKnownHostsFile $(known_hosts)
EOF
    install -m 0600 -o "${PROFILE[DEPLOY_USER]}" -g "${PROFILE[DEPLOY_USER]}" "$config_file" "$(ssh_config)"
    trap - RETURN
    rm -f "$trust_file" "$config_file"
}

assert_ssh_alias() {
    local alias=$1 expected_key=$2 rendered
    rendered="$(as_deploy ssh -F "$(ssh_config)" -G "$alias")"
    grep -Fx 'stricthostkeychecking yes' <<<"$rendered" >/dev/null || fail "$alias does not enforce strict host checking"
    grep -Fx 'identitiesonly yes' <<<"$rendered" >/dev/null || fail "$alias does not enforce one identity"
    grep -Fx "identityfile $expected_key" <<<"$rendered" >/dev/null || fail "$alias has the wrong identity"
    grep -Fx 'forwardagent no' <<<"$rendered" >/dev/null || fail "$alias permits agent forwarding"
}

canonical_origin() { printf 'git@github.com:%s.git\n' "$1"; }
alias_origin() { printf 'git@%s:%s.git\n' "$1" "$2"; }

normalize_origin() {
    local origin=$1 repository=$2
    [[ "$origin" != *'://'[^/]*'@'* && "$origin" != *@*:*:* ]] || return 1
    case "$origin" in
        "https://github.com/$repository"|"https://github.com/$repository.git"|"git@github.com:$repository"|"git@github.com:$repository.git")
            printf 'github.com/%s\n' "$repository"
            ;;
        *) return 1 ;;
    esac
}

ensure_clean_checkout() {
    local checkout=$1 repository=$2 expected_sha=$3 alias=$4
    if [[ -e "$checkout" ]]; then
        [[ -d "$checkout/.git" ]] || fail "checkout is not a Git worktree: $checkout"
        [[ -z "$(as_deploy git -C "$checkout" status --porcelain --untracked-files=all)" ]] || fail "checkout is dirty or has untracked files: $checkout"
        local origin
        origin="$(as_deploy git -C "$checkout" config --get remote.origin.url)" || fail "checkout has no origin: $checkout"
        normalize_origin "$origin" "$repository" >/dev/null || fail "checkout origin is not the pinned GitHub repository: $checkout"
    else
        install -d -m 0755 -o "${PROFILE[DEPLOY_USER]}" -g "${PROFILE[DEPLOY_USER]}" "$(dirname "$checkout")"
        as_deploy git clone --no-checkout "$(alias_origin "$alias" "$repository")" "$checkout"
        as_deploy git -C "$checkout" remote set-url origin "$(canonical_origin "$repository")"
    fi

    as_deploy git -C "$checkout" fetch --quiet --no-tags "$(alias_origin "$alias" "$repository")" "$expected_sha"
    as_deploy git -C "$checkout" checkout --detach "$expected_sha"
    [[ "$(as_deploy git -C "$checkout" rev-parse HEAD)" == "$expected_sha" ]] || fail "checkout is not pinned to $expected_sha: $checkout"
    as_deploy git -C "$checkout" symbolic-ref -q HEAD >/dev/null && fail "checkout must be detached: $checkout"
    [[ -z "$(as_deploy git -C "$checkout" status --porcelain --untracked-files=all)" ]] || fail "checkout became dirty: $checkout"
}

verify_enrollment() {
    local alias=$1 output status
    set +e
    output="$(as_deploy ssh -F "$(ssh_config)" -T "git@$alias" 2>&1)"
    status=$?
    set -e
    [[ $status -eq 1 && "$output" == *'successfully authenticated'* ]] || fail "deploy key enrollment failed for $alias"
}

verify_read_only() {
    local checkout=$1 alias=$2 repository=$3
    as_deploy git ls-remote --exit-code "$(alias_origin "$alias" "$repository")" HEAD >/dev/null || fail "cannot read intended repository through $alias"
    if as_deploy git -C "$checkout" push --dry-run "$(alias_origin "$alias" "$repository")" 'HEAD:refs/heads/agelto-read-only-probe' >/dev/null 2>&1; then
        fail "deploy key unexpectedly has push access: $repository"
    fi
}

prepare() {
    [[ $EUID -eq 0 ]] || fail 'prepare must run as root so private keys remain owned by deploy'
    write_ssh_trust
    assert_key_pair "$(key_path agelto)" agelto
    assert_key_pair "$(key_path azguard)" azguard
    assert_ssh_alias github-agelto "$(key_path agelto)"
    assert_ssh_alias github-azguard "$(key_path azguard)"
    pass 'separate GitHub SSH aliases, pinned host trust and 0700/0600 permissions are ready'
    printf '\nEnroll each public key as a read-only deploy key in its intended GitHub repository, then run sync:\n'
    printf '  Agelto (%s): ' "${PROFILE[AGELTO_REPOSITORY]}"; as_deploy ssh-keygen -lf "$(key_path agelto).pub"
    printf '  AzGuard (from %s): ' "$vendor_manifest"; as_deploy ssh-keygen -lf "$(key_path azguard).pub"
    printf '  sudo %q sync --profile %q --enrollment-confirmed\n' "$0" "$profile"
}

sync() {
    [[ $EUID -eq 0 ]] || fail 'sync must run as root and delegates Git operations to deploy'
    "$enrollment_confirmed" || fail 'missing --enrollment-confirmed; enroll both public keys as read-only GitHub deploy keys first'
    assert_key_pair "$(key_path agelto)" agelto
    assert_key_pair "$(key_path azguard)" azguard
    assert_ssh_alias github-agelto "$(key_path agelto)"
    assert_ssh_alias github-azguard "$(key_path azguard)"
    verify_enrollment github-agelto
    verify_enrollment github-azguard

    ensure_clean_checkout "${PROFILE[AGELTO_CHECKOUT_DIR]}" "${PROFILE[AGELTO_REPOSITORY]}" "${PROFILE[AGELTO_CANDIDATE_SHA]}" github-agelto
    local vendor_repository vendor_sha
    local candidate_vendor_manifest="${PROFILE[AGELTO_CHECKOUT_DIR]}/docker/private-vendors.lock.json"
    local candidate_vendor_verifier="${PROFILE[AGELTO_CHECKOUT_DIR]}/docker/scripts/vendor-sync.sh"
    [[ -x "$candidate_vendor_verifier" ]] || fail 'P2 vendor verifier is missing from the candidate source'
    [[ -f "$candidate_vendor_manifest" ]] || fail 'P2 vendor lock manifest is missing from the candidate source'
    readarray -t vendor_values < <(python3 - "$candidate_vendor_manifest" <<'PY'
import json
import re
import sys

value = json.load(open(sys.argv[1], encoding='utf-8'))
repository = value.get('repository', {})
path = repository.get('path')
commit = value.get('commit')
if not isinstance(path, str) or not re.fullmatch(r'[A-Za-z0-9._-]+/[A-Za-z0-9._-]+', path):
    raise SystemExit('P2 vendor manifest has an invalid repository path')
if not isinstance(commit, str) or not re.fullmatch(r'[0-9a-f]{40}', commit):
    raise SystemExit('P2 vendor manifest has an invalid full SHA')
print(path)
print(commit)
PY
)
    vendor_repository=${vendor_values[0]}
    vendor_sha=${vendor_values[1]}
    ensure_clean_checkout "${PROFILE[AGELTO_CHECKOUT_DIR]}/local/vendors/azguard" "$vendor_repository" "$vendor_sha" github-azguard
    as_deploy "$candidate_vendor_verifier" --check
    verify_read_only "${PROFILE[AGELTO_CHECKOUT_DIR]}" github-agelto "${PROFILE[AGELTO_REPOSITORY]}"
    verify_read_only "${PROFILE[AGELTO_CHECKOUT_DIR]}/local/vendors/azguard" github-azguard "$vendor_repository"
    pass "clean detached Agelto ${PROFILE[AGELTO_CANDIDATE_SHA]} and D19 AzGuard $vendor_sha are verified"
}

fixture() {
    [[ $EUID -ne 0 ]] || fail 'fixture mode refuses root execution'
    [[ $fixture_root = /* && $adapter = /* && -x $adapter && -f $adapter && ! -L $adapter ]] || fail 'fixture root and executable adapter are required'
    local tmp_base resolved_root resolved_adapter
    tmp_base="$(realpath -m "${TMPDIR:-/tmp}")"
    resolved_root="$(realpath -m "$fixture_root")"
    resolved_adapter="$(realpath -m "$adapter")"
    [[ "$resolved_root" == "$tmp_base"/* && "$resolved_adapter" == "$tmp_base"/* && ! -L "$fixture_root" ]] || fail 'fixture paths must be non-symlinks below TMPDIR'

    local fixture_ssh="$resolved_root/home/deploy/.ssh"
    local fixture_checkout="$resolved_root/checkouts/agelto"
    local fixture_vendor="$resolved_root/checkouts/azguard"
    install -d -m 0700 "$fixture_ssh" "$resolved_root/checkouts"

    local trust_file="$fixture_ssh/known_hosts"
    printf 'github.com ssh-ed25519 %s\n' "${PROFILE[GITHUB_ED25519_HOST_KEY]}" > "$trust_file"
    chmod 0600 "$trust_file"
    local fingerprint
    fingerprint="$(ssh-keygen -lf "$trust_file" -E sha256 | awk '{print $2}')"
    [[ "$fingerprint" == 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU' ]] || fail 'GitHub Ed25519 host key does not match the verified fingerprint'

    local alias key
    for alias in agelto azguard; do
        key="$fixture_ssh/${alias}_deploy_ed25519"
        if [[ -e "$key" || -e "$key.pub" ]]; then
            [[ -f "$key" && ! -L "$key" && -f "$key.pub" && ! -L "$key.pub" ]] || fail "incomplete fixture key pair: $alias"
            [[ $(stat -c '%a' "$key") == 600 && $(stat -c '%a' "$key.pub") == 644 ]] || fail "unsafe fixture key permissions: $alias"
        else
            "$adapter" keygen "$alias" "$key"
            [[ -s "$key" && -s "$key.pub" ]] || fail "fixture adapter did not create key pair: $alias"
            chmod 0600 "$key"
            chmod 0644 "$key.pub"
        fi
    done

    local config="$fixture_ssh/config"
    cat > "$config" <<EOF
Host github-agelto
    HostName github.com
    HostKeyAlias github.com
    User git
    IdentityFile $fixture_ssh/agelto_deploy_ed25519
    IdentitiesOnly yes
    IdentityAgent none
    ForwardAgent no
    StrictHostKeyChecking yes
    UserKnownHostsFile $trust_file

Host github-azguard
    HostName github.com
    HostKeyAlias github.com
    User git
    IdentityFile $fixture_ssh/azguard_deploy_ed25519
    IdentitiesOnly yes
    IdentityAgent none
    ForwardAgent no
    StrictHostKeyChecking yes
    UserKnownHostsFile $trust_file
EOF
    chmod 0600 "$config"
    "$adapter" validate-alias github-agelto "$config"
    "$adapter" validate-alias github-azguard "$config"
    "$adapter" verify-enrollment github-agelto "$trust_file"
    "$adapter" verify-enrollment github-azguard "$trust_file"

    local vendor_repository vendor_sha
    readarray -t vendor_values < <(python3 - "$vendor_manifest" <<'PY'
import json
import re
import sys

value = json.load(open(sys.argv[1], encoding='utf-8'))
repository = value.get('repository', {}).get('path')
commit = value.get('commit')
if not isinstance(repository, str) or not re.fullmatch(r'[A-Za-z0-9._-]+/[A-Za-z0-9._-]+', repository):
    raise SystemExit('invalid vendor repository')
if not isinstance(commit, str) or not re.fullmatch(r'[0-9a-f]{40}', commit):
    raise SystemExit('invalid vendor SHA')
print(repository)
print(commit)
PY
)
    vendor_repository=${vendor_values[0]}
    vendor_sha=${vendor_values[1]}

    "$adapter" checkout "${PROFILE[AGELTO_REPOSITORY]}" "${PROFILE[AGELTO_CANDIDATE_SHA]}" "$fixture_checkout"
    "$adapter" verify-origin "${PROFILE[AGELTO_REPOSITORY]}" "$fixture_checkout"
    "$adapter" verify-detached "${PROFILE[AGELTO_CANDIDATE_SHA]}" "$fixture_checkout"
    "$adapter" verify-clean "$fixture_checkout"
    "$adapter" verify-read-only "${PROFILE[AGELTO_REPOSITORY]}" "$fixture_checkout"
    "$adapter" checkout "$vendor_repository" "$vendor_sha" "$fixture_vendor"
    "$adapter" verify-origin "$vendor_repository" "$fixture_vendor"
    "$adapter" verify-detached "$vendor_sha" "$fixture_vendor"
    "$adapter" verify-clean "$fixture_vendor"
    "$adapter" verify-read-only "$vendor_repository" "$fixture_vendor"
    pass 'fixture proved separate identities, strict trust, pinned clean origins and read-only probes without network'
}

read_profile
case "$mode" in
    prepare) prepare ;;
    sync) sync ;;
    fixture) fixture ;;
esac
