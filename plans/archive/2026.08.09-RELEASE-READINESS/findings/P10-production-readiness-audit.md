# P10.4 — production-readiness audit

Дата: 2026-08-13. Аудит read-only относительно product tree: `app`, `config`,
`database`, `routes`, `resources/views`, `bootstrap`, `composer.json` и `composer.lock` не
менялись. Базовый продукт P10.1 — `469acf640fc95944a3accc378445190dbca2a9ba`.

## Method and full coverage manifest

Carrier `artifacts/P10-production-readiness-contract.sh` инвентаризирует каждый вход, а не
выборку: 71 repository migration, 56 моделей и 7 queued job classes; он печатает отсортированные
пути, проверяет schema/migration truth на чистой `agelto_test` и запускает реальные PostgreSQL
concurrency seams. Поиск также покрывает все 35 transaction entry points, 37 create-or-update
entry points, 48 dispatch entry points, 23 after-commit markers, 4 non-config `env()` matches
и 0 `SoftDeletes` matches (команды и counts зафиксированы в carrier).

Тестовая БД определяется из `phpunit.xml`/`tests/bootstrap.php`, имя проходит
`^[a-z0-9_]+_test$`, PostgreSQL готов через `pg_isready`, а clean migration запускалась только с
`APP_ENV=testing`, `DB_HOST=postgres`, `DB_DATABASE=agelto_test`. `migrate:fresh` завершился
успешно: все repository и vendor migrations имеют статус `Ran`.

## Schema, lifecycle and migration truth

- 71 repository migrations применяются cleanly; D36 migration fail-closed запрещает rollback,
  если уже существуют platform-scope status mappings.
- Identity constraints подтверждают важные retry paths: active non-shadow analysis
  `(document_version_id, step)`, archive child `(parent_version_id, archive_entry_path)`, document
  `(procurement_id, external_id)`, one running crawler run, and platform/registry status-mapping
  scopes. `P10.4-R01` ниже — единственный identity constraint без conflict-recovery у writer.
- D31 соблюдён: external storage write происходит до короткой DB transaction; child/event writes
  и `PrepareDocumentJob` dispatch происходят transactionally/after commit. `ArchivePipelineTest`
  доказывает retry и two-worker path.
- D34 соблюдён: `ApplyScreeningDispositionAction` locks document+analysis, пишет decision,
  statuses and event in one transaction, then uses `DB::afterCommit()` for extraction.
- D36 соблюдён: `StatusMappingService` resolves exact registry → platform default → exact unknown;
  unique conflict is re-read and has a real two-worker test.
- No model uses soft deletes. Cascading data loss is therefore reviewed through explicit FK/cascade
  migrations; no production data was mutated by this audit.

## Transactions, races and idempotency

| ID | Severity | Verified chain | Reproduction / exact regression seam | Disposition |
|:--|:--|:--|:--|:--|
| P10.4-R01 | high | `AnalysisStoreRepository::firstOrCreate()` reads an active analysis then calls `create()`; `prc_ai_analyses_active_version_step_unique` prevents a duplicate, but the loser has no `UniqueConstraintViolationException` recovery/re-read. Pipeline unique locks reduce, but do not prove away, a two-worker/retry race. | P10.5: a PostgreSQL `ConcurrentTransactions` feature test starts two workers for one document-version/step and requires two successful returns with one analysis id; recover the named unique violation and re-read the active winner. | **release blocker — fix in P10.5** |
| P10.4-R02 | high | `OpenAiCompatibleProvider::apiKey()` returns `null` when a DB-configured `api_key_env` is absent. That turns an explicitly authenticated provider into an unauthenticated request instead of a fail-closed configuration error; it violates the null+throw config rule and is not covered under config cache. | P10.5: when `api_key_env` is non-empty and neither `NAME_FILE` nor `NAME` resolves a non-empty secret, throw before provider I/O; test cached config and both secret sources. Keep `api_key_env=null` as the explicit local no-auth path. | **release blocker — fix in P10.5** |
| P10.4-T01 | medium | `ProcurementIngestConcurrencyTest` commits from child PHP processes. When that suite precedes `CrawlerRunManagerTest`, its cross-process fixture can share a registry with Crawler cleanup; deleting the registry then violates `prc_procurements_registry_id_foreign`. A clean migration followed by `CrawlerRunManagerTest` alone passes and leaves zero procurements. | P11: run these process-isolated suites in a fresh DB namespace or make every child-process fixture cleanup independent of shared factory identities; add their ordered pair to the test-topology seam. | deferred to P11; product behaviour unaffected |

The remaining state-critical writers have a deterministic proof: procurement ingest uses PostgreSQL
advisory locking and two-worker history tests; crawler runs lock the crawler and recover the unique
winner; archive expansion catches conflict and re-reads the lineage row; proxy health tests cover
concurrent global/target outcomes; leases use a conditional `UPDATE`; job retry ownership is
domain-only (`HasErrorBlock` plus `pipeline:retry-failed`) and guarded against Laravel queue retry
properties.

## Queue and side-effect audit

- All 7 queued jobs were inventoried. Procurement pipeline jobs have no `$tries`, `$backoff`,
  `$maxExceptions`, `retryUntil()` or `failOnTimeout()`; Horizon supervisors use `tries=1`.
- `EstimateExtractionJob` and `CrawlRunJob` deliberately use
  `ShouldBeUniqueUntilProcessing` for checkpoint requeue. The queue timeout chain is tested:
  checkpoint < supervisor timeout < Redis retry-after < stop grace period.
- `PipelineJob` pairs status guard with atomic lease acquisition and always releases in `finally`.
  Direct `handle()` tests are recorded only as local logic proof; the report relies on actual
  PostgreSQL worker seams for delivery/concurrency claims.
- `ShadowAnalysisJob` is explicitly best-effort and outside the primary pipeline. It can cost a
  duplicate shadow call under at-least-once delivery, but it neither changes the primary analysis
  nor dispatches a production state transition; it is not classified as a P10.5 release blocker.

## Config/default/cache audit

- `config:cache` succeeds in the isolated container. Its cached effective values are
  `queue.default=redis` and `settings.cache.enabled=false`.
- The four non-config `env()` matches comprise three secret reads and `Process::env()` in the
  non-production private-vendor Doctor check. `SecretValue` is the single file-or-env secret
  reader, while `OpenAiCompatibleProvider` has the fail-open secret defect P10.4-R02. No
  migration or route path reads environment values directly.
- Spatie settings caching is opt-in and disabled by default. `CrawlerProxySettingsStorageTest`
  proves a resolver refreshes a value written by another process, so the default long-lived worker
  path does not rely on stale in-process settings.

## Executed evidence

- `docker compose exec -T … php artisan migrate:fresh --force --no-interaction` on `agelto_test` — passed.
- `config:clear`, `config:cache`, `config:show queue.default`, `config:show settings.cache.enabled`,
  `config:clear` on `agelto_test` — passed.
- Focused readiness tests: 56 passed / 201 assertions. The combined selector has the documented
  P10.4-T01 failure; after a clean migration, `CrawlerRunManagerTest` alone passes 17 tests / 48
  assertions and leaves 0 cross-process procurements.

## Release-blocker boundary

P10.4 declares two unresolved release blockers: **P10.4-R01** and **P10.4-R02**. P10.5 must not
close R01 by a unit-only or mocked queue test: it needs the exact two-PostgreSQL-worker
conflict/recovery seam. R02 needs a cached-config secret-resolution seam with no provider I/O.
Then P10.5 runs focused and full regression suites. P10.4-T01 is truthful test-topology debt for
P11, not a product mutation in this read-only item.
