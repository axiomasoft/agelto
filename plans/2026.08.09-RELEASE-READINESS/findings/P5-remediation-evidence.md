# P5.6 remediation evidence

P5.6 owns `CardCollectDetailsTest.php` and `ProxyAutoDisableTest.php`, whose current behaviour
was introduced by P5.5 outside its declared Files. `docker/tests/p5.6-release-remediation.sh`
delegates to the existing isolated P5.5 acceptance carrier: it preserves the test-DB guard,
focused proxy matrix, rollback/forward migration proof, full no-TIA suite, Pint, PHPStan and
plan/citation checks. No proxy semantics are changed by this ownership correction.

The full-suite preflight also exposed a stale `EisSeedersTest` assertion for the removed
`use_proxy` column. P5.6 updates that assertion to the current persisted
`CrawlerProxyMode::Default` contract; the seed behaviour, migration and D8/D28/D29 semantics
are unchanged. The focused seed suite passes 11 tests / 46 assertions on the candidate tree.

The shared P5.5 carrier now recreates its preflight-guarded `*_test` schema after the required
rollback/forward proof and before the full suite. This prevents focused fixture rows from leaking
into seed assertions; it does not touch a development or production database.

`EisSeedersTest` also declares `RefreshDatabase` locally, matching adjacent database feature
tests. The explicit binding keeps its seed assertions isolated when the full suite changes test
class ordering. Every EIS registry assertion now scopes `fz44` by the `eis` platform, matching
the seeder's documented non-global uniqueness contract.

The carrier injects `APP_ENV=testing` and the `agelto_test` database name parsed from
`phpunit.xml` into every app command. `--env=testing` alone did not override the container's
default `DB_DATABASE`; the explicit injection makes the migration target auditable before reset.

The paired Swissknifeman source/runtime repair is
`d2bed1f22f04f7de59fe7e16f1f4d5b76597bdde`; it makes the Task return projection exclude the
historical phase audit-attention reopen and admits only the declared P5 validation carrier.

The final paired Task gate repair is
`c7faf56f951e906363ac61af2b1103e537e51e81`: an accepted AUDIT-CAP decision may directly close
a phase only when its `items` exactly equal that phase's declared item set and its body explicitly
forbids the next audit while directing `task:plan-close`. This admits D76's recorded full-P5
remediation and leaves ordinary item-scoped owner overrides refused.

The release carrier then exposed three external-process fixture leaks and one stale UI assertion
that focused runs could not reveal. The remedies are all test-only: reset the proxy setting from
the same external boundary that writes it; delete the complete crawler/registry/platform fixture
for PostgreSQL concurrency tests; scope the negative status-seeder assertion to its own registry;
and assert the current history section caption. No production behaviour changed.

On the candidate tree, a clean `agelto_test` migration followed by
`php artisan test --no-tia` passed **1464 tests / 6168 assertions** in 79.21 seconds, with one
documented environment-dependent RAR example skipped. The reproducing cross-file subset passed
29 tests / 91 assertions before the final full run.
