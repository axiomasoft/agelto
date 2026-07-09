# HANDOFF — 2026-07-09 — after P6.4

**Next:** `/task:plan-exec EST-AI P7.1` (все 7 items фазы P7 уже детализированы — не скелеты,
`plan-exec` можно запускать напрямую, `plan-design` не требуется).
**Done:** P1–P6 (P1/P3/P4/P5 — 🟠 Done with deviations; P2 — 🟢 Done; **P6 — 🟢 Done, 4/4**,
закрыт этим прогоном). P6.4 (оконный путь извлечения) реализован по эскалационному ТЗ D42/D43:
`WindowedChunkSource` (4 метода, самостоятельный контракт) + `SegmentAssembler` (детерминированная
сборка сегментов из discovery-маркеров) + `ExtractionOrchestrator::runWindowed()` (второй
top-level метод). `config('ai.extraction.windowed_enabled')` теперь `true` по умолчанию — оконный
путь боевой, preflight (D29) реально маршрутизирует гигантские/мульти-сметные документы туда, не
в `unprocessable`. Итого закрыто 18 items из 40 (P1 2/4 + P2 3/3 + P3 4/4 + P4 3/3 + P5 2/2 +
P6 4/4).
**Remaining:** P7 (7 items, все детализированы) → P8 (2) → P9 (4) → P10 (4) → P11 (1) → P12 (2,
только opus/Fable — Routing §3). Итого 22 items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (D42/D43 — решение эскалации P6.4; Decision Log D1–D43
полный контекст архитектурных решений) · plans/EST-AI/phases/P6.md (P6.4 Completion Notes/Known
Deviations/Pending Work — детали реализации оконного пути; Phase Handoff — итог фазы) ·
plans/EST-AI/phases/P7.md (все 7 items детализированы: P7.1 context-cache CRUD, P7.2 сиды
роутинга+cost-report, P7.3 stuck-check/алерты, P7.4 датасет эталонов+eval, P7.5 observability
(Sentry/Pulse/trace), P7.6 деплой с живыми очередями, P7.7 data lifecycle/retention/бэкапы) ·
docs/specs/v2/08-estimate-extraction.md §3.4,§4.2,§4а,§4б,§4в,§4г,§4д (полный алгоритм извлечения,
оба пути) · `app/Services/Procurement/Extraction/{ChunkSource,RowRangeChunkSource,
WindowedChunkSource,SegmentAssembler,ExtractionOrchestrator,ExtractionSession,ExtractionPreflight,
EstimateStitcher,EstimateValidator,EstimatePersister,RerunRangeAction}.php` (весь Extraction-слой,
целиком реализован).
**Open risks:**
- Модели EOL (D27): линейка 2.5 умирает 2026-10-16 — P7.2/P7.3 адресуют мониторинг, не решают.
- Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца (без изменений).
- Троттлинг Gemini не откалиброван против реальной квоты провайдера (без изменений).
- `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют устаревшие
  имена таблиц (`doc_*`/`est_*`/`ai_*` вместо `prc_*`) — расхождение не устранено.
- `docker compose exec` требует ДВА `--env-file` (`--env-file .env --env-file .env.docker`);
  `pint --dirty` внутри контейнера НЕ подхватывает untracked-файлы (только `git diff
  --name-only`) — на untracked-heavy item'ах гонять `pint --test <явный список путей>`; контейнер
  `app` не может ЗАПИСАТЬ автофикс в bind-mount — автофикс делать на хосте.
- `.env`/`.env.example` закрыты от чтения — правки только append вслепую.
- `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется, гонять раздельными
  командами.
- `ReaskPolicy` НЕ используется оркестраторами (P5.1/P6.1/P6.4) — не отдаёт usage для
  `LlmCallRecorder` (без изменений).
- `config("ai.capabilities.{$model}")` (dot-notation) ломается на реальных именах моделей Gemini
  (точки в имени) — затрагивает `LlmCallRecorder::costMicros()` (P3.2, тихо `cost_micros=0`);
  `ExtractionPreflight` (P6.1) НЕ повторяет паттерн. Точечный фикс — вне Scope, рекомендован
  ближайшему item'у, трогающему cost-учёт (P7.2 cost-report — естественный кандидат).
- Explicit context cache для extraction НЕ подключён (P6.1/P6.4) — implicit-only (без изменений);
  P7.1 (CRUD context-кэшей) — естественный кандидат для этой работы.
- `EstimatePersister` пропускает сметы документов без `procurement_id` (manual-импорт, D22/P7.4)
  (без изменений, P6.3).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений,
  P6.3).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений, P6.3).
- `total_amount`/`checkGrandTotal()` дублируют одну эвристику в двух местах (без изменений, P6.3).
- **Новое (P6.4):** `window_pages`(3)/`window_overlap_pages`(1)/`totals_tail_pages`(2) не
  откалиброваны на реальных сметах — тот же класс отложенных калибровок, что `row_range_size`/
  `SEVERE_COMPLETENESS_RATIO`, Pending Work до датасета P7.4.
- **Новое (P6.4):** offset-инструкция в user-части windowed-вызовов (замена v1 текстовых
  `[PAGE_START]`-маркеров) НЕ проверена на реальном Gemini (только `FakeLlmProvider`) — модель
  может путать номер страницы среза с номером страницы исходника на практике; калибровка/
  owner-smoke после первого боевого прогона оконного пути.
- **Новое (P6.4):** документы `image/*` (не PDF), маршрутизированные в windowed preflight'ом по
  превышению `max_request_file_mb`, сломались бы на `PdfPageSlicer::sliceRange()` (`qpdf` не
  читает растровые форматы) — теоретический разрыв, реальный windowed-триггер ожидается на сканах,
  УЖЕ упакованных в PDF (research/L finding 17); не покрыт тестом, не чинился.
- **Новое (P6.4):** `max_estimates_per_document`-класс защитного потолка НЕ применён к
  `runWindowed()` (только к `runWholeDocument()`) — цикл естественно ограничен `total_pages`
  (конечен), но патологический маркерный шум может породить много мелких suspicious-сегментов без
  верхней границы их числа; не блокер, калибровка на реальных данных.
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений) `docker compose exec` — два `--env-file`; composer/pint-автофикс
  — с хоста при необходимости (bind-mount permissions); `.env.example` — слепой append; `pint
  --dirty` не видит untracked — явный список путей; `php artisan test` — один `--filter` за
  прогон; `config('ai.capabilities.<модель-с-точками>.<ключ>')` — НЕ работает (dot-notation),
  индексировать массив напрямую.
- deferred: боевое наполнение промт-текстов (P8, вкл. 8-й discovery-промт); Batch API (D7); RAR/7z
  в ArchiveStrategy; OCR/предобработка сканов; LibreOffice-конвертер xlsx (D35); Ghostscript-
  даунскейл скан-PDF; generic ai_extractions (D13); контакты-сущность под CRM; полный
  9-префиксный ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание GlitchTip
  (P7.5); прод-хранилище/cron бэкапов и MinIO ILM (P7.7); shadow CI-гейт; калибровка порогов
  P6.4/P5.2/P6.2 (window_pages/totals_tail_pages/confidence/SEVERE_COMPLETENESS_RATIO);
  переключение сидов на 3.x-модели (D27); Langfuse/registry промтов; правка устаревших имён
  таблиц в 03/04; Gemini-прокси-для-РФ; калибровка `ai.gemini.rate_limit.*`; тариф
  gemini-2.5-pro >200k токенов; ренейм `Ai\AiRoute` (P1.4); калибровка `temperature`/`maxTokens`
  по purpose (P3.3); второй driver для `AiRouteResolver::providerFor()` (P3.3); stuck-check
  зависших lease (P7.3); `LlmCallRecorder`-интеграция ВНУТРИ `ReaskPolicy` (P3.4); точечный фикс
  `LlmCallRecorder::costMicros()` dot-notation (P7.2-кандидат); explicit context cache для
  extraction (P7.1-кандидат); per-purpose роутинг в `ExtractionOrchestrator`; runtime-обнаружение
  провайдерского лимита ПОСЛЕ preflight; manual-импорт документов вне закупки (P7.4); полный
  пересчёт `validation_status`/иерархии после `RerunRangeAction`; путь для `mergedIncompleteItems`
  в UI/датасет через `raw_result` (P9/Filament-витрина); discovery-маршрут (сид `prc_ai_routes`)
  — P7.2; discovery-промт-текст — P8.1; `image/*` в windowed-пути (P6.4, теоретический разрыв);
  `max_estimates_per_document`-аналог для `runWindowed()`.
- open_questions: подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip (P7.5);
  хост/расписание бэкапов (P7.7); дата переключения на 3.x-модели (D27); нужен ли исходящий прокси
  для Gemini из среды деплоя; нужен ли структурный reason-столбец на `prc_documents`/
  `prc_ai_analyses` для unprocessable; guard-фикс `ScreeningJob::statusGuardPassed()` под редкий
  сценарий (P5.2); overlap-дедуп по составному ключу до реальных границ чанков (P6.2); источник
  `Estimate.title` (P6.3); структурный `is_merged_from_split`/якорь `row_index` для склеенных
  incomplete-строк (P6.3); реальная калибровка `window_pages`/`window_overlap_pages`/
  `totals_tail_pages` (P6.4, ждёт датасет P7.4).

Context: фаза P6 закрыта 🟢 (4/4) — рекомендован `/clear` перед следующим item (P7.1, свежая
доменная область — экономика/надёжность/observability, детализированное ТЗ уже есть в
phases/P7.md, дизайн-контекст P6 объёмный и больше не нужен).
