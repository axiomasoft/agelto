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
mkdir -p "$secrets_dir"
chmod 700 "$secrets_dir"

random_value() { openssl rand -base64 48 | tr -d '\n' | tr '+/' '-_' | tr -d '='; }
write_new() {
    local name="$1" value="$2" target="$3" temp
    temp="$(mktemp "$secrets_dir/.${name}.XXXXXX")"
    printf '%s\n' "$value" > "$temp"
    chmod 600 "$temp"
    mv -n "$temp" "$target" 2>/dev/null || { rm -f "$temp"; return 0; }
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
    grep -qx "$rotate" "$contract" || { echo "unknown secret name" >&2; exit 2; }
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
