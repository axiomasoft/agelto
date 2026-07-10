---
audience: dev
type: reference
status: draft
---

# База данных

Полная схема — миграции `database/migrations/` (источник истины, не копируется сюда). Этот раздел —
карта таблиц AI-пайплайна и ключевые инварианты, которые не читаются из одной миграции с ходу.

## Таблицы AI-пайплайна (`prc_ai_*`)

| Таблица | Роль |
|:--|:--|
| `prc_documents` | Документ закупки; `procurement_id` **nullable** (D22 — manual-импорт эталонов вне закупки, `ai:dataset:import`); lease-колонки (idempotency guard, P4.1) |
| `prc_ai_analyses` | Сессия AI-анализа (`$analysis`) — шаг (`AnalysisStep`: screening/extraction/discovery), статус, `progress` (checkpoint-requeue, D31), `is_shadow` |
| `prc_ai_llm_calls` | Один физический LLM-вызов (usage, `cachedTokens`, `finishReason`, ссылки на request/response-блобы) |
| `prc_ai_providers` / `prc_ai_routes` | Каталог провайдеров и маршрутов `(document_type, purpose) → model` (роутинг — `llm-providers.md` Д6) |
| `prc_ai_prompts` | Рантайм-копия промтов, синхронизируется из `resources/ai/prompts/**` (`ai:prompts:sync`) — `ai/prompts.md` |
| `prc_ai_corrections` | Field-level коррекции оператора (JSONPath + reason-enum) — обучающий контур |
| `prc_ai_dataset_examples` | Immutable gold-снапшоты (SFT/DPO источник) — `ai/training-loop.md` |
| `prc_ai_eval_runs` / `prc_ai_eval_results` | Прогоны `ai:eval:run` по golden-датасету и их метрики |
| `prc_estimates` / `prc_estimate_items` | Распарсенная смета (`$estimate`) и её позиции (не путать: `prc_items` — позиция объекта закупки/лота) |

## Ключевые инварианты

- **Один активный анализ на версию документа+шаг** (D24): partial unique-индекс
  `prc_ai_analyses_active_version_step_unique` — `(document_version_id, step) WHERE superseded_at IS
  NULL AND is_shadow = false`. Обычный (не partial) `unique(key, version)` — `prc_ai_prompts`, канон
  v1. `prc_estimates` — unique `(analysis_id, ordinal)` (НЕ unique на голый `analysis_id` — один
  анализ порождает N смет, D12).
- **Shadow-анализы** (`is_shadow=true`) — вне активного unique-предиката, не влияют на статусы
  документа; сравнение с локальной LLM (P9.4, `ShadowDispatch`).
- **Re-run/супersede**: после успешного persist нового анализа той же версии документа — старые
  сметы superseded-анализа удаляются в ТОЙ ЖЕ транзакции (`EstimatePersister`), дублей витрины нет;
  история прогона остаётся в `prc_ai_analyses`/`prc_ai_llm_calls`.
- **FK/cascade санация** (D12, аудит F): `prc_procurement_parties.procurement_id` — обычный FK (был
  потерян); иерархия смет и детальных `prc_*` — явный `cascadeOnDelete`, не `RESTRICT` (иначе
  блокирует переразбор/GC).

## Партиционирование и retention (D33, D41, P7.7)

`prc_ai_llm_calls`/`prc_fetches`/`prc_operation_logs`/`prc_events` — партиционированы через
`pg_partman` (`docker/postgres/`), retention-окно задаётся в `part_config` — DROP старых партиций по
config-окну. **Хранится вечно** (явное исключение из агрессивного retention): gold-датасет
(`prc_ai_dataset_examples`), `prc_estimates.raw_result`, метаданные `LlmCall` без блобов,
**`prc_revisions`** (D41 — доменный аудит изменений закупок, не эфемерный лог; `retention_keep_table`
или отдельное длинное окно по согласованию владельца). Блобы (`request_ref`/`response_ref`) в MinIO
— под ILM-правила (transition/expire), не под Postgres-retention.

## Смежное

- `architecture.md` — домены/префиксы.
- ADR [`adr/0001-prc-megadomain.md`](adr/0001-prc-megadomain.md) — почему единый `prc_`-мегадомен.
- Статусы понятным для оператора языком — `docs/04_Operators/reference/statuses.md` (P10.4).
