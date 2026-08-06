---
audience: dev
type: runbook
status: draft
---

# Runbook: ошибки Gemini

## Symptoms

`ScreeningJob`/`EstimateExtractionJob` уходят в `Failed`/backoff-ретрай с ошибкой транспорта:
429/503 от `generativelanguage.googleapis.com`, таймауты, `INVALID_ARGUMENT` на `generateContent`,
или региональная недоступность (запросы из РФ).

## Triage

1. `make artisan ARGS="ai:cost-report --days=1"` (`CostReportCommand`) — расход/число вызовов по
   маршруту за последние сутки: аномальный всплеск вызовов может означать retry-шторм, а не
   единичный сбой провайдера.
2. Логи worker'а (`make logs SERVICE=horizon`) — код ошибки Prism/Gemini конкретного вызова.
3. Различить причину:
   - **429/503 (rate limit/перегрузка)** — штатный backoff уже встроен (`config('ai.retry.
     max_attempts')`=5, `max_backoff_minutes`=240, канон v1 `min(2**n, 240)` мин). Троттлинг клиента
     — `config('ai.gemini.rate_limit.*')` (default выключен, `max<=0`, **не откалиброван против
     реальной квоты провайдера** — открытый вопрос плана).
   - **`INVALID_ARGUMENT` на большом файле** — лимит 50 МБ/1000 стр. НА ЗАПРОС (D29); должно быть
     отловлено `ExtractionPreflight` ДО upload — если долетело до провайдера, preflight не сработал
     (расхождение конфигурации `config('ai.capabilities.<model>')` с реальным лимитом — эскалация
     разработчику, не повторный ретрай).
   - **Региональная блокировка/недоступность из РФ** — исходящий прокси `config('ai.gemini.proxy')`
     (env `AI_GEMINI_PROXY`); конкретный боевой прокси НЕ решён (открытый вопрос плана EST-AI).
   - **EOL-модель маршрута** — `pipeline:stuck-check` алертит по приближению `eol_date`
     (`config('ai.model_eol_warn_days')`, default 30 дней); линия 2.5 гаснет 2026-10-16, преемники
     3.x уже прописаны закомментированной строкой рядом в `AiRoutingSeeder`.

## Fix

- Rate limit/перегрузка — дождаться backoff, при системном паттерне — включить/поднять
  `config('ai.gemini.rate_limit.max')`.
- Лимит транспорта долетел до провайдера — чинить `config('ai.capabilities.<model>')` (актуализация
  лимитов), не индивидуальный документ.
- EOL — переключить `llm_routes.is_active` на строку-преемник (уже засеяна) вместо создания новой.

## Rollback

Изменение `config/ai.php` — обычный деплой (`process/deploy.md`), не hot-fix в рантайме;
`llm_routes.is_active`-переключение — точечный UPDATE через Filament `LlmRoutes`-ресурс,
откатывается тем же путём.

## Escalation

Прокси/боевая квота/дата переключения на 3.x — решения владельца, не инженерные (открытые вопросы
плана EST-AI, `plans/archive/2026.07.07-EST-AI/handoff.md`). Систематический `INVALID_ARGUMENT` при живом preflight —
баг в `ExtractionPreflight`/`config('ai.capabilities.*')`, эскалация разработчику.
