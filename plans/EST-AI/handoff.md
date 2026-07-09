# HANDOFF — 2026-07-09 — after P6.4

**Next:** `/task:plan-exec EST-AI P6.4` (реализация оконного пути по обновлённому ТЗ item'а —
эскалация разрешена, item доведён до исполняемого состояния, Status ⬜ Not started).
**Done:** P6.1–P6.3 (без изменений). Эскалация P6.4 (архитектурное расхождение `ChunkSource` vs
оконный путь) разрешена `/task:plan-design` (D42/D43, plans/EST-AI/plan.md §5) — item P6.4
полностью переписан в `phases/P6.md` (Intent/Scope/Files/Required Reads/Implementation
Rules/Code Guidance доведены до уровня «sonnet исполнит без вопросов»), кодирование ЕЩЁ НЕ
начиналось. Фаза **P6 — 🟡 In progress (3/4)**.
**Remaining:** сам P6.4 (реализация) → P7 (7) → P8 (2) → P9 (4) → P10 (4) → P11 (1) → P12 (2,
только opus/Fable — Routing §3). Итого 24 items впереди из 40 (закрыто 17: P1 2/4 + P2 3/3 +
P3 4/4 + P4 3/3 + P5 2/2 + P6 3/4).
**Sources of truth:** plans/EST-AI/plan.md (D42/D43 — решение эскалации) ·
plans/EST-AI/phases/P6.md (P6.4 — blockquote «Разрешение эскалации» в начале item'а + полный
переписанный item, читать ПЕРЕД exec) · docs/specs/v2/08-estimate-extraction.md §3.4,§4.2,§4а,
§4б,§4д (discovery-контракт, алгоритм окон, якорение row_index, семантика incomplete_items,
checkpoint-requeue) · `app/Services/Procurement/Extraction/{ChunkSource,RowRangeChunkSource,
ExtractionOrchestrator,ExtractionSession,ExtractionPreflight}.php` (P6.1 — `ChunkSource`/
`RowRangeChunkSource` НЕ трогать, `ExtractionOrchestrator` получает новый `runWindowed()`) ·
`app/Dto/Procurement/Ai/Estimate/{DiscoveryResponse,DiscoveryMarkerLine}.php` +
`app/Enums/Procurement/DiscoveryMarker.php` (P2.2, контракт готов, потребитель — новый
`WindowedChunkSource::fetchDiscoveryWindow()`) · `app/Enums/Procurement/PromptStep.php`
(`ExtractionDiscovery` уже добавлен P3.3) · `app/Enums/Procurement/LlmPurpose.php` (кейс
`Discovery` — добавляется в P6.4, до сих пор отсутствует несмотря на устаревшую пометку Audit
P6 «резолюция D37», см. коррекцию в P6.md) · `app/Services/Procurement/Screening/
PdfPageSlicer.php` (P5.1, добавляется `sliceRange()`).
**Open risks:**
- Разрешено (закрыто D42/D43, было «блокирующее P6.4» в предыдущем handoff): контракт
  `ChunkSource` и checkpoint-грануляция `runWholeDocument()` не подходили под discovery/
  page-based оконный путь — решение: отдельный `WindowedChunkSource` (НЕ `ChunkSource`) + второй
  top-level `ExtractionOrchestrator::runWindowed()`. Реализация ещё предстоит — риск снят на
  уровне АРХИТЕКТУРЫ, не на уровне кода (P6.4 exec может вскрыть детали, не покрытые ТЗ).
- Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца (без изменений).
- Троттлинг Gemini не откалиброван против реальной квоты провайдера (без изменений).
- Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют устаревшие
  имена таблиц (`doc_*`/`est_*`/`ai_*` вместо `prc_*`) — расхождение не устранено.
- `docker compose exec` требует ДВА `--env-file` (`--env-file .env --env-file .env.docker`);
  `pint --dirty` внутри контейнера НЕ подхватывает untracked-файлы (только `git diff
  --name-only`) — на untracked-heavy item'ах гонять `pint --test <явный список путей>`; контейнер
  `app` не может ЗАПИСАТЬ автофикс в bind-mount — автофикс делать на хосте.
- `.env`/`.env.example` закрыты от чтения — правки только append вслепую.
- `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется, гонять раздельными
  командами.
- `ReaskPolicy` НЕ используется оркестраторами (P5.1/P6.1) — не отдаёт usage для
  `LlmCallRecorder` (без изменений).
- `config("ai.capabilities.{$model}")` (dot-notation) ломается на реальных именах моделей Gemini
  (точки в имени) — затрагивает `LlmCallRecorder::costMicros()` (P3.2, тихо `cost_micros=0`);
  `ExtractionPreflight` (P6.1) НЕ повторяет паттерн. Точечный фикс — вне Scope, рекомендован
  ближайшему item'у, трогающему cost-учёт (P7.х) или отдельным `/task:fix` (без изменений).
- Explicit context cache для extraction НЕ подключён (P6.1) — implicit-only (без изменений); тот
  же приём для нового `WindowedChunkSource` (P6.4 Implementation Rules).
- `EstimatePersister` пропускает сметы документов без `procurement_id` (manual-импорт, D22/P7.4)
  (без изменений, P6.3).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений,
  P6.3).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений, P6.3).
- `total_amount`/`checkGrandTotal()` дублируют одну эвристику в двух местах (без изменений, P6.3).
- **Новое (P6.4-дизайн):** глубина/размер discovery-окна (`window_pages`=3/`window_overlap_pages`=1/
  `totals_tail_pages`=2) не откалиброваны на реальных сметах (тот же класс отложенных калибровок,
  что `row_range_size`/`SEVERE_COMPLETENESS_RATIO`) — Pending Work до датасета P7.4.
- **Новое (P6.4-дизайн):** offset-инструкция в user-части (глобальный номер страницы через
  срез) — НЕ протестирована на реальном Gemini (только `FakeLlmProvider` в Scope P6.4); модель
  может путать номер страницы среза с номером страницы исходника на практике — калибровка/
  owner-smoke после первого боевого прогона оконного пути.
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений) `docker compose exec` — два `--env-file`; composer/pint-автофикс
  — с хоста при необходимости (bind-mount permissions); `.env.example` — слепой append; `pint
  --dirty` не видит untracked — явный список путей; `php artisan test` — один `--filter` за
  прогон; `config('ai.capabilities.<модель-с-точками>.<ключ>')` — НЕ работает (dot-notation),
  индексировать массив напрямую.
- deferred: (без изменений с P6.3, плюс P6.4-калибровки выше) боевое наполнение промт-текстов
  (P8); Batch API (D7); RAR/7z в ArchiveStrategy; OCR/предобработка сканов; LibreOffice-конвертер
  xlsx (D35); Ghostscript-даунскейл скан-PDF; generic ai_extractions (D13); контакты-сущность под
  CRM; полный 9-префиксный ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание
  GlitchTip (P7.5); прод-хранилище/cron бэкапов и MinIO ILM (P7.7); shadow CI-гейт; калибровка
  порогов P6.4/P5.2/P6.2; переключение сидов на 3.x-модели (D27); Langfuse/registry промтов;
  правка устаревших имён таблиц в 03/04; Gemini-прокси-для-РФ; калибровка
  `ai.gemini.rate_limit.*`; тариф gemini-2.5-pro >200k токенов; ренейм `Ai\AiRoute` (P1.4);
  калибровка `temperature`/`maxTokens` по purpose (P3.3); второй driver для
  `AiRouteResolver::providerFor()` (P3.3); stuck-check зависших lease (P7.3);
  `LlmCallRecorder`-интеграция ВНУТРИ `ReaskPolicy` (P3.4); точечный фикс
  `LlmCallRecorder::costMicros()` dot-notation; explicit context cache для extraction; per-purpose
  роутинг в `ExtractionOrchestrator`; runtime-обнаружение провайдерского лимита ПОСЛЕ preflight;
  manual-импорт документов вне закупки (P7.4); полный пересчёт `validation_status`/иерархии после
  `RerunRangeAction`; путь для `mergedIncompleteItems` в UI/датасет через `raw_result` (P9/
  Filament-витрина); discovery-маршрут (сид `prc_ai_routes`) — P7.2; discovery-промт-текст — P8.1.
- open_questions: подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip (P7.5);
  хост/расписание бэкапов (P7.7); дата переключения на 3.x-модели (D27); нужен ли исходящий прокси
  для Gemini из среды деплоя; нужен ли структурный reason-столбец на `prc_documents`/
  `prc_ai_analyses` для unprocessable; guard-фикс `ScreeningJob::statusGuardPassed()` под редкий
  сценарий (P5.2); overlap-дедуп по составному ключу до реальных границ чанков (P6.2); источник
  `Estimate.title` (P6.3); структурный `is_merged_from_split`/якорь `row_index` для склеенных
  incomplete-строк (P6.3).

Context: эскалация P6.4 разрешена (`/task:plan-design`, D42/D43) — item готов к исполнению. Перед
`/task:plan-exec EST-AI P6.4` рекомендован /clear (дизайн-контекст объёмный, exec стартует
свежим по обновлённому ТЗ item'а).
