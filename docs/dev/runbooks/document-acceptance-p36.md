---
audience: dev
type: runbook
status: living
---

# P3.6: приёмка документного контура

Download и PDF prepare активированы через `DocumentRuntime`. Общие Process owners выполняют
admission/claim/checkpoint/finalize/recovery; `DocumentFeeder` только создаёт ready work.
Скачивание создаёт отдельный prepare run с required membership, а prepare атомарно сохраняет
representations и pending screening intent. Production AI остаётся `handler_not_activated`.

## Проверяемые инварианты

| Инвариант | Реальные assertions |
| --- | --- |
| Reference → download → prepare → fixture result | `Documents/T10DocumentRuntimeTest.php`: canonical reference, локальный HTTP через network ledger, immutable bytes, настоящий registered PDF handler со stub tools, hash подготовленного representation в screening input, guarded fixture sink; повторная доставка не дублирует result, всего один download request |
| Один analyzer contract после bytes | `Documents/T10DocumentAcceptanceTest.php`: два platform cohorts и manual, acquisition paused и targets retired; один handler читает реальный complete representation, не выбирает ветку по площадке |
| Полное покрытие исходника | Там же: exact anchored text/hash, source count, covered/missing ranges, native/OCR page anchors. `T26PrepareManifestTest.php` и `T26RealToolsTest.php`: restart/split/singleton/truncation/failed required pages/exit0 timeout/реальные mixed PDF tools; incomplete не создаёт complete representation |
| Head и атомарность | `T10DocumentAcceptanceTest.php`: новая source revision после анализа → fenced superseded, ноль fixture publications; отказ mandatory journal → rollback sink/state/slot release, затем одна успешная финализация |
| Ссылки и schema | `T12CanonicalSchemaTest.php`, `T12StoredDocumentWriterTest.php`: обязательные blob/disk/hash/recipe/range, immutable tuples. Acceptance проверяет version/representation/run membership/pending-intent head; download runtime проверяет отдельные run IDs |
| Transport и revalidation | `T03DownloadRevalidationTest.php`: реальные requests, bounded failures без item charge, signed refresh, recurring cycle и fencing. `T12DocumentStorageTest.php`: A→B→A = 3 revisions/2 blobs, current-head CAS, GC/reference race |
| Два feeder и W/C | `Concurrency/T11DocumentFeederConcurrencyTest.php`: отдельные PHP processes/PG connections, W10/W20, parent+child reservations, running C, cohorts/immutable age; `T10DocumentFeederTest.php`: defer/recovery освобождают всю группу |
| Единственные владельцы | `Architecture/DocumentCutoverTest.php`: удалённые writers/jobs/downstream flag и запрет app imports historical harness. `ProcessCoreArchTest.php` и platform boundary tests: направления зависимостей |

Fixture sink — PostgreSQL temporary table, существующая только в PHP test connection. Запись
проходит настоящий `WorkFinalizer` и откатывается вместе с mandatory journal. Это не production
estimate publication, analysis-window/chunk implementation или финансовый/provider protocol.
Test-only stage binding не активирует и не поглощает реальный pending screening intent.

## Оговорка P3.5 и оставшиеся границы

Исторические AI recovery/orphan/retry/manual-rerun assertions сохранены в
`tests/Support/ProcessCore/Historical/AnalysisRecoveryHarness.php`. Они исполняются в полном
no-TIA regression с fake queue/provider и testing guards. Их pass — compatibility evidence
исторической реализации, не подтверждение нового production AI. Отдельные имена и frozen hashes
зафиксированы в `tests/Fixtures/ProcessCore/Documents/p35-semantic-dispositions.json` и
[P3.5 dispositions](document-cutover-p35.md). Сценарии не удаляются и не заменяются общим
`handler_not_activated` assertion. P3.6 не меняет ни эти mappings, ни historical harness.

PDF — текущий typed prepare runtime. Остальные форматы сохраняют deterministic strategy/safety
corpus и явно blocked в runtime; архивная lineage проверяется в canonical storage tests. Их
retained tests не доказывают runtime conversion. Legacy queue/budget declarations для этого
корпуса ещё присутствуют в config, но StageQueueMap document runtime использует только Process
маршруты; отсутствие всех legacy config declarations здесь не заявляется. Stub page semantics
дополняют, а не заменяют real-tool tests; semantic OCR/AI accuracy ими не установлена. JSON/manual cohorts здесь доказывают
независимость обработки после bytes, а не полный source download второй production площадки.

Real AI recipes/schema validation/receipts/billing/windows/chunks/current estimate publication
принадлежат P3.8–P3.14. Fault/load T15/T16 и ≥1000 documents/30 минут принадлежат последующим
owning пунктам; bounded concurrency текущей группы их не подменяет. Production deployment и
paid requests не входят в эту приёмку.

## Команды и evidence

После подготовки стенда выполнить последовательно на неизменном кандидате:

```bash
make core-test-env
make core-test-install
make core-test-documents
make core-test-concurrency
make core-quality
make core-test-regression
```

`core-test-install` выполняет два guarded clean install. Regression запускает весь существующий
PHP corpus без TIA и сверяет original scenarios с их исполняемыми replacements. Не принимать
skip/empty suite или отчёт от другого input tree. Команды используют только isolated Compose
project `agelto_core_test` и его БД `agelto_test`; точный DB guard описан в
[process-test-rig](process-test-rig.md).

Stage report: `storage/app/testing/process-core/p36-stage-report.json`. Он содержит command logs,
result/JUnit/scenario paths, HEAD/dirty/input-tree/fixture fingerprints, изменённые файлы,
историю технических повторов и ограничения. Ignored report не является плановым lifecycle:
регистрацию finish/evidence и commit выполняет координатор.
