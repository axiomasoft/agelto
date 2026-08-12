# P5.6 remediation evidence

P5.6 owns `CardCollectDetailsTest.php` and `ProxyAutoDisableTest.php`, whose current behaviour
was introduced by P5.5 outside its declared Files. `docker/tests/p5.6-release-remediation.sh`
delegates to the existing isolated P5.5 acceptance carrier: it preserves the test-DB guard,
focused proxy matrix, rollback/forward migration proof, full no-TIA suite, Pint, PHPStan and
plan/citation checks. No proxy semantics are changed by this ownership correction.

The paired Swissknifeman source/runtime repair is
`449153a582c2169592458998a3c7302ae4abe26a`; it makes the Task return projection accept the
historical P5 audit-attention reopen and admits only the declared P5 validation carrier.
