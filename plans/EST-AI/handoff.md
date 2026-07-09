# HANDOFF — 2026-07-09 — after P7.1

**Next:** `/task:plan-exec EST-AI P7.2` (Сиды роутинга/провайдеров и отчёт стоимости — item уже
детализирован в `phases/P7.md`, `plan-design` не требуется).
**Done:** P1–P6 (P1/P3/P4/P5 — 🟠 Done with deviations; P2/P6 — 🟢 Done) + P7.1 (🟠 Done with
deviations, закрыт этим прогоном). Итого закрыто 19 items из 40 (P1 2/4 + P2 3/3 + P3 4/4 + P4 3/3
+ P5 2/2 + P6 4/4 + P7 1/7).
**Remaining:** P7 (6 items осталось: P7.2–P7.7, все детализированы) → P8 (2) → P9 (4) → P10 (4) →
P11 (1) → P12 (2, только opus/Fable — Routing §3). Итого 21 item впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D43 — полный контекст архитектурных
решений; Update Log — детали каждого закрытого item'а) · plans/EST-AI/phases/P7.md (P7.1 Completion
Notes/Known Deviations/Pending Work — детали реализации CRUD-кэша; P7.2–P7.7 уже детализированы,
готовы к исполнению) · `app/Services/Llm/LlmCacheManager.php` + `app/Console/Commands/Ai/
CachesCommand.php` (P7.1, весь CRUD explicit context cache + мониторинг implicit-хитов) ·
docs/specs/v2/04-llm-providers.md §2.1,§8 (контракт `SupportsContextCaching`, ограничение
`cachedContent` несовместим с `system_instruction` в одном запросе).
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
- **Новое (P7.1):** explicit context cache НЕ подключён к `ExtractionOrchestrator`/
  `RowRangeChunkSource` — `LlmCacheManager` построен и протестирован как самостоятельный
  orchestrator-ready сервис, но живая интеграция вскрыла архитектурный конфликт: row-range путь
  composes РАЗНЫЙ `systemPart` на каждый purpose (passport/rows/totals — разная схема в системной
  части), а explicit cache Gemini фиксирует ОДИН system_instruction на этапе создания и
  несовместим с его повторной отправкой в том же запросе (ТЗ 04 §2.1). Подключение требует
  архитектурного решения (развести «стабильную часть» и «per-purpose схему» одного кэша, либо
  завести несколько кэшей на сессию), которого нет в Decision Log — кандидат для
  `plan-design`/нового Decision перед тем, как P7.1-Pending Work можно закрыть. См. Known
  Deviations P7.1 в phases/P7.md.
- **Новое (P7.1):** «когда явный кэш выгоднее implicit» — числовой порог не задан нигде в ТЗ/
  Required Reads (только качественно «для крупных смет, где implicit ненадёжен», ТЗ 04 §2.1) —
  калибровка/явное решение нужны, когда кэш реально подключат к оркестратору.
- Explicit context cache для extraction НЕ подключён (P6.1/P6.4) — implicit-only (без изменений
  по факту, но теперь готовый механизм для подключения существует, см. выше).
- `EstimatePersister` пропускает сметы документов без `procurement_id` (manual-импорт, D22/P7.4)
  (без изменений, P6.3).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений,
  P6.3).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений, P6.3).
- `total_amount`/`checkGrandTotal()` дублируют одну эвристику в двух местах (без изменений, P6.3).
- `window_pages`(3)/`window_overlap_pages`(1)/`totals_tail_pages`(2) не откалиброваны на реальных
  сметах (без изменений, P6.4) — Pending Work до датасета P7.4.
- offset-инструкция в user-части windowed-вызовов не проверена на реальном Gemini (без изменений,
  P6.4).
- документы `image/*` в windowed-пути сломались бы на `PdfPageSlicer::sliceRange()` (без
  изменений, P6.4, теоретический разрыв).
- `max_estimates_per_document`-класс защитного потолка НЕ применён к `runWindowed()` (без
  изменений, P6.4).
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
  `LlmCallRecorder::costMicros()` dot-notation (P7.2-кандидат); per-purpose роутинг в
  `ExtractionOrchestrator`; runtime-обнаружение провайдерского лимита ПОСЛЕ preflight;
  manual-импорт документов вне закупки (P7.4); полный пересчёт `validation_status`/иерархии после
  `RerunRangeAction`; путь для `mergedIncompleteItems` в UI/датасет через `raw_result`
  (P9/Filament-витрина); discovery-маршрут (сид `prc_ai_routes`) — P7.2; discovery-промт-текст —
  P8.1; `image/*` в windowed-пути (P6.4, теоретический разрыв); `max_estimates_per_document`-аналог
  для `runWindowed()`; **живое подключение explicit context cache в `ExtractionOrchestrator`/
  `RowRangeChunkSource` (P7.1 Pending Work — ждёт архитектурного решения про per-purpose systemPart
  vs единый cached system_instruction)**; **периодическое расписание `ai:caches prune` (P7.1 —
  естественно ложится в P7.3 рядом со `stuck-check`)**.
- open_questions: подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip (P7.5);
  хост/расписание бэкапов (P7.7); дата переключения на 3.x-модели (D27); нужен ли исходящий прокси
  для Gemini из среды деплоя; нужен ли структурный reason-столбец на `prc_documents`/
  `prc_ai_analyses` для unprocessable; guard-фикс `ScreeningJob::statusGuardPassed()` под редкий
  сценарий (P5.2); overlap-дедуп по составному ключу до реальных границ чанков (P6.2); источник
  `Estimate.title` (P6.3); структурный `is_merged_from_split`/якорь `row_index` для склеенных
  incomplete-строк (P6.3); реальная калибровка `window_pages`/`window_overlap_pages`/
  `totals_tail_pages` (P6.4, ждёт датасет P7.4); **как архитектурно развести «стабильную» и
  «per-purpose схемную» части системного промта extraction для явного контекстного кэша (P7.1) —
  нужно решение владельца/plan-design, не изобреталось в одностороннем порядке**; **числовой порог
  «когда explicit cache выгоднее implicit» (P7.1, ТЗ 04 §2.1 задаёт только качественный критерий)**.

Context: P7.1 закрыт с задокументированным Known Deviation (см. Open risks выше) — вход в P7.2
требует прочитать phases/P7.md §P7.2 (сиды роутинга/провайдеров, cost-report) и config/ai.php;
контекст explicit-кэша P7.1 для P7.2 НЕ нужен (разные подсистемы), можно `/clear`.
