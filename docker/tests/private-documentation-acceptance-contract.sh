#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

bash "$root_dir/docker/tests/private-documentation-response-contract.sh"
bash "$root_dir/docker/tests/documentation-build-contract.sh"
bash "$root_dir/docker/tests/prod-image-contract.sh" application
bash "$root_dir/docker/tests/clean-install-e2e.sh"
vendor/bin/pint --test config/documentation.php app/Services/Documentation tests/Unit/Documentation tests/Feature/Documentation
DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpstan analyse app/Services/Documentation --no-progress --memory-limit=1G
git -C "$root_dir" diff --check

echo 'Private documentation acceptance contract passed'
