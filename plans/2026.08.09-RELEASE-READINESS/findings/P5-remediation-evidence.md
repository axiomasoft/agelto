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
class ordering.

The carrier injects `APP_ENV=testing` and the `agelto_test` database name parsed from
`phpunit.xml` into every app command. `--env=testing` alone did not override the container's
default `DB_DATABASE`; the explicit injection makes the migration target auditable before reset.

The paired Swissknifeman source/runtime repair is
`d2bed1f22f04f7de59fe7e16f1f4d5b76597bdde`; it makes the Task return projection exclude the
historical phase audit-attention reopen and admits only the declared P5 validation carrier.
