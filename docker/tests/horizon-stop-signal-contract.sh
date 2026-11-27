#!/usr/bin/env bash
# Read-only render: no image pull/build, container mutation, or database access.
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
COMPOSE_PROJECT_NAME=agelto_signal_contract IMAGE_TAG=contract-tag \
    docker compose --project-directory "$root_dir" --env-file "$root_dir/.env.example" \
    -f "$root_dir/docker-compose.prod.yml" config --format json | \
    python3 -c 'import json, sys
config = json.load(sys.stdin)
assert config["services"]["horizon"].get("stop_signal") == "SIGTERM", "Horizon must override the FPM image SIGQUIT with SIGTERM"
print("Production Horizon stop-signal contract passed")'
