#!/usr/bin/env bash
set -euo pipefail
umask 077

root=""
rotate=""
while (($#)); do
    case "$1" in
        --root) root="$2"; shift 2 ;;
        --rotate) rotate="$2"; shift 2 ;;
        *) echo "usage: $0 --root <repository> [--rotate <secret-name>]" >&2; exit 2 ;;
    esac
done
[ -n "$root" ] || { echo "--root is required" >&2; exit 2; }
root="$(cd "$root" && pwd -P)"
secrets_dir="$root/local/secrets"
contract="$root/docker/secrets.contract"
[ -f "$contract" ] || { echo "secret contract is missing" >&2; exit 1; }

if [ -L "$secrets_dir" ] || { [ -e "$secrets_dir" ] && [ ! -d "$secrets_dir" ]; }; then
    echo 'secret root must be a real directory' >&2
    exit 1
fi

mkdir -p -m 700 "$secrets_dir"
chmod 700 "$secrets_dir"

declare -A allowed_names=(
    [app_key]=1
    [db_password]=1
    [redis_password]=1
    [redis_acl]=1
    [minio_root_password]=1
    [minio_app_password]=1
    [admin_initial_password]=1
)
declare -A declared_names=()

while IFS= read -r name; do
    [ -n "$name" ] && [ "${name#\#}" = "$name" ] || continue
    [[ "$name" =~ ^[a-z][a-z0-9_]*$ ]] && [ -n "${allowed_names[$name]:-}" ] || {
        echo 'secret contract contains an unsafe or unknown name' >&2
        exit 2
    }
    [ -z "${declared_names[$name]:-}" ] || {
        echo 'secret contract contains a duplicate name' >&2
        exit 2
    }
    declared_names[$name]=1
done < "$contract"

for name in "${!allowed_names[@]}"; do
    [ -n "${declared_names[$name]:-}" ] || {
        echo 'secret contract is incomplete' >&2
        exit 2
    }
done

random_value() { openssl rand -base64 48 | tr -d '\n' | tr '+/' '-_' | tr -d '='; }
write_new() {
    local name="$1" value="$2" target="$3" temp
    [ -f "$target" ] && [ ! -L "$target" ] && return 0
    [ ! -e "$target" ] && [ ! -L "$target" ] || {
        echo "secret target is not a regular file: $name" >&2
        return 1
    }
    temp="$(mktemp "$secrets_dir/.${name}.XXXXXX")"
    [ -f "$temp" ] && [ ! -L "$temp" ] || { echo 'unable to create a safe temporary secret file' >&2; return 1; }
    printf '%s\n' "$value" > "$temp"
    chmod 600 "$temp"
    if ! mv -n "$temp" "$target" 2>/dev/null; then
        rm -f "$temp"
        return 1
    fi
    [ -f "$target" ] && [ ! -L "$target" ] || { echo "secret target is unsafe: $name" >&2; return 1; }
}
generate() {
    local name="$1" value
    case "$name" in
        app_key) value="base64:$(openssl rand -base64 32 | tr -d '\n')" ;;
        redis_acl) return 0 ;;
        *) value="$(random_value)" ;;
    esac
    write_new "$name" "$value" "$secrets_dir/$name"
}

if [ -n "$rotate" ]; then
    [ -n "${declared_names[$rotate]:-}" ] || { echo "unknown secret name" >&2; exit 2; }
    [ "$rotate" != "redis_acl" ] || { echo "redis_acl is derived" >&2; exit 2; }
    value="$(random_value)"
    target="$secrets_dir/$rotate.next"
    [ ! -e "$target" ] || { echo "staged secret already exists" >&2; exit 1; }
    write_new "$rotate.next" "$value" "$target"
    exit 0
fi

while IFS= read -r name; do
    [ -n "$name" ] && [ "${name#\#}" = "$name" ] || continue
    generate "$name"
done < "$contract"
redis_password="$(cat "$secrets_dir/redis_password")"
redis_hash="$(printf '%s' "$redis_password" | sha256sum | awk '{print $1}')"
write_new redis_acl $'user default on #'"$redis_hash"$' ~* +@all\nuser health on nopass ~* +ping' "$secrets_dir/redis_acl"
