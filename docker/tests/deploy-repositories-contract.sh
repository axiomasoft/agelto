#!/usr/bin/env bash

set -euo pipefail

readonly root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly script="$root/docker/scripts/deploy-repositories.sh"
readonly example="$root/docker/host/production-profile.example"

fail() {
    printf '[FAIL] %s\n' "$*" >&2
    exit 1
}

expect_fail() {
    local label=$1
    shift
    if "$@" >/dev/null 2>&1; then
        fail "$label unexpectedly passed"
    fi
}

bash -n "$script"
for literal in 'IdentitiesOnly yes' 'StrictHostKeyChecking yes' 'ForwardAgent no' \
    'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU' '--enrollment-confirmed' \
    'candidate_vendor_verifier' 'as_deploy "$candidate_vendor_verifier" --check'; do
    grep -F -- "$literal" "$script" >/dev/null || fail "missing safety contract: $literal"
done
! rg -n -- 'ssh-keyscan|StrictHostKeyChecking=no|ssh -A|git reset --hard|git clean|token@|eval|\|\| true' "$script" >/dev/null

fixture_root=$(mktemp -d)
trap 'find "$fixture_root" -depth -delete' EXIT
profile="$fixture_root/profile"
cp "$example" "$profile"
sed -i \
    -e "s#^DEPLOY_AUTHORIZED_KEY_FILE=.*#DEPLOY_AUTHORIZED_KEY_FILE=$fixture_root/deploy.pub#" \
    -e "s#^REGISTRY_PASSWORD_FILE=.*#REGISTRY_PASSWORD_FILE=$fixture_root/registry#" \
    -e "s#^BACKUP_ALERT_URL_FILE=.*#BACKUP_ALERT_URL_FILE=$fixture_root/alert#" \
    -e 's#^AGELTO_REPOSITORY=.*#AGELTO_REPOSITORY=axiomasoft/agelto#' \
    -e 's#^AGELTO_CANDIDATE_SHA=.*#AGELTO_CANDIDATE_SHA=0123456789abcdef0123456789abcdef01234567#' \
    -e "s#^AGELTO_CHECKOUT_DIR=.*#AGELTO_CHECKOUT_DIR=/srv/agelto/repository#" \
    -e 's#^GITHUB_ED25519_HOST_KEY=.*#GITHUB_ED25519_HOST_KEY=AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl#' \
    "$profile"
chmod 0600 "$profile"

adapter="$fixture_root/adapter"
cat > "$adapter" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
operation=$1
shift
printf '%s:%s\n' "$operation" "$*" >> "${REPOSITORY_LOG:?}"
case "$operation" in
    keygen)
        identity=$1; key=$2
        printf 'fixture-private-%s\n' "$identity" > "$key"
        printf 'ssh-ed25519 fixture-public-%s\n' "$identity" > "$key.pub"
        ;;
    ssh)
        config=$2
        if [[ "$3" == -G ]]; then
            [[ ${FAIL_OPERATION:-} != validate-alias ]] || exit 41
            alias=$4
            key=$(awk -v host="$alias" '$1 == "Host" { selected=($2 == host) } selected && $1 == "IdentityFile" { print $2 }' "$config")
            printf 'stricthostkeychecking yes\nidentitiesonly yes\nidentityfile %s\nforwardagent no\n' "$key"
        else
            [[ ${FAIL_OPERATION:-} != verify-enrollment ]] || exit 41
            echo 'successfully authenticated'; exit 1
        fi
        ;;
    git)
        repo=''
        if [[ "$1" == -C ]]; then repo=$2; shift 2; fi
        case "$1" in
            clone) mkdir -p "$4/.git"; printf '%s\n' "$3" > "$4/.git/origin" ;;
            remote) printf '%s\n' "$4" > "$repo/.git/origin" ;;
            status) if [[ ${FAIL_OPERATION:-} == verify-clean ]]; then echo ' M dirty'; fi ;;
            config)
                if [[ ${FAIL_OPERATION:-} == verify-origin ]]; then echo 'https://github.com/other/repo.git'; else cat "$repo/.git/origin"; fi ;;
            fetch) ;;
            checkout) printf '%s\n' "$3" > "$repo/.git/HEAD.fixture" ;;
            rev-parse)
                if [[ ${FAIL_OPERATION:-} == verify-detached ]]; then echo '0000000000000000000000000000000000000000'; else cat "$repo/.git/HEAD.fixture"; fi ;;
            symbolic-ref) exit 1 ;;
            ls-remote) echo 'fixture HEAD' ;;
            push) if [[ ${FAIL_OPERATION:-} == verify-read-only ]]; then exit 0; else exit 1; fi ;;
            *) exit 90 ;;
        esac ;;
    *) exit 90 ;;
esac
EOF
chmod 0700 "$adapter"
export REPOSITORY_LOG="$fixture_root/repository.log"
simulation="$fixture_root/simulation"

"$script" fixture --profile "$profile" --fixture-root "$simulation" --adapter "$adapter" >/dev/null
grep -q '^keygen:agelto ' "$REPOSITORY_LOG"
grep -q '^keygen:azguard ' "$REPOSITORY_LOG"
[[ $(stat -c '%a' "$simulation/home/deploy/.ssh/agelto_deploy_ed25519") == 600 ]]
[[ $(stat -c '%a' "$simulation/home/deploy/.ssh/agelto_deploy_ed25519.pub") == 644 ]]
grep -F 'StrictHostKeyChecking yes' "$simulation/home/deploy/.ssh/config" >/dev/null
grep -F 'axiomasoft/agelto' "$REPOSITORY_LOG" >/dev/null
vendor_repository=$(python3 -c 'import json; print(json.load(open("docker/private-vendors.lock.json"))["repositories"][0]["repository"]["path"])')
grep -q '^keygen:proxyma ' "$REPOSITORY_LOG"
grep -F 'axiomasoft/proxyma.git' "$REPOSITORY_LOG" >/dev/null
grep -F 'github-proxyma' "$simulation/home/deploy/.ssh/config" >/dev/null
grep -F "$vendor_repository" "$REPOSITORY_LOG" >/dev/null || fail 'fixture did not reuse the vendor manifest'
first_key_hash=$(sha256sum "$simulation/home/deploy/.ssh/agelto_deploy_ed25519" | cut -d' ' -f1)
"$script" fixture --profile "$profile" --fixture-root "$simulation" --adapter "$adapter" >/dev/null
[[ $(grep -c '^keygen:' "$REPOSITORY_LOG") -eq 3 ]] || fail 'rerun overwrote existing key material'
[[ $(sha256sum "$simulation/home/deploy/.ssh/agelto_deploy_ed25519" | cut -d' ' -f1) == "$first_key_hash" ]]

for operation in validate-alias verify-enrollment verify-origin verify-detached verify-clean verify-read-only; do
    case_root="$fixture_root/fail-$operation"
    export REPOSITORY_LOG="$fixture_root/fail-$operation.log"
    expect_fail "$operation fixture" env FAIL_OPERATION="$operation" REPOSITORY_LOG="$REPOSITORY_LOG" \
        "$script" fixture --profile "$profile" --fixture-root "$case_root" --adapter "$adapter"
done

hostile="$fixture_root/hostile-profile"
cp "$profile" "$hostile"
sed -i 's/^AGELTO_CANDIDATE_SHA=.*/AGELTO_CANDIDATE_SHA=not-a-sha/' "$hostile"
expect_fail 'invalid candidate SHA' "$script" fixture --profile "$hostile" --fixture-root "$fixture_root/bad-sha" --adapter "$adapter"
cp "$profile" "$hostile"
sed -i 's/^GITHUB_ED25519_HOST_KEY=.*/GITHUB_ED25519_HOST_KEY=AAAAC3NzaC1lZDI1NTE5AAAAIFakeWrongKeyMaterial/' "$hostile"
expect_fail 'wrong GitHub fingerprint' "$script" fixture --profile "$hostile" --fixture-root "$fixture_root/bad-host-key" --adapter "$adapter"
cp "$profile" "$hostile"
printf 'AGELTO_REPOSITORY=axiomasoft/agelto\n' >> "$hostile"
expect_fail 'duplicate profile key' "$script" fixture --profile "$hostile" --fixture-root "$fixture_root/duplicate" --adapter "$adapter"
cp "$profile" "$hostile"
printf 'UNKNOWN=value\n' >> "$hostile"
expect_fail 'unknown profile key' "$script" fixture --profile "$hostile" --fixture-root "$fixture_root/unknown" --adapter "$adapter"
chmod 0644 "$profile"
expect_fail 'weak profile permissions' "$script" fixture --profile "$profile" --fixture-root "$fixture_root/weak" --adapter "$adapter"
chmod 0600 "$profile"
rm "$simulation/home/deploy/.ssh/azguard_deploy_ed25519.pub"
expect_fail 'incomplete key pair' "$script" fixture --profile "$profile" --fixture-root "$simulation" --adapter "$adapter"

expect_fail 'sync without enrollment confirmation' "$script" sync --profile "$profile"
! rg -n 'fixture-private-|token@|password=' "$REPOSITORY_LOG" >/dev/null
grep -F 'multiple repositories require separate' "$root/docs/operators/production/repositories.md" >/dev/null

printf 'Deploy repositories contract passed\n'
