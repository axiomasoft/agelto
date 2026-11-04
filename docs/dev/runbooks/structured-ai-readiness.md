---
audience: dev
type: runbook
---

# Structured AI: приёмка orchestration и граница quality

P3.14 связывает finance → joint network claim → immutable receipt → validated chunk →
единственную publication. Состояние приёмки V1–V6 фиксирует coordinator на итоговом candidate.
Наличие этого документа или fixture manifest само по себе не означает успешную приёмку.

## Детерминированная проверка

`tests/Fixtures/ProcessCore/Llm/acceptance-v1.json` фиксирует SHA-256 контрактов, prompts,
schema golden, model/HTTP fixtures, затронутой реализации и owning test files.
Корневой `tests/Fixtures/ProcessCore/manifest.json` связывает этот manifest и generation envelope
с provenance и T IDs. `AcceptanceManifestTest` проверяет актуальные bytes и legacy eval inventory.
Это исходные входы проверки; executed results, candidate fingerprint и логи хранятся отдельно
в `storage/app/testing/process-core/reports/<run-id>/`. Commit создаёт coordinator.

| Сценарий | Основное доказательство |
| --- | --- |
| T13 | CanonicalContract / GenerationReceiptDecoder / ExtractionFinalization: schema, decimals, anchors, empty/refusal/unsupported outcomes, bounded reask, split и flat totals depth5 |
| T14 | AssetLifecycle / AssetContinuation: identity dimensions, cached/uncached wire, TTL/404, purpose-specific recovery и консервативная оплата |
| T15/T22 | ExtractionWorkerKill / FinancialRecovery / ProviderRecoveryConcurrency: SIGKILL, exact linkage, restart, два reconciler, periodic blocked scan, storage failure, pause/cancel/head drift, >50 candidates |
| T30 | BudgetLedger: реальные PG contenders, upper-bound cap, hold boundary, in-flight unknown, rollover и explicit replay |
| T35 | ProviderJointSend: actual local HTTP, один joint ID, denial и double-use fencing, TLS, полный permit timeout |
| Связная цепочка | StructuredChainAcceptance: настоящий ExtractionHandler и HTTP, ledger settlement, durable chunk и publication; lost continuation и late put после committed blocked; invalid JSON оплачивается и не публикуется |

Новые checks используют actual Process/financial/network/storage owners. Synthetic provider/account
отвечает на loopback HTTP; Gemini receipt policy настоящая. Generation receipt с известными token
counts conservatively settles весь pinned upper bound: это accounted exposure, а не измеренный
provider invoice. Token usage, charge classification и business validity — отдельные факты.
Отсутствующий usage остаётся unknown даже после conservative settlement. Returned modelVersion
и finishReason сохраняются, если получены как bounded metadata; отсутствующая version не выдумывается.
Response ID и полный raw envelope доступны по protected receipt/hash, без копирования в telemetry.

Проверка outgoing body сверяет JSON MIME, responseJsonSchema, system/user separation, отсутствие
tools и pinned token/thinking options. REST surface повторно сверена 2026-11-03 с
[официальной спецификацией](https://aistudio.google.com/api-reference/generate-content).
Поддержку конкретного account/model, lifecycle, thinking, limits и pricing проверяют отдельно при
provisioning; fixture-model не является разрешением на live send. Explicit cache зависит от API/model:
[официальный caching contract](https://aistudio.google.com/docs/caching) не разрешает его для
Interactions/Gemini 4 Argon и новее. Local wire не доказывает поддержку произвольной live модели.

Для узкой проверки в уже проверенном isolated app:

```bash
php vendor/bin/pest --no-tia tests/Feature/ProcessCore/Llm/StructuredChainAcceptanceTest.php
php vendor/bin/pest --no-tia tests/Feature/ProcessCore/Llm/ReceiptProvenanceTest.php
php vendor/bin/pest --no-tia tests/Feature/ProcessCore/Llm/AcceptanceManifestTest.php
```

Domain suites и полный no-TIA regression выполняет coordinator. Эти новые tests не заменяют
реальные concurrency/fault checks и не утверждают, что финальные T16/load/E2E уже выполнены.
Изменений schema в P3.14 нет; reset/dev DB не требуются для этой локальной правки.

## Качество модели: не измерено

Legacy `estimates/manifest.json` содержит 25 документов, 6 extraction gold и 0 human-verified
записей. `estimates/eval-splits.json` назначает 4 tune и 2 holdout документа; остальные 19
не назначены. У назначенных записей family/cluster не пересекаются между tune и holdout.
Gold files и split связаны hashes, но legacy gold не объявляется canonical structured-output gold.
Original input hashes в inventory переписаны из исходного manifest; все original bytes здесь
не проверялись. Manifest integrity не является human label verification или accuracy report.

Этот корпус не удовлетворяет будущему quality gate: минимум 60 независимых документов,
не менее 10 native spreadsheet / text PDF / scanned PDF, дополнительно multi-estimate/GSFX/archive,
human-reviewed canonical labels и family-separated train/dev/holdout. Нужны actual holdout metrics:
routing precision/recall, required row coverage, duplicates, значимые field F1, decimals/totals,
unresolved fraction, cost/document и p95 latency. Значения метрик и semantic accuracy threshold
не выдумываются. Hard invariants: 0 silently accepted required gaps, 0 duplicate published rows,
100% published numeric fields с provenance и validation.

Paid holdout не запускался. Production cap/risk appetite не утверждены и автоматически не
выбираются; test cap относится только к isolated fixture account. Синхронный paid eval сейчас
явно Unsupported без owning authorization contract. Этот пункт не активирует его скрытым
fallback. Подготовка controlled eval с pinned recipe и explicit spend cap остаётся отдельной
работой owning P3.16–P3.19. До human gold и реального отчёта вывод quality — `not_measured`;
готовность orchestration не означает пригодность AI для автоматического business use.
