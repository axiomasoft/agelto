# Разведка — контур учёта расхода LLM (деньги и токены)

Слой 1 (`plan-protocol` §16). Дата — **2026-07-29**, метод — построчное чтение репозитория на
`74f515b` (recon-агент + проверка ключевых мест вручную). Потребитель — фаза **P4**.

Дополняет `repo-structure-2026-07-28.md` §8: там учёт описан обзорно, здесь — поимённо те факты,
против которых пишется ТЗ P4.1–P4.4.

---

## §1 Запись вызова: `LlmCallRecorder`

`app/Services/Llm/LlmCallRecorder.php:34-48` — единственный писатель строки вызова:

```php
record(?int $analysisId, LlmPurpose $purpose, string $provider, string $model,
       array $promptVersionSet, ?string $cacheId, string $requestPayload, ?string $responsePayload,
       ?LlmUsage $usage, bool $success, ?string $error, int $durationMs, CarbonImmutable $calledAt): LlmCall
```

Пишет (`:49-67`): `analysis_id`, `purpose`, `provider`, `model`, `prompt_version_set`, `cache_id`,
`request_ref`/`response_ref` (пути в object storage), `prompt_tokens`, `completion_tokens`,
`cached_tokens`, `total_tokens`, `cost_micros`, `success`, `error`, `duration_ms`, `called_at`.
Персистенция — `app/Repositories/Procurement/AiCallStoreRepository.php:19-22`.

- **Цена считается в момент записи** — `costMicros()` `:100-115`: токены × ставки
  `config('ai.capabilities')[$model]`, `ceil`. Модель вне матрицы → `0` молча, без исключения.
  `usage === null` (отказ) → `cost_micros = 0` (`:62`).
- **Ретро-пересчёт при смене прайса невозможен** — ставка в строку вызова не денормализована.
  Для отчёта расхода это ЖЕЛАЕМОЕ поведение (цифра = что реально заплачено тогда), не дефект.
- **Thinking-токены неотделимы**: `PrismGeminiProvider.php:230-246` суммирует
  `usageMetadata.thoughtsTokenCount` в `completionTokens` (Google билует их по output-ставке;
  исправление — коммит `6b87c07`). Отдельного поля в схеме нет и не планируется.
- `promptTokens = max(promptTokenCount − cachedContentTokenCount, 0)` (`:230-234`) — кэш не
  задваивается.

### Call-site'ы `record()` — все несут `analysis_id`

| Файл:строка | `purpose` | Чей `analysis_id` |
|:--|:--|:--|
| `Screening/ScreeningProcessor.php:175` (отказ), `:196` (успех) | `Screening` | анализа скрининга |
| `Extraction/RowRangeChunkSource.php:170,190,211` | `ExtractPassport` `:67` · `ExtractRows` `:94` · `ExtractTotals` `:119` | анализа извлечения |
| `Extraction/WindowedChunkSource.php:240,261` | `Discovery` `:76` · `ExtractPassport` `:113` · `ExtractRows` `:148` · `ExtractTotals` `:179` | анализа извлечения |
| `Jobs/Procurement/ShadowAnalysisJob.php:123,151` | `Shadow` | ТЕНЕВОГО анализа (`is_shadow = true`) |
| `Services/Procurement/Tuning/PromptCritic.php:79,100` | `PromptCritic` | **чужого** анализа — вызов ТЮНИНГА, привязанный к разбираемому анализу |

Enum `app/Enums/Procurement/LlmPurpose.php:10-20`: `screening, discovery, extract_passport,
extract_rows, extract_totals, eval, shadow, prompt_critic`.

> **Ловушка для слепка.** «Сумма вызовов по `analysis_id`» ≠ «стоимость анализа»: под тем же
> `analysis_id` живут вызовы критика промтов (`prompt_critic`), которые тратились на ПРОМТ, а не на
> смету. Прямая сумма завысила бы цену документа. Решение — D39.

## §2 Схема `prc_ai_llm_calls` и retention

`database/migrations/2026_07_04_100026_create_prc_ai_llm_calls_table.php:13-44` (raw SQL):
колонки как в §1; PK `(id, called_at)` `:35`, `PARTITION BY RANGE (called_at)` `:36`; индексы
`(analysis_id)` `:39` и `(purpose, called_at)` `:40`; FK `analysis_id → prc_ai_analyses(id)`
`:41-44`. **Индекса по `(provider, model)` и по одному `called_at` нет** — разрез дашборда
«день × провайдер × модель» пойдёт по partition pruning + фильтр, это надо проверить планом
запроса, а не предполагать.

Retention: `2026_07_04_100047_setup_pg_partman.php:37-62` (регистрация, interval 1 month),
`2026_07_09_100050_set_pg_partman_retention.php:26-35` (`partman.part_config.retention`),
`config/partitioning.php:49-53` — `AI_RETENTION_LLM_CALLS_MONTHS` по умолчанию **12**,
`keep_table = false` → партиции старше года **DROP'аются**. Блобы `ai/llm-calls/` в MinIO —
90 дней (`:99-103`).

> **Следствие:** горизонт любого отчёта, стоящего на `llm_calls`, ограничен retention. Долгий
> горизонт живёт только в слепке на анализе (D5) — анализы не дропаются. Решение — D37.

## §3 Схема `prc_ai_analyses` — что уже есть и чего нет

`database/migrations/2026_07_04_100025_create_prc_ai_analyses_table.php:17-76`:

- **есть**: `provider text NULL` `:30`, `model text NULL` `:31`, `result jsonb`, `progress jsonb`,
  `started_at`/`finished_at`, `parent_analysis_id` `:60-63`, `superseded_at` `:64`, `is_shadow`
  `:65`; partial unique `(document_version_id, step) WHERE superseded_at IS NULL AND is_shadow =
  false` `:72-76`;
- **нет**: колонок токенов и `cost_micros` (их вводит P1.4); relation `llmCalls()` на модели
  `app/Models/Procurement/Ai/Analysis.php` отсутствует — обратная сторона FK не заведена;
- токены прогона сегодня живут только в jsonb: `progress['usage']` и `result['meta']['usage']`
  (`ExtractionOrchestrator::usageToArray():617-625`) — **без денег**.

## §4 Терминальные точки анализа (куда вешать слепок)

`app/Repositories/Procurement/AnalysisStoreRepository.php`: `firstOrCreate():24-37`,
`update(Analysis,$attrs):42-45` — **единственная точка записи, терминальность не инкапсулирована**;
`createRerun():56-65`, `createShadow():75-85`. Методов `markSucceeded/markFailed` НЕТ.

| Место | Что пишет |
|:--|:--|
| `Screening/ScreeningProcessor.php:219-233` | `Succeeded` + `document_type` + `result` + **`provider`/`model`** + `finished_at` |
| `Screening/ScreeningProcessor.php:252-256` | `Skipped` (unprocessable) + `finished_at` |
| `Extraction/ExtractionOrchestrator.php:706-711` | `Succeeded` + `result{estimates,meta.usage}` + `finished_at`; **`provider`/`model` НЕ пишет** |
| `Extraction/ExtractionOrchestrator.php:726-730` | `Skipped` (unprocessable) + `finished_at` |
| `Extraction/ExtractionOrchestrator.php:672-681` | checkpoint: `progress['usage']` в транзакции |
| `Jobs/Procurement/ShadowAnalysisJob.php:139-144` / `:167-176` | `Failed` / `Succeeded` + `provider`/`model` + `finished_at` |
| `Jobs/Procurement/ScreeningJob.php:104` | `Failed` — **без `finished_at`** |
| `Jobs/Procurement/EstimateExtractionJob.php:102` | `Failed` — **без `finished_at`** |
| `Extraction/RerunAnalysisAction.php:44,46` | `superseded_at` на старом + `createRerun()` |

> **Следствие:** «писать слепок при завершении» сегодня означает восемь разных мест, два из
> которых даже не проставляют `finished_at`. Без единого сейма любая пропущенная ветка = молча
> потерянные деньги, которые видно только сверкой с биллингом.

## §5 Скрининг платный, шага два, анализа два

`ScreeningProcessor.php:196-210` пишет вызов с `purpose = screening` — первичный анализ
оплачивается наравне с извлечением (бриф §4). На одну версию документа существуют ДВА активных
анализа: `ScreeningJob.php:138` → `firstOrCreate(step: Screening)`, `EstimateExtractionJob.php:143`
→ `firstOrCreate(step: Extraction)`; `AnalysisStep` — `screening|extraction`. Стоимость документа
= сумма по обоим (+ теневые анализы на той же версии, `is_shadow = true`).

## §6 `ai:cost-report` — что он сегодня

`app/Console/Commands/Ai/CostReportCommand.php`: `ai:cost-report {--days=7}` `:21`, окно
`now()->subDays()` `:27-28`; запрос `:30-42` — `LlmCall::query()` прямо в команде, `select provider,
model, purpose, count(*), sum(prompt_tokens/cached_tokens/completion_tokens/cost_micros)`,
`groupBy(provider, model, purpose)`. **Разреза по ДНЯМ нет**; фильтра по `success` нет (отказы
входят с нулевой ценой — честно). `cacheSavingsMicros():98-109` — `cached × (input−cached)` по
`config('ai.capabilities')`. Курс — статический `config('ai.cost_report.rub_per_usd')` `:50`
(`config/ai.php:184-186`, env `AI_COST_REPORT_RUB_PER_USD`, дефолт 90.0). Вывод — только
`$this->table()` в консоль, файлов/JSON нет.

Тест `tests/Feature/Ai/CostReportTest.php`: группировка и итог `:9-52`, пустое окно `:54-58`,
экономия кэша `:60-82`, `--days` `:84-100`. ₽-конвертация не покрыта.

## §7 Дашборд расхода частично УЖЕ ЕСТЬ — но не в админке

- `laravel/pulse ^1.7`; карточка `app/Pulse/Cards/LlmSpendCard.php:22-44` — **прямой SQL-агрегат**
  по `prc_ai_llm_calls` (`toBase()`), окно жёстко 24ч (`subDay():24`), группировка ТОЛЬКО по
  `purpose` `:35`; курс из `config('ai.cost_report.rub_per_usd')` `:41`.
- View `resources/views/livewire/pulse/llm-spend.blade.php` — таблица, `wire:poll.30s` `:8`,
  итог $ и ₽ `:38-41`. **Диаграммы нет, разреза по дням нет, подписи источника нет.**
- Регистрация `app/Providers/AppServiceProvider.php:41-44`; доступ — `Gate::define('viewPulse')`
  `:46-51`. Живёт на отдельном роуте `config/pulse.php:33` (`/pulse`), **вне Filament-панели**.
- Тест `tests/Feature/Observability/PulseCardsTest.php:39`.
- Специальных Pulse-recorder'ов для LLM нет — карточка читает боевую таблицу напрямую.

> **Следствие:** после переезда `prc_ai_llm_calls` → `llm_calls` (P1.5, D22) карточка ломается.
> В `Files` item'а P1.5 она не названа, но его Validation №4 (`grep -rn "prc_ai_llm_calls" app/`
> — пустой вывод) ловит её по факту. P4 обязан это проверить, а не предполагать.

## §8 Filament — виджетов нет вообще

- `filament/filament ^5.0`, установлено v5.6.8; одна панель `app/Providers/Filament/
  AdminPanelProvider.php` (id `admin`, path `admin`), `discoverWidgets(app_path('Filament/Widgets'))`
  `:46`, `widgets([AccountWidget, FilamentInfoWidget])` `:47-50`, `pages([Dashboard::class])` `:44`.
- **Каталогов `app/Filament/Widgets` и `app/Filament/Pages` не существует** — кастомных виджетов и
  страниц в проекте ноль; дашборд `/admin` — дефолтный с двумя стоковыми виджетами.
- Графики: `vendor/filament/widgets/src/ChartWidget.php` + `LineChartWidget`/`BarChartWidget` и др.
  — идут В СОСТАВЕ Filament 5, новых зависимостей для диаграммы не требуется
  (RAG:— repo-grounded: `ls vendor/filament/widgets/src/`). Пакетов графиков в `composer.json` нет.
- Права панели — `AzGuardPlugin` (`vendor/axioma-studio/azguard-filament/src/AzGuardPlugin.php`),
  enforcement через нативную авторизацию Filament (`boot():73-79`, `config('az-guard-filament.
  enforce'|'source')`). Виджеты плагином явно не упоминаются — механизм доступа к виджету надо
  проверить, а не предполагать.
- Стоимости/LLM-вызовов в `app/Filament/**` сегодня нет вовсе.

## §9 Существующих сущностей биллинга нет

Греп `UsageSource|Billing|BigQuery|spend` по `app/` и `config/` даёт только Pulse-карточку.
Сейма источника расхода, агрегатных таблиц, cost-снимков — нет. Косвенные потребители расхода:
`EvalRunCommand.php:599-620` (читает `LlmCall` по `analysis_id` ради `prompt_version_set`, не ради
денег) и `StorageLifecycleCommand.php:15` (lifecycle блобов).

## §10 Сводка пробелов, релевантных P4

1. `prc_ai_analyses` не несёт ни токенов, ни денег; `provider`/`model` заполняются только на
   успехе скрининга и shadow, у извлечения — не заполняются вовсе (§4).
2. Единой точки завершения анализа нет — восемь мест, два без `finished_at` (§4).
3. Прямая сумма `llm_calls` по `analysis_id` завышает цену документа вызовами тюнинга (§1).
4. Горизонт `llm_calls` — 12 месяцев, дальше партиции дропаются (§2).
5. Расход показан только на `/pulse`, таблицей, за 24ч, без разреза по дням и без подписи
   источника (§7); в админке владельца расхода нет (§8).
6. Разреза «по дням» нет ни в одном существующем потребителе (§6, §7).
