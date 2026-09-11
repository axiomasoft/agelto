#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
project=${COMPOSE_PROJECT_NAME:-agelto_dev}
env_file=${ENV_FILE:-.env.example}
compose=(docker compose --project-directory "$root_dir" --project-name "$project" --env-file "$root_dir/$env_file" -f "$root_dir/docker-compose.yml")

wait_for_horizon() {
    for _ in $(seq 1 15); do
        if "${compose[@]}" exec -T horizon php artisan horizon:status 2>/dev/null | grep -qi running; then
            return 0
        fi

        sleep 1
    done

    echo 'Horizon did not become ready after signal recovery' >&2
    return 1
}

cache_state() {
    local key=$1

    "${compose[@]}" exec -T app php artisan tinker --execute="echo cache()->get('${key}', 'missing');" | tr -d '\r\n'
}

wait_for_state_prefix() {
    local key=$1
    local prefix=$2

    for _ in $(seq 1 15); do
        local state
        state=$(cache_state "$key")

        if [[ $state == "$prefix"* ]]; then
            printf '%s\n' "$state"

            return 0
        fi

        sleep 1
    done

    echo "Probe ${key} did not reach ${prefix}*" >&2
    return 1
}

dispatch_probe() {
    local key=$1
    local seconds=$2

    "${compose[@]}" exec -T app php artisan tinker --execute="\\Tests\\Support\\Jobs\\PipelineWorkerSignalProbeJob::dispatch(cacheKey: '${key}', workSeconds: ${seconds})->onConnection('redis')->onQueue('ai_screening');"
}

# This is deliberately an integration contract: it talks to the Redis/Horizon containers rather
# than a mocked queue. The short test profile preserves the same inequalities as production.
"${compose[@]}" ps --status running --services | grep -qx horizon
"${compose[@]}" exec -T app php artisan test --compact --no-tia \
  tests/Unit/Config/QueueTimeoutInvariantTest.php \
  tests/Feature/Procurement/Pipeline/QueueTimeoutFaultInjectionTest.php

"${compose[@]}" exec -T horizon sh -ec "pgrep -o -f 'artisan horizon'" >/dev/null

# SIGTERM is exercised while a real job is active on the real Horizon screening lane. The short
# probe must finish before Docker's ten-second hard-stop window; its cache marker records the
# worker PID and makes a silent restart without job completion fail the contract.
sigterm_probe="p3.5-sigterm-$(date +%s)-$$"
dispatch_probe "$sigterm_probe" 3
sigterm_started=$(wait_for_state_prefix "$sigterm_probe" 'started:')
"${compose[@]}" restart -t 10 horizon
wait_for_horizon
sigterm_finished=$(wait_for_state_prefix "$sigterm_probe" 'finished:')

if [[ $(cache_state "${sigterm_probe}:active") != missing ]]; then
    echo 'SIGTERM probe still holds its active marker after graceful completion' >&2
    exit 1
fi

printf 'SIGTERM probe: %s -> %s\n' "$sigterm_started" "$sigterm_finished"

# SIGKILL is the crash path. The unfinished marker proves the worker did not run a graceful
# finally-path; Redis visibility plus P3.4's durable reconciler, not an in-process retry, own
# recovery. Its deterministic PostgreSQL fault suite is run after the real worker returns.
sigkill_probe="p3.5-sigkill-$(date +%s)-$$"
dispatch_probe "$sigkill_probe" 30
sigkill_started=$(wait_for_state_prefix "$sigkill_probe" 'started:')
"${compose[@]}" kill -s SIGKILL horizon
sigkill_state=$(cache_state "$sigkill_probe")

if [[ $sigkill_state != "$sigkill_started" ]]; then
    echo "SIGKILL probe unexpectedly completed: ${sigkill_state}" >&2
    exit 1
fi

"${compose[@]}" start horizon
wait_for_horizon
printf 'SIGKILL probe: interrupted at %s; durable recovery suite follows\n' "$sigkill_state"
"${compose[@]}" exec -T app php artisan test --compact --no-tia \
  tests/Feature/Procurement/Pipeline/PipelineRecoveryFaultInjectionTest.php

printf 'pipeline worker recovery contract: real SIGTERM slow-success, SIGKILL interruption, durable recovery and no-overlap checks passed\n'
