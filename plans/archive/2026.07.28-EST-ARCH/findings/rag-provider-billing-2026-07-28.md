# RAG — программный доступ к расходу провайдеров LLM

Слой 1 (`plan-protocol` §16). Дата проверки — **2026-07-28**, движок — Perplexity Web.
Потребитель — фаза P4 (учёт денег и дашборд), решения D10/D11.

Повод: владелец просит «на дашборде показывать **чисто из апи** расход по дням в виде диаграммы»
(`brief/01-refinements.md`, Q6). Нужно знать, что провайдеры реально отдают программно.

---

## Запрос 1 — Gemini API и OpenRouter: есть ли биллинг/usage API

**Вопрос:** «Does Google Gemini API (generativelanguage / AI Studio paid key) offer a programmatic
billing or usage API to fetch daily spend per model in 2026? And does OpenRouter provide
/api/v1/credits and daily activity/usage endpoints?»

**Вердикт (RAG:✅ 2026-07-28):**

| Провайдер | Что доступно программно | Чего НЕТ |
|:--|:--|:--|
| **Google Gemini API** | Мониторинг расхода — только UI: страница Usage в AI Studio и отчёты Cloud Billing (группировка по service/SKU, выбор диапазона дат). Управление: prepay, месячный cap, auto-reload | **Нет** публичного эндпоинта «суточный расход по моделям»; нет JSON-биллинг-API уровня credits/activity; нет per-request cost breakdown из самого Gemini API |
| **OpenRouter** | `GET /api/v1/key` → `limit`, `limit_reset`, `limit_remaining`, `usage`, `usage_daily`, `usage_weekly`, `usage_monthly`, `byok_usage*`, `is_free_tier`. Страница Activity фильтруется по модели/провайдеру/ключу | Полного отчёта «расход по моделям по дням» одним эндпоинтом нет; документированного публичного `/api/v1/credits` в текущих доках не найдено |

Источники: `ai.google.dev/gemini-api/docs/billing` · `openrouter.ai/docs/api_reference/limits` ·
`openrouter.ai/docs/faq`.

## Запрос 2 — Google Cloud Billing: есть ли REST фактического расхода

**Вопрос:** «Is BigQuery billing export the only supported way to get daily cost per SKU for Gemini
API? Is there a Cloud Billing REST API returning actual spend (not pricing)?»

**Вердикт (RAG:✅ 2026-07-28):**

- Программный путь к фактическим затратам — **экспорт Cloud Billing в BigQuery**. Схема экспорта
  несёт service, SKU, project, labels, location, cost, usage, credits, adjustments, currency —
  ровно то, что нужно для «суточной стоимости по SKU Gemini API».
- **REST-API фактического расхода у Google нет.** Cloud Billing Budgets API — про бюджеты и
  уведомления (Pub/Sub), Pricing API — про прайс-листы SKU, а не про понесённые затраты.
- **Лаг данных:** биллинговые данные отстают до суток и более — ни консоль, ни экспорт не являются
  real-time-леджером.
- Практический паттерн, который называет Google: включить экспорт в BigQuery → запрос по дню,
  сервису и SKU → при желании джойн с прайсинг-экспортом для нормализации юнит-экономики.

Источник: `docs.cloud.google.com/billing/docs/how-to/budget-api-overview` (+ биллинг-доки Gemini
из запроса 1).

---

## Следствия для проектирования (потребитель — P4, D10/D11)

1. Формулировка «расход по дням чисто из API» **в лоб не реализуема** на текущем единственном
   активном провайдере (`gemini`, см. `repo-structure-2026-07-28.md` §1). Реализуема на OpenRouter,
   если он появится, и на Gemini — только через включённый владельцем экспорт Cloud Billing в
   BigQuery.
2. Значит, дашборд обязан стоять на **провайдер-агностичном сейме источника расхода**, а не на
   вызове конкретного провайдера: реализация «наш трекинг» (`prc_ai_llm_calls`) есть всегда,
   реализация «биллинг провайдера» подключается по мере доступности и гейтится доступами владельца.
3. Дашборд обязан **явно подписывать источник цифры** («наш трекинг» / «биллинг провайдера,
   лаг ≥24ч»). Молчаливое смешение источников — прямая дорога к повторению инцидента D126
   EST-TRAIN ($2.30 по трекингу против ~$12 по биллингу Google): именно расхождение источников там
   и было симптомом бага.
4. Сверка «наш трекинг ↔ биллинг провайдера» остаётся **процессом с человеком** (лаг, отсутствие
   API, ручная выгрузка), а не автоматическим алертом — оформляется как runbook + команда сверки.
