# P5 acceptance evidence

## Contract covered

`docker/tests/p5.5-proxy-acceptance.sh` is the executable P5.5 carrier. It uses only the isolated
`agelto_test` database, factories and fake HTTP; it never calls a live proxy, Gemini or Playwright
MCP endpoint. The focused matrix proves profile/default/direct selection, configured-source
no-fallback, global and target health, concurrent updates, settings refresh, listing/detail/document
`proxy_source`, Filament authorization and the independent LLM/tooling boundaries.

## Commands

Run `bash docker/tests/p5.5-proxy-acceptance.sh` from the repository root. The carrier performs
Postgres/test-DB preflight, focused tests without TIA, one test-DB rollback/forward migration,
the final full no-TIA suite, Pint, PHPStan, exact boundary searches, Task views/checks, strict
citation verification and `git diff --check`.

## Limitations

The evidence intentionally proves routing with fakes and the project test database. It does not
claim availability of a proxy vendor, Gemini, or the Playwright MCP service; those are excluded from
P5.5 and remain separate runtime/tooling concerns.
