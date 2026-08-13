#!/usr/bin/env bash
set -Eeuo pipefail

readonly PLAN_ID="2026.08.09-RELEASE-READINESS"
readonly ITEM_ID="P10.1"
readonly BASELINE_HEAD="469acf640fc95944a3accc378445190dbca2a9ba"
readonly PLAN_REL="plans/${PLAN_ID}"

fail() {
    printf 'P10.1 contract: FAIL — %s\n' "$1" >&2
    exit 1
}

expect_eq() {
    local expected="$1"
    local actual="$2"
    local label="$3"

    [[ "$actual" == "$expected" ]] || fail "${label}: expected '${expected}', got '${actual}'"
}

cd "$(git rev-parse --show-toplevel)"

git cat-file -e "${BASELINE_HEAD}^{commit}" || fail "baseline commit is unavailable"

product_status="$(git status --porcelain=v1 -- . ":(exclude)${PLAN_REL}/**" ':(exclude)CLAUDE.md')"
[[ -z "$product_status" ]] || fail "product scope is dirty"
git diff --quiet "$BASELINE_HEAD" -- . ":(exclude)${PLAN_REL}/**" ':(exclude)CLAUDE.md' \
    || fail "product tree differs from baseline"

declare -a upstream_states=(
    "phases/P0/P0.6.state.md"
    "phases/P1/P1.5.state.md"
    "phases/P2/P2.6.state.md"
    "phases/P3/P3.5.state.md"
    "phases/P4/P4.5.state.md"
    "phases/P5/P5.5.state.md"
    "phases/P6/P6.6.state.md"
    "phases/P7/P7.6.state.md"
    "phases/P8/P8.5.state.md"
    "phases/P9/P9.6.state.md"
)

for state in "${upstream_states[@]}"; do
    rg -q '^\*\*Status:\*\* (🟢 Done|🟠 Done with deviations)$' "${PLAN_REL}/${state}" \
        || fail "nonterminal upstream state: ${state}"
done

expected_hashes="$(printf '%s\n' \
    '99c3376c0c92c2cd421667e38cd010c855edddf7496a4091df60e2aff39b945e  composer.json' \
    '2dda5bb380f4f3c2e32c3609370d3c14925827664a39278add90bff0900d714e  composer.lock' \
    'ffaf31a24657dfc33ca65c7951fb10f8d120798b50195fda466f7d5f7da93842  phpstan.neon' \
    'ed4bd788dd7dac3bd0045b4a94c8e5422310dbc303f03d18ef38db23e61cd2e0  phpstan-baseline.neon')"
actual_hashes="$(sha256sum composer.json composer.lock phpstan.neon phpstan-baseline.neon)"
expect_eq "$expected_hashes" "$actual_hashes" "immutable input hashes"

expect_eq "8.4.1" "$(php -r 'echo PHP_VERSION;')" "host PHP version"
expect_eq "8.5.8" "$(docker compose exec -T app php -r 'echo PHP_VERSION;')" "container PHP version"
expect_eq "2.8.3" "$(composer --version --no-ansi | awk '{print $3}')" "Composer version"
expect_eq "1.30.4" "$(./vendor/bin/pint --version | awk '{print $2}')" "Pint version"
expect_eq "2.2.8" "$(./vendor/bin/phpstan --version | awk '{print $NF}')" "PHPStan version"
[[ ! -x ./vendor/bin/rector ]] || fail "Rector unexpectedly exists in baseline"

expect_eq "700" "$(rg --files app -g '*.php' | wc -l | tr -d ' ')" "app PHP files"
expect_eq "54881" "$(rg --files app -g '*.php' -0 | xargs -0 wc -l | tail -n 1 | awk '{print $1}')" "app PHP LOC"
expect_eq "286" "$(rg --files tests -g '*.php' | wc -l | tr -d ' ')" "test PHP files"
expect_eq "71" "$(rg --files database/migrations -g '*.php' | wc -l | tr -d ' ')" "migration PHP files"
expect_eq "7" "$(rg --files tests/Unit/Architecture -g '*.php' | wc -l | tr -d ' ')" "architecture guards"
expect_eq "54" "$(rg -c '^\s*message:' phpstan-baseline.neon)" "baseline entries"
expect_eq "59" "$(rg '^\s*count:' phpstan-baseline.neon | awk '{sum += $2} END {print sum + 0}')" "baseline occurrences"

guard_sha="$(rg --files tests/Unit/Architecture -g '*.php' | LC_ALL=C sort | sha256sum | awk '{print $1}')"
expect_eq "ff0677696f2e17dbaadf46909caed644bc328020016dbe1c6df328b1fb8093f4" "$guard_sha" "architecture guard manifest"

locked_count="$(composer show --locked --format=json | php -r '$d=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo count($d["locked"]);')"
direct_count="$(composer show --locked --direct --format=json | php -r '$d=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo count($d["locked"]);')"
direct_sha="$(composer show --locked --direct --format=json \
    | php -r '$d=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach ($d["locked"] as $p) echo $p["name"], " ", $p["version"], PHP_EOL;' \
    | LC_ALL=C sort | sha256sum | awk '{print $1}')"
expect_eq "204" "$locked_count" "locked dependency count"
expect_eq "30" "$direct_count" "direct dependency count"
expect_eq "0682fc2022bdf0145748a39c4d44b8f1bd97e126e4efd3762d7210ed9cc498ca" "$direct_sha" "direct dependency manifest"

composer validate --strict --no-check-publish --no-ansi >/dev/null
audit_json="$(composer audit --locked --format=json)"
audit_summary="$(printf '%s' "$audit_json" | php -r '$d=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $a=$d["advisories"]??[]; $b=$d["abandoned"]??[]; echo count($a), "|", array_sum(array_map("count", $a)), "|", count($b);')"
expect_eq "0|0|0" "$audit_summary" "Composer audit"

set +e
pint_json="$(./vendor/bin/pint --test --format=json 2>/dev/null)"
pint_exit=$?
set -e
expect_eq "1" "$pint_exit" "Pint dry-run exit"
pint_path_sha="$(printf '%s' "$pint_json" | php -r '$d=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $paths=array_column($d["files"], "path"); sort($paths, SORT_STRING); foreach ($paths as $path) echo $path, PHP_EOL;' | sha256sum | awk '{print $1}')"
expect_eq "3e1eabdc3e424f2b86f8b69fd78de124cf51e2a44006165fa4161af0f272e7d3" "$pint_path_sha" "Pint path inventory"

phpstan_inventory() {
    local mode="$1"
    local output
    local status

    set +e
    if [[ "$mode" == "configured" ]]; then
        output="$(docker compose exec -T -e PAO_DISABLE=true app ./vendor/bin/phpstan analyse \
            --no-progress --error-format=json --memory-limit=1G 2>/dev/null)"
    else
        output="$(docker compose exec -T -e PAO_DISABLE=true app ./vendor/bin/phpstan analyse \
            --configuration=vendor/larastan/larastan/extension.neon --level=max app \
            --no-progress --error-format=json --memory-limit=1G 2>/dev/null)"
    fi
    status=$?
    set -e

    expect_eq "1" "$status" "PHPStan ${mode} exit"
    printf '%s' "$output" | php -r '
        $d=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $ids=[]; $paths=[];
        foreach (($d["files"] ?? []) as $path => $file) {
            foreach (($file["messages"] ?? []) as $message) {
                $id=$message["identifier"] ?? "none";
                $ids[$id]=($ids[$id] ?? 0) + 1;
                $paths[$path]=($paths[$path] ?? 0) + 1;
            }
        }
        ksort($ids); ksort($paths);
        echo $d["totals"]["file_errors"], "|", count($ids), "|", count($paths), "|",
            hash("sha256", json_encode($ids, JSON_UNESCAPED_SLASHES)), "|",
            hash("sha256", json_encode($paths, JSON_UNESCAPED_SLASHES));
    '
}

expect_eq \
    "34|14|13|ac1009a423e3e181296cb890448eadf5f04e9bde3767c28616c7205ce19f60f8|d7b71ab0e7c0dc92aa1613c96380545480b15af4d83037903f0091e3e4cccff0" \
    "$(phpstan_inventory configured)" \
    "configured PHPStan inventory"
expect_eq \
    "1637|41|271|8dda28f6beb37def82e6890798628f11b0610f4601ea1dbc7f4dcbd8b6a9d899|015cfe0f532a3e4e04c19e62c09a52e13507d55a6322adceeafc13e462be6ee1" \
    "$(phpstan_inventory max-no-baseline)" \
    "max/no-baseline PHPStan inventory"

modern_inventory="$(php <<'PHP'
<?php
$paths = [];
exec('rg --files app -g "*.php"', $paths);
$counts = [
    'class' => 0,
    'final_class' => 0,
    'readonly_class' => 0,
    'abstract_class' => 0,
    'non_final_concrete_class_candidate' => 0,
    'interface' => 0,
    'trait' => 0,
    'enum' => 0,
    'constructor' => 0,
    'switch' => 0,
    'match' => 0,
    'arrow_function' => 0,
];

foreach ($paths as $path) {
    $source = file_get_contents($path);
    foreach (preg_split('/\R/', $source) as $line) {
        if (! preg_match('/^\s*((?:(?:abstract|final|readonly)\s+)*)(class|interface|trait|enum)\s+[A-Za-z_]/', $line, $match)) {
            continue;
        }
        $kind = $match[2];
        $counts[$kind]++;
        if ($kind === 'class') {
            $modifiers = preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY);
            $isFinal = in_array('final', $modifiers, true);
            $isReadonly = in_array('readonly', $modifiers, true);
            $isAbstract = in_array('abstract', $modifiers, true);
            $counts['final_class'] += (int) $isFinal;
            $counts['readonly_class'] += (int) $isReadonly;
            $counts['abstract_class'] += (int) $isAbstract;
            $counts['non_final_concrete_class_candidate'] += (int) (! $isFinal && ! $isAbstract);
        }
    }
    $counts['constructor'] += preg_match_all('/\bfunction\s+__construct\s*\(/', $source);
    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }
        $counts['switch'] += (int) ($token[0] === T_SWITCH);
        $counts['match'] += (int) (defined('T_MATCH') && $token[0] === T_MATCH);
        $counts['arrow_function'] += (int) (defined('T_FN') && $token[0] === T_FN);
    }
}

echo implode('|', array_values($counts));
PHP
)"
expect_eq "591|496|269|3|92|21|4|84|275|0|85|438" "$modern_inventory" "modern PHP inventory"

finding="${PLAN_REL}/findings/P10-static-analysis-audit.md"
research="${PLAN_REL}/research/12-P10-execution-design.md"
rg -q 'Verdict: \*\*compatible, recommendation-only for P10\.6' "$finding" || fail "Rector verdict missing"
rg -q 'https://github\.com/rectorphp/rector' "$finding" || fail "official Rector source missing"
rg -q 'https://packagist\.org/packages/driftingly/rector-laravel' "$finding" || fail "official extension metadata missing"
rg -q 'Rector 2\.6\.2 \+ extension 2\.5\.0' "$finding" || fail "exact Rector branch missing"
rg -q 'RAG: Web search/open against official Rector' "$research" || fail "research verification reconciliation missing"

task_root="$(codex plugin marketplace list --json | python3 -c 'import json,sys,pathlib; data=json.load(sys.stdin); roots=[item["root"] for item in data.get("marketplaces", []) if item.get("name")=="swissknifeman" and (pathlib.Path(item["root"])/"packages/task/scripts/plan-lint.py").is_file()]; len(roots)==1 or sys.exit("task runtime unavailable"); print(roots[0]+"/packages/task")')"
python3 "$task_root/scripts/plan-lint.py" "$PLAN_REL"
python3 plans/verify-citations.py --strict "$PLAN_REL"

printf '%s\n' 'P10.1 contract: GREEN — upstream terminal; product/config immutable; Pint 7-path red baseline; PHPStan 34 configured / 1637 max-no-baseline; Composer audit 0/0; Rector branch compatible.'
