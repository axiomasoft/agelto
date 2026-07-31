# Структурный разбор AI-контура — факты репозитория

Слой 1 (`plan-protocol` §16). Все факты сверены чтением кода и миграций на `74f515b`
(ветка `main`, 2026-07-28) — это НЕ проектные предположения. Маркер каждого пункта —
`RAG:— (repo-grounded: <файл:строка>)`.

Разбор сделан по прямому поручению владельца («проанализировать всю структуру проекта… всё надо
по полной предусмотреть», `brief/00-brief.md`). Потребители — все семь фаз плана.

---

## 0. Что УЖЕ есть (переиспользовать, а не строить заново)

| Требование владельца | Состояние | Носитель |
|:--|:--|:--|
| Провайдер/модель/токены/деньги на КАЖДЫЙ вызов, включая первичный анализ (бриф §4) | **Есть** | `prc_ai_llm_calls`: `provider`, `model`, `purpose`, `prompt_tokens`, `completion_tokens`, `cached_tokens`, `total_tokens`, `cost_micros`, `duration_ms`, `success`, `analysis_id`, `prompt_version_set` |
| Финальный объединённый промт каждого запроса (бриф §5) | **Данные есть, экрана нет** | `LlmCallRecorder::storeRaw()` кладёт полный payload (`systemPart` + `userParts`) на raw-диск; ссылка — `prc_ai_llm_calls.request_ref`/`response_ref` |
| Ось «тип документа» в анализе (бриф §6) | **Частично** | `prc_ai_analyses.document_type` (`text`, nullable, без FK); enum `AiDocumentType` с пятью кейсами |
| Очередь и порядок «скрининг → извлечение» (бриф §2) | **Есть** | `PipelineJob`, `PrepareDocumentJob`, `ScreeningJob`, `EstimateExtractionJob`, `PipelineDispatcher`, Horizon |
| Отчёт по стоимости (бриф §3) | **Команда есть, экрана нет** | `ai:cost-report` |
| Filament по AI | **Частично** | `Ai/{Analyses,Prompts,AiRoutes,Providers,DatasetExamples}` |
| Ось типа документа в очереди предложений (бриф §9) | **Уже есть** | `prc_ai_prompt_suggestions.document_type` (заведена P7.4 EST-TRAIN); не хватает только расширения оси «тип подсказки» |

---

## 1. Провайдеры: активен ровно один

`AiRoutingSeeder` заводит единственного провайдера `code = 'gemini'`, `driver = 'gemini'`,
`base_url = null`, все маршруты — на него
(RAG:— repo-grounded: `database/seeders/AiRoutingSeeder.php:41-49`). Адаптеров в коде два —
`PrismGeminiProvider` и `OpenAiCompatibleProvider`
(RAG:— repo-grounded: `app/Services/Llm/Providers/`), но второй ни одним маршрутом не выбран.

Следствие для дашборда расхода: сегодня «биллинг провайдера» = биллинг Google, у которого REST
фактического расхода нет вовсе (`rag-provider-billing-2026-07-28.md`).

## 2. Точка переключения по типу документа существует ровно одна — и она захардкожена

`ScreeningOutcomeHandler::branchByType()`
(RAG:— repo-grounded: `app/Services/Procurement/Screening/ScreeningOutcomeHandler.php:115-122`):

```php
match ($documentType) {
    AiDocumentType::Estimate      => $this->markScreened($document, $analysis, dispatchExtraction: true),
    AiDocumentType::Unknown, null => $this->ignore($document, $analysis, reason: 'unknown'),
    default                       => $this->markScreened($document, $analysis, dispatchExtraction: false),
};
```

и следом жёсткий диспатч `EstimateExtractionJob::dispatch($document->id)` (`:131`). Это и есть
искомый «переключатель по типу документа» — но он не расширяем без правки ядра: второй тип =
правка `match` + новый Job + новая ветка в `markScreened`. Плюс `storeEvent(… outcome: 'estimate')`
(RAG:— repo-grounded: `ScreeningOutcomeHandler.php:128`) кодирует тип магической строкой прямо
в событие.

## 3. НАХОДКА СВЕРХ ЧЕРНОВИКА: мёртвый реестр `prc_document_types`

Таблица `prc_document_types` существует с первого дня схемы — колонки `code`, `name`,
**`has_extractor`**, `is_active`
(RAG:— repo-grounded: `database/migrations/2026_07_04_100003_create_prc_document_types_table.php:14-21`),
есть модель `App\Models\Procurement\Platform\DocumentType`
(RAG:— repo-grounded: `app/Models/Procurement/Platform/DocumentType.php:20`) и фабрика.

**Она не используется нигде**: ни одного сидера (в `database/seeders/` только `AiRouting`,
`Prompt`, `StatusMapping`, `DatabaseSeeder`), ни одного call-site модели в `app/`, `has_extractor`
не читается ничем, кроме собственной фабрики
(RAG:— repo-grounded: `grep -rn "has_extractor" app/ database/` → только модель, миграция, фабрика).

При этом три таблицы ссылаются на неё **комментарием, а не внешним ключом**: `prc_ai_analyses`,
`prc_ai_routes`, `prc_ai_corrections` несут `document_type text` с комментарием
«code из `prc_document_types`, без строгого FK»
(RAG:— repo-grounded: `2026_07_04_100025:27`, `2026_07_04_100028:18`, `2026_07_09_100052:31`).

Итого у «типа документа» **два конкурирующих носителя** — enum `AiDocumentType` (по которому
код реально ветвится) и мёртвая таблица-реестр (на которую ссылается схема). Ни один не является
источником истины; `has_extractor` — ровно тот флаг «у типа есть извлечение», который
`ScreeningOutcomeHandler` захардкодил у себя. Это первая развилка фазы P1
(см. `## Обсуждение` плана, развилка 1).

## 4. Тип документа ездит СТРОКОЙ и захардкожен литералом в семи местах

`AiRouteResolver::resolve(string $documentType, LlmPurpose $purpose)`, `candidatesFor()`,
`shadowRoute()` — параметр `string`, не `AiDocumentType`
(RAG:— repo-grounded: `app/Services/Llm/AiRouteResolver.php:48,65,77`).

Литерал `'estimate'` (RAG:— repo-grounded, `grep -rn "'estimate'" app/`):

- `app/Services/Procurement/Extraction/ExtractionOrchestrator.php:114`, `:312`
- `app/Services/Procurement/Extraction/RerunRangeAction.php:61`
- `app/Console/Commands/Ai/EvalRunCommand.php:145`, `:278`
- `app/Services/Procurement/Bench/FormatBenchRunner.php:93`, `:259`

Плюс спец-значение `'any'` (маршрут критика) — зарезервированная строка прямо в комментарии
миграции маршрутов, без носителя в типах
(RAG:— repo-grounded: `2026_07_04_100028_create_prc_ai_routes_table.php:18`).
`prc_ai_routes.document_type` — `text` без FK и без enum-ограничения.

Это ровно «привязка к абстрактным значениям» из брифа §6: опечатка в литерале даёт молчаливый
фолбэк-маршрут, а не ошибку.

## 5. Enum'ы шагов и назначений смешивают инфраструктуру с предметной областью

| Enum | Кросс-документное | Сметно-специфичное |
|:--|:--|:--|
| `PromptStep` | `Global`, `PromptCritic`, `ScreeningGlobal`, `ScreeningClassify` | `ExtractionGlobal`, `ExtractionDiscovery`, `ExtractionPassport`, `ExtractionRows`, `ExtractionTotals` |
| `LlmPurpose` | `Screening`, `Eval`, `Shadow`, `PromptCritic` | `Discovery`, `ExtractPassport`, `ExtractRows`, `ExtractTotals` |
| `AnalysisStep` | `Screening`, `Extraction` | — (ось общая и правильная) |

(RAG:— repo-grounded: `app/Enums/Procurement/{PromptStep,LlmPurpose,AnalysisStep}.php`)

Второй тип документа со своим набором шагов (ТЗ: разделы → требования → сроки) заставит либо
раздувать общие enum'ы сметно-чужими кейсами, либо заводить второй носитель шагов. Каждый кейс —
это ещё и строка маршрута в `AiRoutingSeeder`. Сегодня разделение держится ТОЛЬКО соглашением о
префиксе в строке (`extraction:rows`) — машинно не проверяемым.

## 6. Слой `Extraction` смешивает машинерию и предметку

`app/Services/Procurement/Extraction/` (RAG:— repo-grounded: `ls` каталога):

- **Переиспользуемое любым типом документа:** `ChunkSource`, `WindowedChunkSource`,
  `RowRangeChunkSource`, `ExtractionOrchestrator`, `ExtractionPreflight`, `ExtractionSession`,
  `SegmentAssembler`, `StitchReport`.
- **Сметное:** `EstimatePersister`, `EstimateStitcher`, `EstimateValidator`,
  `EstimateToContractMapper`, `EstimateDiffReport`, `EvalMetrics`, `GoldExampleRecorder`,
  `ValidationReport`, `RerunAnalysisAction`, `RerunRangeAction`.

Второй тип документа переиспользует первую половину и обязан подставить свою вторую — границы
между половинами нет ни в папках, ни в интерфейсах.

## 7. Гибкость БД: где второй тип упрётся

| Таблица | Проблема под второй тип |
|:--|:--|
| `prc_ai_eval_runs` | нет ни `document_type`, ни `task` — прогон неявно сметный; сравнение прогонов разных типов невозможно |
| `prc_ai_fixtures` | сметно-специфичные `form`, `class`, `expected_estimates`, `expected_rows`, `gold_source` рядом с общими `code`/`sha256`/`storage_path`; поле называется `doc_type`, тогда как в `prc_ai_analyses`/`prc_ai_routes` — `document_type` |
| `prc_ai_prompts` | нет оси типа документа: разделение только соглашением о префиксе в `key`/`step` |
| `prc_ai_dataset_examples` | ось `task` (`extraction`/`screening`) есть, оси типа документа нет |
| `prc_estimates` + `prc_estimate_{sections,items,resources,totals,work_types}` | пять таблиц результата, привязка `analysis_id`; общий сейм «анализ → результат» существует только как `prc_ai_analyses.result` (jsonb) |
| `prc_ai_analyses`, `prc_estimates` | **нет ни токенов, ни денег** — см. §8 |
| `prc_ai_prompt_suggestions` | `document_type` уже есть; `kind` — только `prompt_patch`/`correction_hint`, ось «тип подсказки» из брифа §9 шире |
| `prc_document_types` | мёртвый реестр — см. §3 |

## 8. Учёт денег не переживает ротацию — прямое противоречие брифа §4

`prc_ai_llm_calls` партиционирована `PARTITION BY RANGE (called_at)` с составным PK
`(id, called_at)` и чистится `ai:storage:lifecycle`. `prc_ai_analyses` несёт `provider` и `model`,
но **не несёт ни токенов, ни `cost_micros`**
(RAG:— repo-grounded: `2026_07_04_100025_create_prc_ai_analyses_table.php:30-31` — есть
`provider`/`model`, полей расхода в файле нет); `prc_estimates` не несёт ничего из этого.
Стоимость исторической сметы считается только джойном к партиционированной таблице и исчезает
вместе с партицией.

Побочный факт из EST-TRAIN: D126 — `PrismGeminiProvider` не считал `thoughtsTokenCount`, весь
боевой пайплайн под `gemini-3.x` занижал расход; владелец обнаружил расхождение $2.30 по трекингу
против ~$12 по биллингу Google. Баг исправлен, но **сверка трекинга с биллингом как процесс не
заведена** — а бриф §3 просит именно «статистику по балансу».

## 9. Именование: где имена врут или расходятся

- `AiCallStoreRepository` ↔ модель `LlmCall` ↔ таблица `prc_ai_llm_calls` — **три разных имени
  одной сущности**.
- `prc_ai_fixtures.doc_type` ↔ `prc_ai_analyses.document_type` ↔ `prc_ai_routes.document_type` —
  два имени одного факта.
- `EstimateType::Estimate = 'estimate'` и `AiDocumentType::Estimate = 'estimate'` — **два разных
  смысла под одним литералом** (вид сметного документа vs тип документа закупки)
  (RAG:— repo-grounded: `app/Enums/Procurement/EstimateType.php:16`,
  `app/Enums/Procurement/AiDocumentType.php:17`).
- `ai:eval:run` обещает общее, а внутри — `resolve('estimate', LlmPurpose::ExtractRows)`
  (`EvalRunCommand.php:145`): команда сметная, имя — нет. То же у `ai:bench:formats`,
  `ai:dataset:*`, `ai:gold:*`, `ai:fixtures:*`.
- `app/Dto/Procurement/Ai/{Bench,Diff,Estimate,Gold,Gsfx,Screening,Tuning,Fixture}` — на одном
  уровне лежат предметная область (`Estimate`), формат-источник (`Gsfx`), стадия (`Screening`),
  инструмент (`Bench`, `Diff`, `Tuning`) и артефакт (`Gold`, `Fixture`). Оси группировки нет.
- Зафиксированная в глоссарии ловушка: `Estimate\Item` (`prc_estimate_items`, позиция сметы)
  vs `PurchaseItem` (`prc_items`, позиция объекта закупки).

## 10. Команды: 18 штук плоско, домены смешаны

`app/Console/Commands/Ai/` — один каталог без подпапок, 18 файлов
(RAG:— repo-grounded: `ls app/Console/Commands/Ai/`):

- **Кросс-документные** (остаются `ai:*`): `ai:prompts:sync`, `ai:prompts:suggest`,
  `ai:prompts:apply`, `ai:caches`, `ai:cost-report`, `ai:storage:lifecycle`, `ai:tools:doctor`.
- **Сметные** (уезжают в `estimate:*`): `ai:gold:build`, `ai:gold:import`, `ai:eval:run`,
  `ai:eval:diff`, `ai:eval:compare`, `ai:fixtures:import`, `ai:fixtures:manifest`,
  `ai:fixtures:verify`, `ai:bench:formats`, `ai:dataset:import`, `ai:dataset:export`.

Логика живёт В командах: `EvalRunCommand`, `EvalDiffCommand` (234 строки), `PromptsSuggestCommand`
(233 строки). Долг опознан внутри EST-TRAIN точечно: Pending Work P7.4
(«`PromptsSuggestCommand` дублирует ~40 строк резолва из `EvalDiffCommand`») и ТЗ P8.2 EST-TRAIN
(«вынести шаги 1–3 `ai:gold:import` в общий сервис»).

## 11. Промты: девять групп плоско

`resources/ai/prompts/`: `global`, `prompt-critic` (общие) · `screening-global`,
`screening-classify` (стадия скрининга) · `extraction-global`, `extraction-discovery`,
`extraction-passport`, `extraction-rows`, `extraction-totals` (сметные)
(RAG:— repo-grounded: `ls resources/ai/prompts/`). Путь читается `PromptSeeder`/`ai:prompts:sync`
и `PromptPatchApplier` (ищет файл по `key`) — переезд обязан пройти через все три.

## 12. Наследуемые долги из аудита P7 EST-TRAIN

1. **Словари `#[Alias]` не доезжают до модели** (находка F4): `EstimateHeaderMap` не используется
   нигде, кроме собственного объявления
   (RAG:— repo-grounded: `grep -rn "EstimateHeaderMap" app/ resources/` → единственное вхождение
   `app/Dto/Procurement/Ai/Estimate/EstimateHeaderMap.php:19`); боевые call-site
   (`ScreeningProcessor.php:148`, `WindowedChunkSource.php:224`, `RowRangeChunkSource.php:145`)
   передают в `compose()` только Response-класс шага, `PromptContextGenerator::generate()`
   рекурсии по вложенным типам не делает.
2. `phpstan-baseline.neon` вырос на 19 записей вне `Files` трёх items P7 — накопленное
   архитектурное расхождение (`@property` на моделях, relation-chain false positives).
3. Дублирование резолва `Analysis`/gold между `EvalDiffCommand` и `PromptsSuggestCommand`.
4. Позиционное сопоставление смет (`ordinal`) не заменено устойчивой эвристикой
   (`estimate_number → адрес → позиция`) — Pending Work P7.3.
