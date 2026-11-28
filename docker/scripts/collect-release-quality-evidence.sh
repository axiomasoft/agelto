#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
evidence_dir=${RELEASE_QUALITY_EVIDENCE_DIR:-"$root_dir/.ci-artifacts/release-quality"}
project_name=${COMPOSE_PROJECT_NAME:-agelto_dev}
env_file=${ENV_FILE:-.env}

[[ $# -le 1 ]] || { echo 'Expected at most one release-quality group' >&2; exit 64; }
group=${1:-all}
case "$group" in
    all|serial|profile|coverage|coverage-[1-4]|random|selected) ;;
    *) echo "Unknown release-quality group: $group" >&2; exit 64 ;;
esac

cd "$root_dir"
rm -rf "$evidence_dir"
mkdir -p "$evidence_dir"

if [[ "$project_name" == agelto_core_test ]]; then
    compose=(docker compose --project-directory "$root_dir/docker" --project-name "$project_name" \
        --env-file "$env_file" -f "$root_dir/docker/compose.core-test.yml")
    app=("${compose[@]}" exec -T app)
else
    # Retain the existing local release evidence carrier.
    make test-preflight ENV_FILE="$env_file"
    compose=(docker compose --project-directory "$root_dir" --project-name "$project_name" \
        --env-file "$env_file" -f "$root_dir/docker-compose.yml")
    app=("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app)
fi
app_root=$("${compose[@]}" exec -T app pwd)

run_test() {
    local name=$1
    shift

    local php=(php -d memory_limit=1G)
    if [[ "$name" == coverage || "$name" == coverage-[1-4] ]]; then
        # Coverage retains per-test execution data for the full corpus and also needs
        # bootstrap/proxyma in scope. Exclude dependency and runtime trees explicitly.
        php+=(-d memory_limit=2G -d pcov.enabled=1 -d "pcov.directory=$app_root" \
              -d 'pcov.exclude=~(vendor|storage|node_modules)~')
    fi
    local started finished elapsed
    started=$(date +%s%3N)
    local pest=("${php[@]}" ./vendor/bin/pest --ci --no-tia --compact --log-junit="storage/app/release-quality/$name.xml" "$@")
    if [[ "$project_name" == agelto_core_test ]]; then
        if ! PYTHONPATH="$root_dir/docker/tests/process-core" python3 -B - "$evidence_dir" "$name" "${pest[@]}" <<'PYTHON'
from pathlib import Path
import sys
from rig import bounded_pest

folder, name = Path(sys.argv[1]), sys.argv[2]
sys.exit(bounded_pest(folder, name, sys.argv[3:], folder / (name + ".txt")).returncode)
PYTHON
        then
            cat "$evidence_dir/$name.txt" >&2
            exit 1
        fi
    elif ! "${app[@]}" "${pest[@]}" >"$evidence_dir/$name.txt" 2>&1; then
        cat "$evidence_dir/$name.txt" >&2
        exit 1
    fi
    finished=$(date +%s%3N)
    elapsed=$((finished - started))
    printf '%s\n' "$elapsed" >"$evidence_dir/$name.milliseconds"
}

"${app[@]}" sh -ec 'rm -rf storage/app/release-quality && mkdir -p storage/app/release-quality'

if [[ "$group" == all || "$group" == serial ]]; then
    for run in 1 2 3 4 5; do run_test "serial-$run"; done
fi
if [[ "$group" == all || "$group" == profile ]]; then
    run_test profile --profile
fi
if [[ "$group" == all || "$group" == coverage ]]; then
    run_test coverage --coverage-clover=storage/app/release-quality/coverage-clover.xml
fi
if [[ "$group" == coverage-[1-4] ]]; then
    shard=${group#coverage-}
    run_test "$group" "--shard=$shard/4" "--coverage-php=storage/app/release-quality/$group.php"
fi
if [[ "$group" == all || "$group" == random ]]; then
    for seed in 104729 130363 155921; do
        run_test "random-$seed" --order-by=random --random-order-seed="$seed"
    done
fi
if [[ "$group" == all || "$group" == selected ]]; then
    for run in 1 2 3; do run_test "selected-serial-$run"; done
fi

"${compose[@]}" cp "app:$app_root/storage/app/release-quality/." "$evidence_dir/"
if [[ "$group" == all ]]; then
    python3 docker/scripts/check-release-quality.py \
        --budget tests/performance-budget.json \
        --evidence "$evidence_dir" \
        --write-summary "$evidence_dir/summary.json"
else
    python3 docker/scripts/release-quality-groups.py write "$group" "$evidence_dir"
fi

printf 'Release-quality evidence: %s\n' "$evidence_dir"
