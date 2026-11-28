#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
temp_dir=$(mktemp -d)
trap 'rm -rf "$temp_dir"' EXIT

repo="$temp_dir/repo"
mkdir -p "$repo/docker/scripts" "$temp_dir/bin"
cp "$root_dir/docker/scripts/test-database.sh" "$repo/docker/scripts/test-database.sh"
printf '<env name="DB_DATABASE" value="agelto_test"/>\n' > "$repo/phpunit.xml"
printf 'APP_ENV=testing\n' > "$repo/test.env"

cat > "$temp_dir/bin/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

args=" $* "

if [[ "$args" == *' ps --status running postgres '* ]]; then
    printf 'postgres\n'
elif [[ "$args" == *' ps --status running app '* ]]; then
    printf 'app\n'
elif [[ "$args" == *' pg_isready '* ]]; then
    exit 0
elif [[ "$args" == *'SELECT 1 FROM pg_database'* ]]; then
    printf '1\n'
elif [[ "$args" == *' migrate:status '* ]]; then
    case "${MIGRATION_MODE:-green}" in
        green) printf 'Ran\n' ;;
        pending) printf 'Pending\n' ;;
        failure) exit 42 ;;
    esac
else
    echo "Unexpected docker invocation: $*" >&2
    exit 64
fi
EOF
chmod +x "$temp_dir/bin/docker"

run() {
    PATH="$temp_dir/bin:$PATH" bash "$repo/docker/scripts/test-database.sh" \
        --root "$repo" --env-file test.env --project-name agelto_contract
}

expect_failure() {
    local expected=$1 actual
    shift
    set +e
    "$@" >/dev/null 2>&1
    actual=$?
    set -e
    [[ "$actual" -eq "$expected" ]]
}

bash -n "$root_dir/docker/scripts/test-database.sh" "$root_dir/docker/tests/test-database-contract.sh"
run | grep -Fx 'Test database ready: agelto_test' >/dev/null
expect_failure 68 env MIGRATION_MODE=pending bash -c 'PATH="$1:$PATH" MIGRATION_MODE=pending bash "$2/docker/scripts/test-database.sh" --root "$2" --env-file test.env --project-name agelto_contract' _ "$temp_dir/bin" "$repo"
expect_failure 70 env MIGRATION_MODE=failure bash -c 'PATH="$1:$PATH" MIGRATION_MODE=failure bash "$2/docker/scripts/test-database.sh" --root "$2" --env-file test.env --project-name agelto_contract' _ "$temp_dir/bin" "$repo"

sed -i 's/agelto_test/agelto/' "$repo/phpunit.xml"
expect_failure 65 run

echo 'Test database contract passed'
