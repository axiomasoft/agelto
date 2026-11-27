---
audience: dev
type: reference
status: living
---

# Process core: acceptance evidence

## P3.19 — candidate-bound manifest и передача

Итоговый машинный пакет создаёт `make core-test-report` в
`storage/app/testing/process-core/reports/<run-id>/summary.json`. Команда читает evidence,
сохраняет ошибки и возвращает nonzero при missing/stale/nonpassing scenario. Она не запускает
тесты. `ready_for_owner_review` означает готовность evidence к независимому рассмотрению;
это не разрешение deployment или автоматического бизнес-использования результатов AI.

Кандидат задаётся парой `candidate.head` + `candidate.input_tree_sha256`; `inputs.json`
включает фактические рабочие bytes, в том числе untracked product files. Staged snapshot
не подменяет рабочее дерево. Координатор коммитит финальный кандидат и повторно собирает
report на этом commit. Hash самого будущего commit не встраивается в его собственные файлы.
Старые отчёты сохраняют свои исходные commit/input hashes и не переименовываются в новые.

| Validation | Источник доказательства |
| --- | --- |
| V1 | `core-test-install`: два полных reset; повторно вычисленные schema/seed/catalog hashes, включая partman |
| V2 | `core-test-e2e`: real Horizon/Redis/local HTTP/OCR/fake Gemini и operator navigation/RBAC |
| V3 | `core-test-faults`: exact named cases, JUnit и machine scenario list; same-work crash/recovery |
| V4 | `core-test-load WIDTH=10`: отдельный полный soak и joint admission/FIFO cases |
| V5 | `core-test-load WIDTH=20`: отдельный полный soak и joint admission/FIFO cases |
| V6 | `core-test-regression`: полный no-TIA JUnit, D18 comparison без missing/nonpassing/duplicate cases |
| V7 | `core-quality`: все пять сохранённых subchecks, без baseline/ignore послаблений |
| V8 | Collector: вся матрица T01–T48, exact case-name digests, source/config/fixture/recipe hashes и artifact hashes |
| V9 | Фактическое отсутствие temporary baseline и исполненные permanent exact-role/negative tests из V6 |

Это карта evidence, а не заявление о новом запуске V1–V9 исполнителем P3.19. Latest result
выбирается отдельно по `(command, WIDTH)`; новый red запрещает reuse прежнего green той же
проверки. JUnit сверяется с machine list, проверяются ненулевая выборка/assertions и отсутствие
skip. Case-name/count digests фиксируют фактические dataset cases, включая named fault cases.
`scenario_traceability` перечисляет все исполненные имена для каждого binding, файл, group,
target и source SHA-256. Для прежних T33 architecture files selector — явный case prefix T33;
добавление фиктивного group к старому evidence не требуется.

Полный regression исполняет также owning domain cases; он служит текущим доказательством
T01–T48 вместо механического повторения старых отдельных domain targets с прежними inputs.
Группа/наличие файла сами по себе не дают pass. Карты `requirements` и `accepted_contracts`
связывают R01–R15, принятые D/G/R/SOL/GROK/EXG/EXR контракты с этими assertions.
Исходные нормативные sections и hashes переданы execution-input; исторические альтернативы
Opus не являются вторым каноном. R13 (Planner) и R14 (независимый review) имеют явные границы,
без вымышленного product-test pass. По D51 final review-and-fix — P3.23; P3.20–P3.22 skipped.

Reuse разрешён только после сравнения всех влияющих inputs. Изменения документации и
точно перечисленных report-only файлов записываются отдельно. Для `rig.py` проверяется
известный upstream SHA и неизменный AST всех execution частей за исключением collector.
Для install отдельно проверяется неизменный AST guard/reset/snapshot функций и identities;
изменения app/config/schema/seeds/dependencies не исключаются. Добавление report contract
в fixture manifest допускается только при точном совпадении остальных entries и hash нового
contract. Любой иной runtime/fixture/test input drift требует нового affected evidence.
Это не общий allowlist для изменения suites или ослабления assertions.

Сохранённые coordinator runs P3.18 на input tree
`44b4191e8db6201f85c5fd8d47802b5e2e2ff39adb0eddb350d401d67dcd16a8`:

| Target | Report ID | Измеренный результат |
| --- | --- | --- |
| e2e | `20261103T134605.139292Z-1401266` | 20 cases, exit 0 |
| faults | `20261103T133713.318309Z-1401265` | 126 cases, exit 0 |
| W10 | `20261103T134821.458368Z-1453156` | 1000 docs, 1847.716 s, 6 faults, 3000 sends/receipts |
| W20 | `20261103T142012.931421Z-1479592` | 1000 docs, 1882.592 s, 6 faults, 3000 sends/receipts |
| regression | `20261103T145243.850703Z-1620411` | 3010 cases; 2014 original cases preserved |
| quality | `20261103T151633.427873Z-1774329` | Pint, PHPStan, architecture, schema-docs, schema-tests exit 0 |

Оба soak имеют 0 unaccounted work, 0 FIFO inversions, 0 active reservations/pending outbox
после drain. W10: attempt p50/p95/p99 = 0.107/0.275/0.438 s; W20: 0.127/0.339/0.432 s.
Это синтетический screening workload с настоящими execution/network authorities, а не
throughput измерение модели. Cohort wait p95 около 1015–1031 s отражает намеренный ingress
и bounded admission; эти величины не production SLO. Raw samples/RSS/history и CPU/DB/storage
metadata остаются в `load.json`; declared cap — 256 MiB, post-warmup noise — 8 MiB.

Double install `20261103T122653.884757Z-575707` содержит одинаковые результаты:
schema `95da33090d6e2a502e53eecce6d5eb3b2524021d23389da5724c81e06af0550f`,
seeds `1b39094651daa4bb830620c667cadcdf29918b8a9c463c979fb6567ae85b61eb`,
catalog `a4558afa6919d26ca855e69c2cfdf87a74d720681bc99c72d85172bda853cec9`.
Reuse на новом кандидате всё равно требует машинной проверки inputs; строка в документации
не заменяет эту проверку. Финальный manifest включает hashes всех приложенных output files.

Качество модели, verified gold и controlled paid holdout ограничены отдельно:
[quality limits](runbooks/process-quality-limits.md). Восстановление описано в
[process recovery](runbooks/process-recovery.md), сборка и повтор evidence — в
[isolated rig](runbooks/process-test-rig.md).

## P3.18 — отдельные fault/load gates

`core-test-e2e`, `core-test-faults`, `core-test-load WIDTH=10`, `core-test-load WIDTH=20`,
`core-test-regression` и `core-quality` — шесть разных проверок. Результат faults не является
результатом load/regression. Каждый target сохраняет собственный candidate hash и JUnit.
Полные V1–V6 выполняет coordinator; targeted evidence ниже не заменяет их.

`HorizonPipelineTest` использует настоящий Horizon master/supervisor/workers, Redis
`ExecuteWorkJob`, PostgreSQL admission/finalization и GCRA/FIFO с actual local HTTP.
Corpus создаёт реальные immutable document/version/representation/analysis subjects двух
площадок; тестовый screening возвращает три bounded slices. Он доказывает механизм исполнения,
но не качество AI и не нормализацию сметы. Полный domain workflow остаётся отдельным
`ProcessPipelineE2eTest` с настоящими handlers, OCR и fake Gemini. Его исходный inline
сценарий сохранён; дополнительный сценарий передаёт ту же конфигурацию/immutable disks в
реальные Horizon children и проверяет исполнение всех восьми стадий их PID до normalized
publication и status refresh. Test bootstrap не активируется вне isolated rig.

`HorizonCrashTest` связывает named barrier, worker PID и тот же work ID после SIGKILL:
before-claim, after-lease, before-send, sent-intent-before-wire, after-bytes,
finalize-before-commit, mid-range-checkpoint, commit-before-ack. Отдельный SIGTERM case
сохраняет уже полученный результат. Recovery ждёт настоящий PostgreSQL срок, без UPDATE lease
в прошлое. Test profile: heartbeat 2 s, reserve 15 s; slice/job/Horizon/retry_after/lease =
24/26/28/30/34 s. Production policy не меняется.

Faults target также запускает owning storage, provider joint-send/raw/output kill, late receipt,
Redis loss, stale-owner, journal/sink/projection/retention и network fence/recovery files.
Точный список — `SUITE_PATHS` в harness; JUnit фиксирует исполненные cases, а не только T-номера.
Paid unknown не принимается за бесплатный retry. Внешние reads могут повторяться;
единственная accepted business finalization проверяется отдельно.

`RelayKillTest` убивает настоящий daemon после committed claim и после Redis publish до
mark; новый relay ждёт реальный короткий claim expiry и завершает ту же работу один раз.
`DatabaseDisconnectTest` адресно завершает backend только текущего `agelto_test` внутри
finalization transaction, проверяя rollback journal/result и последующее recovery.
`PaidResponseKillTest` убивает реальный extraction runner после provisional authorization
либо после получения HTTP response до durable raw. Первый случай отзывает старый token и
разрешает known-not-sent retry того же logical request; второй сохраняет reserve и blocked
unknown без нового send. Это отдельные assertions от durable-raw/output recovery upstream.

Load target требует минимум 1000 documents, две площадки, 1800 s для каждой WIDTH и три slices
на work. Старые low-priority работы конкурируют с fresh ingress; два отдельных feeder процесса
соревнуются на PostgreSQL. Во время soak повторяются named SIGKILL, Redis loss и Horizon restart.
`load.json` содержит W/C samples, per-worker/controller RSS, declared caps/noise, stage/cohort
p50/p95/p99, очередь/outbox lag, request/server counts, FIFO grant order, history/resource
accounting и final drain. `load_report.py` отвергает короткую длительность, missing samples,
oversubscription, loss/duplicates, pending reservations/outbox, RSS growth и отсутствие repeated
faults. Его negative fixtures не являются load evidence.

Одиночный/regression запуск load test использует малый corpus того же runtime. Только
`core-test-load` включает полный профиль и валидирует report. Ни такой малый запуск, ни fake
Gemini не подтверждают production throughput, model accuracy, browser automation или deployment.
Финальные results и limitations собирает P3.19 после шести coordinator checks.

Targeted outputs и сводка исполнителя находятся в `storage/app/testing/process-core/p318/`.
Малый joint proof `20261103T131142.401218Z-1150689` измерил 12 документов, 36 requests/server
receipts, 0 FIFO inversions, 0 активных reservations/pending outbox после drain; длительность
79.6 s включает разрешённые durable deferrals и не удовлетворяет soak gate. Этот runtime
фактически сообщает PHP 8.5.8; совместимость с PHP 8.4 этим запуском не измерялась.

Финальные single-file proofs (все exit 0, candidate/input hashes в каждом report):

| Проверка | Cases | Report |
| --- | ---: | --- |
| Domain EIS/OCR/Gemini pipeline, inline и Horizon, recovery | 3 | `20261103T133317.717363Z-1365300` |
| Horizon document subjects / три network slices | 1 | `20261103T132828.850358Z-1324824` |
| Worker SIGKILL/SIGTERM boundaries | 9 | `20261103T132346.948484Z-1275243` |
| Relay claim/publish SIGKILL | 2 | `20261103T131911.961904Z-1214937` |
| PostgreSQL disconnect при finalization | 1 | `20261103T131305.307597Z-1157831` |
| Provider authorization/response до durable raw | 2 | `20261103T131931.344504Z-1220820` |
| Малый joint admission/FIFO load | 1 | `20261103T132046.465057Z-1236629` |
| Docs integrity | 5 | `20261103T133344.103200Z-1368966` |

Это 24 целевых PHP cases; ещё 41 Python harness и 3 load-validator tests, targeted Pint,
PHP syntax, fixture hashes и diff whitespace проверены отдельно. Они не являются pass V1–V6.
Точные команды, source hashes и границы claims — `p318/proof.json`.

## P3.17 — final legacy removal

Единый consumer/schema cutover удаляет source incidents, legacy fetch/operation-log tables,
их модели/factories/writer и старые maintenance commands. Journal URL сохраняет прежние
точные `OperationLogPermission` grants и читает transitions. Process incident badge/filter/
acknowledge проверяются с реальным SQL, operator audit и analyst denial.

| Test | Assertion / evidence boundary |
| --- | --- |
| `LegacyOwnershipAbsenceTest` | Production symbol/config/table scan, negative fixtures каждого forbidden семейства, lawful new health/feature options |
| `PlatformsDomainLayoutTest`, `PlatformsBaselineOriginTest` | Baseline отсутствует; historical pairs закрыты; exact permanent role permissions не расширены |
| `FinalCutoverTest` | Final schema, полный admin sidebar/badges, incident filter/action/audit, canonical journal, zero SQL к deleted tables, runtime schedule/list whitelist |
| `LegacyOutboundClientTest` | Ordinary work без owning context не создаёт operation/demand/request, даже при unknown health; bounded diagnostic/reference owner сохраняется |
| `RawRetentionConcurrencyTest` | Отдельные PG sessions, адресный gate, concurrent raw publish/prune, bounded deletion и сохранение canonical raw evidence |
| `OperationLogResourceTest`, `SourceControlResourceTest`, `AdminRouteInventoryTest` | Существующие URL/RBAC/escaping, отсутствие deleted route, canonical action owner |
| `PartitionCoverageTest`, `PartitionRetentionTest`, `QueueTimeoutInvariantTest` | Сохранённая domain history/partman policy; только Process/default consumers; shutdown grace |

Executor evidence хранится в `storage/app/testing/process-core/p317/` и отдельных rig reports.
Запускаются только single-file proofs, schema-inventory/harness unit checks, syntax/Pint и diff
checks. V1–V4, полный regression и двукратный clean install принадлежат coordinator и здесь
не объявляются выполненными. Нет production deployment, paid model eval или live crawling.

D18: исторические scenario names и SHA provenance сохранены в fixture manifest. Изменённые
имена/закрытие временного baseline имеют exact transfers в `scenario-transfers.json`; тесты
старых incident readers проверяют final absence и canonical sidebar/action. Старое поведение
«health.enabled подавляет explicit diagnose» удалено: explicit passive diagnostics работают,
а execution eligibility принадлежит durable controls. OCR helper tests используют текущие
bounded tool policies и Process slice, без сохранения второй job/lease/queue matrix.

Канонические сырые доказательства консервативно остаются закреплёнными; автоматическое
освобождение этого namespace здесь не реализовано. Браузерный E2E и жизненный цикл Horizon,
полные faults и load и пропускная способность production — более поздние границы приёмки.

## P3.16 — platform UI и operational metrics

Историческое состояние P3.16: последующие срезы P3.17–P3.19 описаны выше.

Этот срез добавляет CLI/Pulse consumers существующих coverage facts и replayable counters.
Новые schema, projector, timeseries engine, speculative UI hooks и external alerts не создаются.
Механизмы следующего среза P3.17–P3.19 остаются отдельными работами; legacy incidents/schema,
switches и dependency baseline здесь не удаляются.

| Test / group | Actual assertions | Owning target |
| --- | --- | --- |
| `PlatformMetricsProjectionTest`, t46/p33 | Реальный producer complete-empty, contiguous gap, archive isolation, несколько streams, closed denominator/incomplete, Unknown/no-data/day precision, replay/duplicate/labels, числовой platform cohort → platform code, CLI/Pulse, current counts, due/pause, maintenance/lag | `core-test-observability` |
| `PlatformUiRegistryTest`, t48/p33 | Настоящий JSON detail без extension и EIS SQL, EIS fields/escaping, Livewire list 1→20 rows с постоянным query count и без unused extension queries | `core-test-platforms` |
| `PlatformUiBoundaryTest`, t48/p33 | Generic resources без implementation imports; negative source fixtures для import/FQCN/class strings | `core-quality` |
| `ProjectionConcurrencyTest`, t46/t20/t18 | Сохранённый корпус реальных PostgreSQL connections/barriers после изменения active-snapshot reader | `core-test-observability` |
| `ProcessPipelineE2eTest`, `OperatorNavigationE2eTest`, `MultiProcessPipelineE2eTest`, p316/e2e | Полный E2E конвейер (listing → detail → download → prepare OCR → screening/extraction/publication → status_refresh → metrics/CLI/Pulse), recovery reconcile, EXR-01/EXR-02 операторская навигация/RBAC/команды и многопроцессное взаимодействие daemons/workers на PostgreSQL и Redis | `core-test-e2e` |

Executor запускает только отдельные test files через `make core-test-file TEST_FILE=...`.
Этот target использует те же identity/readiness guards, общий project lock, останавливает
isolated workers и сохраняет JUnit/executed-scenarios/candidate hashes. Он не заменяет
acceptance targets. `core-test-e2e` также включает отдельные T46 metrics и T48 UI files,
чтобы его assertions проверяли replay/freshness и реального N+1 consumer.
Пустая выборка/skips/нулевые assertions остаются ошибкой.

`covered_through` хранит исходную дату включительно. Секундный lag требует timestamp boundary,
которого в существующих facts нет: `coverage_day_precision` — явная граница доказательства,
не нулевая задержка и не признак outage. При отсутствующих данных ratio/lag/counters
не объявляются здоровыми нулями. Тесты не доказывают полноту содержимого удалённого сервера
или production throughput/SLO. Платные модели и live crawling не вызываются.

Полные V1/V2 запускает coordinator на конечном candidate. Этот документ не является
production deployment approval и не заявляет завершение fault/load/regression группы.

Executor evidence (HEAD `cb15851a7065baad075385a92210600295918e1f`, uncommitted P3.16 changes):

| Single file | Report under `storage/app/testing/process-core/reports/` |
| --- | --- |
| T46 platform metrics: 7 tests / 67 assertions, включая числовой cohort | `20261103T101246.482247Z-3869825` |
| T48 platform UI: 3 tests / 20 assertions | `20261103T074226.840883Z-2591441` |
| UI boundary: 2 tests, включая group-use negative fixture | `20261103T075907.992767Z-2674807` |
| Existing projection concurrency: 19 tests / 196 assertions | `20261103T075003.723064Z-2629343` |
| Existing CrawlerForm consumer | `20261103T075409.427790Z-2651408` |
| Documentation integrity | `20261103T101305.790267Z-3873379` |

Каждый report содержит `result.json`, `inputs.json`, `junit.xml` и `executed-scenarios.json`.
Итоговые targeted Pint/PHPStan/harness checks записываются в
`storage/app/testing/process-core/p316-{pint,phpstan,rig-tests}.log`; передача включает эти файлы.
Manifest регистрирует T46/T48 source hashes и явную границу day-precision, без заявления full-suite pass.

Дополнение e2e harness: сохранённый report `20261103T100528.606560Z-3782417`
содержит exit 0 и 6 executed scenarios. Его `inputs.json` совпадал с кодом e2e и metrics
на момент возобновления executor; последующее уточнение T46 и документации фиксируется
отдельным single-file evidence. Набор выполняет настоящие Redis pop/fire и daemon ticks,
включая отдельные PHP-процессы, HTTP/TLS fixtures и OCR. HTTP/Livewire operator assertions
не являются browser automation; Horizon supervision, kill corpus, нагрузка и production
throughput остаются за границей этого доказательства. Расширенная выборка e2e (6 прежних
сценариев и 10 T46/T48 tests) передаётся coordinator для V1; её общий pass здесь не заявляется.
