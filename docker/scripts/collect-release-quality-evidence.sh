#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
evidence_dir=${RELEASE_QUALITY_EVIDENCE_DIR:-"$root_dir/.ci-artifacts/release-quality"}
project_name=${COMPOSE_PROJECT_NAME:-agelto_dev}

cd "$root_dir"
rm -rf "$evidence_dir"
mkdir -p "$evidence_dir"

compose=(docker compose --project-directory "$root_dir" --project-name "$project_name" --env-file "$root_dir/.env" -f "$root_dir/docker-compose.yml")
app=("${compose[@]}" exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app)
app_root=$("${compose[@]}" exec -T app pwd)

run_test() {
    local name=$1
    shift

    local started finished elapsed
    started=$(date +%s%3N)
    if ! "${app[@]}" php artisan test --ci --no-tia --compact --log-junit="storage/app/release-quality/$name.xml" "$@" >"$evidence_dir/$name.txt" 2>&1; then
        cat "$evidence_dir/$name.txt" >&2
        exit 1
    fi
    finished=$(date +%s%3N)
    elapsed=$((finished - started))
    printf '%s\n' "$elapsed" >"$evidence_dir/$name.milliseconds"
}

make test-preflight
"${app[@]}" sh -ec 'rm -rf storage/app/release-quality && mkdir -p storage/app/release-quality'

for run in 1 2 3 4 5; do
    run_test "serial-$run"
done

run_test profile --profile
run_test coverage --coverage-clover=storage/app/release-quality/coverage-clover.xml

for seed in 104729 130363 155921; do
    run_test "random-$seed" --order-by=random --random-order-seed="$seed"
done

for run in 1 2 3; do
    run_test "selected-serial-$run"
done

"${compose[@]}" cp "app:$app_root/storage/app/release-quality/." "$evidence_dir/"
python3 docker/scripts/check-release-quality.py \
    --budget tests/performance-budget.json \
    --evidence "$evidence_dir" \
    --write-summary "$evidence_dir/summary.json"

printf 'Release-quality evidence: %s\n' "$evidence_dir"
