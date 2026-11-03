---
audience: dev
type: reference
---

# Извлечение смет

Текущий pipeline использует Process work ledger, immutable recipes и durable chunks.
Screening, extraction и publication исполняются через ExecuteWorkJob; единственный владелец
предметной финализации — WorkFinalizer. Analysis — bigint result/review projection,
AnalysisRun — read model prc_analysis_runs с UUID. Execution state выводится из work,
финансовые показатели — из provider ledger. Отдельных AI lease/retry/progress writers нет.

## Вход, recipe и source coverage

AnalysisFeeder выбирает текущие подготовленные immutable representations независимо от
acquisition, crawler и доступности площадки. Под subject locks он создаёт один run и ready
screening work. Конкурирующие producers не создают два runs. Preparation intent закрывается
при создании конкретного analysis intent. Нет прямого queue dispatch.

AnalysisRecipeFactory до первого provider запроса фиксирует preparation/analyzer version,
TaskManifest со schema/prompt snapshots для screening, discovery и rows, provider/account/API/model,
route/options/thinking policy, currency/locale и coverage/split strategy.
Промпты находятся в resources/ai/prompts/contracts/. PHP Data и schema attributes — канон результата.
Resume читает сохранённый manifest. Изменение semantic policy требует нового run.

ScreeningHandler сначала проверяет representation и сохраняет source manifest через общий finalizer.
Для PDF доказательством покрытия являются исходные страницы и page/line anchors prepare manifest;
число строк сметы не выводится из принятых extraction chunks.
Page discovery покрывает исходные страницы и фиксирует принадлежность сметам. Несколько смет на
странице допустимы. Gaps, исходная неполнота и degradation не исчезают от успешного ответа модели.

## Исполнение

1. ScreeningHandler проверяет pinned structured result и sealed policy. Estimate ведёт в
   discovery/extraction; borderline требует review, known type без implementation сохраняется
   как retain. Скрытой эскалации модели нет.
2. DiscoveryStep и WindowPlanner создают page-based declaration и bounded windows с
   immutable input hash/range/expected coverage до extraction.
3. ExtractionHandler обрабатывает pending subrange. StructuredCallExecutor декодирует только
   successful generation receipt. Canonical schema validation предшествует Data hydration;
   далее проверяются anchors, range, duplicates, decimal arithmetic, totals и completeness.
4. ChunkCommitter в transaction WorkFinalizer сохраняет output ref/hash, canonical rows/provenance,
   coverage и удаление pending range. Mapping/recovery directive consumption входит в тот же commit.
   StagePartial сохраняет envelope и возвращает тот же work в ready.
5. ChunkStitcher объединяет overlap по source anchor + estimate identity, проверяет conflicts/gaps.
   EstimatePublisher проверяет exact required coverage; PublicationRepository в общем finalizer
   записывает normalized estimates/items/resources/sections/flat totals, provenance, result
   projection и current publication pointer.

Деньги и количества — decimal-строки. Float, exponent и JSON number не заменяют decimal-string.
Unknown остаётся null. Flat totals используют node_id,parent_id; проверяются unique IDs, parents,
cycles и арифметика. Depth-five nodes сохраняются целиком.
EstimateAttributes — чистая нормализация без persistence. Model uncertainty хранится в quality.model;
SQL NULL при отсутствии пометки сохраняет точность фильтра marked items.

## Outcomes и split

| Ответ | Durable действие |
| --- | --- |
| Invalid recipe/schema compilation | blocked(invalid_recipe_configuration), до send |
| Invalid JSON/schema/decimal type | один semantic reask, затем blocked(ai_response_invalid) |
| Refusal | blocked(model_refusal), без automatic resend |
| Empty candidates/empty required output | blocked(model_response_incomplete), не EOF |
| Unsupported finish/content | blocked(unsupported_provider_outcome) |
| Anchor/range/coverage/totals conflict | один reask, затем blocked(semantic_review_required) |
| MAX_TOKENS | bounded split, без приёма prefix rows |
| Same chunk key, другой hash | blocked(chunk_conflict), без overwrite |
| Valid output | guarded chunk; publication лишь при exact required coverage |

Semantic ordinal устойчив к redelivery/restart; AI validation не расходует item failure budget.
Reask — отдельный request/reservation. Недостаток budget даёт deferred(provider_budget);
неизвестный paid outcome остаётся blocked(provider_result_unknown).

Диапазоны полуоткрытые [start,end). Split детерминирован, left-before-right; min span, max depth
и max splits закреплены recipe. Pending ranges принадлежат checkpoint одного work, без новых leases.
Kill после split не повторяет parent generation. Exhaustion даёт blocked(extraction_incomplete).
Pages не режутся на части bytes. Empty pending разрешает completion лишь при exact parent coverage.

## Recovery и publication fence

Authorization, joint send claim, immutable raw receipt и accepted chunk — разные durable границы.
До claim recovery отзывает token и доказывает not_sent. После claim без receipt исход unknown:
reserve сохраняется, automatic resend нет. После raw-before-chunk общий RecoveryReconciler
выбирает pinned resume_from_receipt: stored raw обрабатывается без нового send/reserve.

WorkCheckpointEnvelope сохраняет progress и exact recovery linkage. Progress checkpoint не
стирает directive. Chunk, coverage и directive consumption атомарны.
Successful cache/file/lookup receipts восстанавливают metadata/assets, не generation JSON.
Следующий declared purpose получает отдельную identity. Production inline page-text generation
не создаёт cache/file operations неявно.

Finalizer проверяет owner generation/token, document/version heads, analysis generation,
recipe/input, ancestor mapping и admission membership. Истёкший, но не отозванный owner может
сохранить готовый результат; send/checkpoint/heartbeat требуют live lease.
Cancel/takeover/stale recipe/head дают ноль publication. Journal failure откатывает chunks,
coverage, normalized rows, current pointer и recovery consumption вместе.
Partial не подменяет current complete publication. Publication сама не устанавливает verified.

## Provisioning и операторские действия

Runtime использует explicit llm.analysis_profile из config/llm.php. Пустой профиль не угадывает
account/model/pricing и не создаёт paid work. process:sync-analysis с явными period/cap/reason
создаёт budget, handler authorities и provider target catalog; route membership настраивается явно.
См. [LLM providers](../llm-providers.md).

Ручной повтор создаёт новый durable intent/work/run и recipe snapshot; command UUID дедуплицирует
команду. История не сбрасывается. Provider ambiguity, active work и stale source не обходятся.
Review repository принимает лишь operator metadata. Fixture entry point использует общую
canonical working copy/preparation и независимый analysis producer.

Synchronous paid eval, critic, shadow и direct cache prune без owning gate явно Unsupported.
Offline dataset/split planning, dry-run, metrics и human patch review сохранены.
Dry format matrix не выдаёт неизвестную цену за нулевую. Real-model accuracy не заявляется.

## Проверки

T13 проверяет schema/semantic/split/stitch, T15 — настоящий worker SIGKILL,
T22 — recipe/raw recovery/finalization/publication/planning/read-model/UI,
T35 — joint send, local HTTP server count и provider/network facts.
Concurrency использует реальные PostgreSQL connections/barriers.
Acceptance, два isolated clean installs и полный no-TIA regression выполняет coordinator;
paid/live eval и deployment в этот cutover не входят.

P3.14 связывает actual HTTP и финансовый ledger с chunk/publication и receipt-only recovery.
См. [readiness report](../runbooks/structured-ai-readiness.md): deterministic orchestration и
неизмеренная model quality имеют отдельные выводы; legacy eval labels не заменяют human holdout.
