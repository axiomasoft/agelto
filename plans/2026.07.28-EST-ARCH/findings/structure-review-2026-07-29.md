# Структурный разбор по замечаниям владельца — 2026-07-29

Слой 1 (`plan-protocol` §16). Разбор четырёх замечаний владельца от 2026-07-29 (`brief/01-refinements.md`).
Факты кода сверены чтением на `74f515b`; внешний факт (канон размещения трейтов) — RAG.
Потребители — items P1.1, P1.3, P1.5, P1.6, P1.7.

---

## 1. Домен LLM-транспорта: сервисный слой уже отделён, модели — нет

**Замечание владельца:** «`app/Models/Procurement/Ai/Provider.php` не относится напрямую к
закупкам (она общая), значит будет `ai_providers`. Ну и путь новый».

Факты:

- `app/Services/Llm/` — 29 файлов, самостоятельный слой: `AiRouteResolver`, `LlmCallRecorder`,
  `PromptComposer`, `LlmCacheManager`, `ShadowDispatch`, `Contracts/LlmProvider`,
  `Providers/{PrismGeminiProvider,OpenAiCompatibleProvider}`, `Dto/*`, `Schema/*`, `Validation/*`
  (RAG:— repo-grounded: `find app/Services/Llm -name "*.php"`).
- Модели, которыми этот слой владеет, лежат в ЗАКУПОЧНОМ домене:
  `App\Models\Procurement\Ai\{Provider,AiRoute,Prompt,LlmCall}` с таблицами `prc_ai_*`
  (RAG:— repo-grounded: `app/Models/Procurement/Ai/Provider.php:5,22`).
- В схеме `prc_ai_providers` НЕТ ни одного закупочного понятия: `code`, `driver`, `base_url`,
  `options`, `is_active` (RAG:— repo-grounded: `app/Models/Procurement/Ai/Provider.php:24-30`).
- Имя `Llm` уже канон в коде: `LlmProvider`, `LlmPurpose`, `LlmCall`, `LlmOptions`, `LlmResponse`,
  `LlmUsage`, `LlmCacheHandle` (RAG:— repo-grounded: `ls app/Services/Llm/Dto/`).

Вывод: граница домена проведена в сервисном слое и не проведена в модельном. Владелец заметил её
на одной таблице; по факту она проходит по четырём. → **D22** (домен `Llm`, префикс `llm_`, четыре
модели), исполняет **P1.5**.

**Известная цена решения:** `llm_routes.document_type` и `llm_prompts.document_type` остаются
параметризованы закупочным enum'ом `AiDocumentType` с check-constraint по D17 — транспорт хранит
словарь потребителя как ключ маршрутизации. Принято явно.

**Риск переезда:** `prc_ai_llm_calls` партиционирована `PARTITION BY RANGE (called_at)` с составным
PK `(id, called_at)`; на её имя ссылаются три отдельные миграции — `2026_07_04_100047_setup_pg_partman`,
`2026_07_09_100050_set_pg_partman_retention`, `2026_07_09_100051_set_hot_partition_autovacuum`
(RAG:— repo-grounded: `database/migrations/2026_07_04_100026_…:12-45`). Переименование родителя не
переименовывает партиции и не правит `part_config` — проверяется отдельно (Validation P1.5).

**Следствие для канона проекта:** `CLAUDE.md` утверждает «три домена по короткому префиксу таблицы —
`prc_`/`ref_`/`org_`». После D22 префиксов четыре. Источник (`.ai/guidelines/architecture.md`) и
`docs/03_Dev/{architecture,database}.md` правятся в P1.5.

## 2. Обучалка и боевой контур: унификация есть, дискриминатор безымянный

**Замечание владельца:** «обучение и реальные документы с сайта госзакупок должны работать 1 в 1
при запуске парсинга, просто для обучалки только ручной режим, а для госзакупок автоматический и
возможность перезапустить вручную; подчинённые таблицы типа вызовов llm будут одинаковые; таблица
смет тоже 1 в 1, только эти сметы не участвуют в показе на сайте».

Факты — **унификация УЖЕ достигнута фактически**:

- Обучающий файл получает полноценные `Document` + `DocumentVersion`:
  `GoldImportCommand.php:153` и `DatasetImportCommand.php:187` создают их с `'procurement_id' => null`
  (RAG:— repo-grounded).
- `prc_ai_analyses.document_version_id`, `prc_ai_llm_calls.analysis_id`, `prc_estimates.analysis_id`
  — подчинённые таблицы висят на общей оси и не знают о происхождении документа
  (RAG:— repo-grounded: `2026_07_04_100025_…:20-24`, `2026_07_04_100026_…:15`).

Факты — **чего не хватает**:

1. **Дискриминатор безымянный и перегружен.** «Обучающий документ» кодируется условием
   `procurement_id IS NULL` в семи местах, и под ним живут ДВА разных факта
   (RAG:— repo-grounded: `grep -rn "procurement_id" app/ --include=*.php | grep -iE "null"`):

   | Call-site | Смысл сегодня |
   |:--|:--|
   | `ScreeningOutcomeHandler.php:159` (`storeEvent`) | структурный: журнала закупки нет — писать некуда |
   | `ScreeningOutcomeHandler.php:184` (`matchesIgnoreRule`) | структурный: нет закупки — нечего сверять с правилом |
   | `EstimatePersister.php:266` (`storeEvent`) | структурный: то же |
   | `ArchiveStrategy.php:207` | структурный: событие «документ добавлен» пишется только при закупке |
   | `GsfxStrategy.php:305` | то же |
   | `FormatBenchRunner.php:114` | **контурный**: «бенчмарк работает только по документам эталонов» |
   | `DocumentStoreRepository.php:27` | смешанный: поиск по `external_id` вне закупки |

2. **Условие уже не эквивалентно «эталон».** Докблок `DocumentStoreRepository.php:19` прямо
   допускает вне закупки И дочерние документы архива (P4.2), И manual-импорт (D22 EST-TRAIN)
   (RAG:— repo-grounded). То есть гвард `FormatBenchRunner` уже неточен.

3. **Прямой связи `Document ↔ Fixture` нет.** Мост идёт через `DatasetExample.fixture_id` +
   `DatasetExample.document_version_id` (RAG:— repo-grounded: `FormatBenchRunner.php:104-112`,
   `eligibleExamples()`): «документ этого эталона» достижим только через пример датасета.

4. **Правила показа на сайте нет как носителя.** Требование «обучающие сметы не показываем»
   держалось бы тем же безымянным условием в каждом запросе.

5. **Оси режима запуска нет.** Обучалка — только ручной запуск, боевой — автоматический плюс
   ручной перезапуск; в схеме этого различия нет.

6. **«Прогон» не является сущностью.** Один запуск = ДВА независимых `prc_ai_analyses`
   (`screening` + `extraction`), связанных только общим `document_version_id`; строка истории с
   токенами и ценой (бриф §2) обязана суммировать оба. Перезапуск сегодня выражается
   `parent_analysis_id`/`superseded_at` на КАЖДОМ шаге отдельно
   (RAG:— repo-grounded: `2026_07_04_100025_…:60-70,88-93`).

→ **D24** (ось происхождения + связь с эталоном + носитель показа), **D25** (сущность прогона с
режимом запуска и ограничением объёма). Контракт — **P1.3**, исполнение — **P1.6**.

## 3. Размещение трейтов моделей (`Concerns`)

**Замечание владельца:** «`app/Models/Procurement/Concerns` не нравится расположение. Может
`app/Concerns` или `app/Models/Concerns`. Узнать через perplexity-web лучшие практики».

Факты репо:

- В каталоге два трейта: `HasErrorBlock`, `HasLease`
  (RAG:— repo-grounded: `ls app/Models/Procurement/Concerns/`).
- Реальные потребители (`use` внутри класса) — ТОЛЬКО модели: `Document\Document:23`,
  `Ai\Analysis:35`. Все прочие упоминания (`PipelineJob`, `PipelineDispatcher`, `ScreeningProcessor`,
  `RowRangeChunkSource`, `ScreeningJob`, `PipelineRetryFailed`) — докблоки, не импорты
  (RAG:— repo-grounded: `grep -rn "HasErrorBlock\|HasLease" app/`).
- В `app/` уже есть инфраструктурные каталоги верхнего уровня: `Attributes`, `Casts`, `Guards`.

**RAG:✅ 2026-07-29** (Perplexity Web, запрос «Laravel 12 project structure: model traits placement —
app/Models/Concerns vs app/Concerns vs app/Traits, best practice 2025–2026»; источники: laraveldaily,
dev.to, r/laravel, cowdev, stackoverflow, spatie docs). Вердикт:

- `app/Models/Concerns` — для трейтов, представляющих поведение МОДЕЛИ (скоупы, связи, атрибуты).
  Локальность: поведение лежит рядом с моделью, аналогия с `app/models/concerns` Rails.
- `app/Concerns` — только для сквозных трейтов, переиспользуемых ВНЕ моделей (контроллеры, джобы,
  сервисы). Явный риск, названный в источниках: превращается в «digital junk drawer».
- `app/Traits` — общее ведро, менее выразительно; не рекомендуется для проектов с доменной раскладкой.
- Laravel сам ничего не предписывает: модели и трейты могут лежать где угодно под `app/` при
  совпадении неймспейсов — это конвенция, не требование фреймворка.

Применение к репо: оба трейта — чистое поведение модели, потребителей вне моделей нет, но
потребители уже в РАЗНЫХ поддоменах (`Document`, `Ai`), а после D22 поддомены разъезжаются сильнее
→ уровень `Procurement` в пути стал ложным. Целевое — **`App\Models\Concerns`**. → **D23**,
исполняет **P1.7**.

## 4. Размещение `DocumentType` — вопрос снят решением D16

**Замечание владельца:** «`app/Models/Procurement/Platform/DocumentType.php` странно, что в папке
`Platform`, а имя `prc_document_types` (может в `app/Models/Procurement/Document`)».

Наблюдение верное: модель лежит в `Platform`, таблица называется `prc_document_types`, и по смыслу
это справочник типов документа, а не площадки.

Но эта таблица — **мёртвый реестр**: ни одного использования, `has_extractor` читает только
собственная фабрика (`findings/repo-structure-2026-07-28.md` §3). Владелец утвердил **D16**
(2026-07-29): таблица, модель и фабрика СНОСЯТСЯ, SSOT типа документа — enum `AiDocumentType` +
реестр анализаторов в коде (P2). Вопрос размещения снимается вместе с сущностью; исполняет **P1.4**.

Если владелец предпочтёт сохранить реестр — отменяется D16 новой D-записью со `supersedes D16`, и
только тогда вопрос размещения возвращается.
