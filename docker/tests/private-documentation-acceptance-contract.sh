#!/usr/bin/env bash

set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

bash "$root_dir/docker/tests/private-documentation-response-contract.sh"
bash "$root_dir/docker/tests/documentation-build-contract.sh"
bash "$root_dir/docker/tests/prod-image-contract.sh" application
bash "$root_dir/docker/tests/clean-install-e2e.sh"
vendor/bin/pint --test config/documentation.php app/Services/Documentation tests/Unit/Documentation tests/Feature/Documentation
vendor/bin/phpstan analyse app/Services/Documentation tests/Unit/Documentation tests/Feature/Documentation --no-progress

task_root=$(codex plugin marketplace list --json | python3 -c 'import json,sys,pathlib; data=json.load(sys.stdin); roots=[item["root"] for item in data.get("marketplaces", []) if item.get("name")=="swissknifeman" and (pathlib.Path(item["root"])/"packages/task/lib/task").is_dir()]; len(roots)==1 or sys.exit("task runtime unavailable: expected one marketplace root"); print(roots[0]+"/packages/task")')
python3 "$task_root/scripts/plan-lint.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/scripts/plan-views.py" state --all --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/scripts/plan-views.py" decisions --all --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/scripts/plan-views.py" status --all --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$task_root/scripts/plan-views.py" bundle --all --check --plan-dir "$root_dir/plans/2026.08.09-RELEASE-READINESS"
python3 "$root_dir/plans/verify-citations.py" "$root_dir/plans/2026.08.09-RELEASE-READINESS" --strict
git -C "$root_dir" diff --check

echo 'Private documentation acceptance contract passed'
