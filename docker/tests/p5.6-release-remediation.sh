#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)

# P5.6 accepts the two P5.5 test paths under its own carrier while preserving
# the proven P5.5 acceptance matrix and test-DB safety boundary.
exec "$root_dir/docker/tests/p5.5-proxy-acceptance.sh"
