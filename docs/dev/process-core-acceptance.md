---
audience: dev
type: reference
status: living
---

# Process core: acceptance evidence

## P3.16 — platform UI и operational metrics

Этот срез добавляет CLI/Pulse consumers существующих coverage facts и replayable counters.
Новые schema, projector, timeseries engine, speculative UI hooks и external alerts не создаются.
Механизмы следующего среза P3.17–P3.19 остаются отдельными работами; legacy incidents/schema,
switches и dependency baseline здесь не удаляются.

| Test / group | Actual assertions | Owning target |
| --- | --- | --- |
| `T46PlatformMetricsProjectionTest`, t46/p33 | Реальный producer complete-empty, contiguous gap, archive isolation, несколько streams, closed denominator/incomplete, Unknown/no-data/day precision, replay/duplicate/labels, числовой platform cohort → platform code, CLI/Pulse, current counts, due/pause, maintenance/lag | `core-test-observability` |
| `T48PlatformUiRegistryTest`, t48/p33 | Настоящий JSON detail без extension и EIS SQL, EIS fields/escaping, Livewire list 1→20 rows с постоянным query count и без unused extension queries | `core-test-platforms` |
| `PlatformUiBoundaryTest`, t48/p33 | Generic resources без implementation imports; negative source fixtures для import/FQCN/class strings | `core-quality` |
| `T46ProjectionConcurrencyTest`, t46/t20/t18 | Сохранённый корпус реальных PostgreSQL connections/barriers после изменения active-snapshot reader | `core-test-observability` |
| `T17ProcessPipelineE2eTest`, `T48OperatorNavigationE2eTest`, `T31MultiProcessPipelineE2eTest`, p316/e2e | Полный E2E конвейер (listing → detail → download → prepare OCR → screening/extraction/publication → status_refresh → metrics/CLI/Pulse), recovery reconcile, EXR-01/EXR-02 операторская навигация/RBAC/команды и многопроцессное взаимодействие daemons/workers на PostgreSQL и Redis | `core-test-e2e` |

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
