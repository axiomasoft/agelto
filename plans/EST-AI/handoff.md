# HANDOFF — 2026-07-09 — after P6.4

**Next:** `/task:plan-design EST-AI P6` (пересмотреть item P6.4 — эскалация в `phases/P6.md`)
**Done:** P6.1–P6.3 (без изменений). P6.4 НЕ реализован — эскалация ДО написания кода/тестов
(предусловие/чтение выполнены, кодирование не начиналось). Фаза **P6 — 🔴 Blocked (3/4)**.
**Remaining:** сначала `/task:plan-design EST-AI P6.4` (разрешить архитектурное расхождение ниже),
затем сам P6.4 → P7 (7) → P8 (2) → P9 (4) → P10 (4) → P11 (1) → P12 (2, только opus/Fable —
Routing §3). Итого 24 items впереди из 40 (закрыто 17: P1 2/4 + P2 3/3 + P3 4/4 + P4 3/3 + P5 2/2 +
P6 3/4).
**Sources of truth:** plans/EST-AI/plan.md · plans/EST-AI/phases/P6.md (P6.4 — blockquote
`🔴 ESCALATION` целиком, читать перед plan-design) · docs/specs/v2/08-estimate-extraction.md §4.2
(Fallback: оконный трёхстадийный путь), §3.4 (`extraction:discovery`) · `app/Services/Procurement/
Extraction/ChunkSource.php` (P6.1, интерфейс — предмет спора) · `app/Services/Procurement/
Extraction/ExtractionOrchestrator.php` (P6.1, `runWholeDocument()` — checkpoint-цикл, единственное
место инкремента `unitsThisRun`) · `app/Dto/Procurement/Ai/Estimate/DiscoveryResponse.php` +
`DiscoveryMarkerLine.php` + `app/Enums/Procurement/DiscoveryMarker.php` (P2.2, контракт готов, но
без потребителя-метода в ChunkSource) · `app/Enums/Procurement/PromptStep.php` (`ExtractionDiscovery`
уже добавлен P3.3) · `app/Enums/Procurement/LlmPurpose.php` (кейс `Discovery` — НЕ добавлен, Scope
Included P6.4, заблокирован тем же архитектурным вопросом) · `app/Services/Procurement/Screening/
PdfPageSlicer.php` (P5.1, qpdf-резка окон).
**Open risks:**
- **Новое, блокирующее P6.4:** интерфейс `ChunkSource` (P6.1/D40, 3 метода, row-index-адресация)
  не имеет метода/response-типа под `extraction:discovery` (`DiscoveryResponse`), а стадия 2
  оконного пути (спека 08 §4.2) явно требует чанкинг СТРАНИЦАМИ, а не row-index-диапазонами —
  два независимых несовпадения контракта, оба блокируют прямую реализацию `WindowedChunkSource`
  без правки `ChunkSource.php` (владелец — P6.1, не в Files P6.4).
- **Новое, блокирующее P6.4:** `ExtractionOrchestrator::runWholeDocument()` (P6.1) — единственное
  место, где чекпоинтится `analysis.progress`/инкрементируется `unitsThisRun`, ПОСЛЕ каждого
  `ChunkSource`-вызова; discovery гигантского документа (сотни страниц ÷ K=3 = сотни LLM-вызовов)
  требует стольких же checkpoint-единиц ДО первого валидного `PassportResponse`, но
  `fetchPassport()` возвращает РОВНО один ответ на вызов — спрятать весь discovery внутри одного
  вызова нарушает Code Guidance P6.4 («каждое окно — единица прогресса для self-requeue») и прямо
  противоречит Why этого item'а (документ 2–200 МБ, D31 research/L finding 18). Полный текст — см.
  blockquote в `phases/P6.md` под P6.4.
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
- Explicit context cache для extraction НЕ подключён (P6.1) — implicit-only (без изменений).
- `EstimatePersister` пропускает сметы документов без `procurement_id` (manual-импорт, D22/P7.4)
  (без изменений, P6.3).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений,
  P6.3).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений, P6.3).
- `total_amount`/`checkGrandTotal()` дублируют одну эвристику в двух местах (без изменений, P6.3).
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений с P6.3) `docker compose exec` — два `--env-file`; composer/pint-
  автофикс — с хоста при необходимости (bind-mount permissions); `.env.example` — слепой append;
  `pint --dirty` не видит untracked — явный список путей; `php artisan test` — один `--filter` за
  прогон; `config('ai.capabilities.<модель-с-точками>.<ключ>')` — НЕ работает (dot-notation),
  индексировать массив напрямую.
- deferred: (без изменений с P6.3) боевое наполнение промт-текстов (P8); Batch API (D7); RAR/7z в
  ArchiveStrategy; OCR/предобработка сканов; LibreOffice-конвертер xlsx (D35); Ghostscript-
  даунскейл скан-PDF; generic ai_extractions (D13); контакты-сущность под CRM; полный
  9-префиксный ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание GlitchTip
  (P7.5); прод-хранилище/cron бэкапов и MinIO ILM (P7.7); shadow CI-гейт; калибровка порогов
  P6.4/P5.2/P6.2; переключение сидов на 3.x-модели (D27); Langfuse/registry промтов; правка
  устаревших имён таблиц в 03/04; Gemini-прокси-для-РФ; калибровка `ai.gemini.rate_limit.*`;
  тариф gemini-2.5-pro >200k токенов; ренейм `Ai\AiRoute` (P1.4); калибровка
  `temperature`/`maxTokens` по purpose (P3.3); второй driver для `AiRouteResolver::providerFor()`
  (P3.3); stuck-check зависших lease (P7.3); `LlmCallRecorder`-интеграция ВНУТРИ `ReaskPolicy`
  (P3.4); точечный фикс `LlmCallRecorder::costMicros()` dot-notation; explicit context cache для
  extraction; per-purpose роутинг в `ExtractionOrchestrator`; runtime-обнаружение провайдерского
  лимита ПОСЛЕ preflight; manual-импорт документов вне закупки (P7.4); полный пересчёт
  `validation_status`/иерархии после `RerunRangeAction`; путь для `mergedIncompleteItems` в
  UI/датасет через `raw_result` (P9/Filament-витрина).
- open_questions: подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip (P7.5);
  хост/расписание бэкапов (P7.7); дата переключения на 3.x-модели (D27); нужен ли исходящий прокси
  для Gemini из среды деплоя; нужен ли структурный reason-столбец на `prc_documents`/
  `prc_ai_analyses` для unprocessable; guard-фикс `ScreeningJob::statusGuardPassed()` под редкий
  сценарий (P5.2); overlap-дедуп по составному ключу до реальных границ чанков (P6.2); источник
  `Estimate.title` (P6.3); структурный `is_merged_from_split`/якорь `row_index` для склеенных
  incomplete-строк (P6.3). **Новое, P6.4 — требует решения plan-design:** как разрешить конфликт
  `ChunkSource`-контракта (row-index, 3 response-типа) с page-based discovery/per-estimate
  чанкингом оконного пути — варианты на выбор владельца/plan-design: (а) расширить `ChunkSource`
  четвёртым методом под `DiscoveryResponse` + явно допустить, что `fetchRows()` для
  `WindowedChunkSource` интерпретирует параметры иначе (страницы вместо row-index) с
  документированным контрактом; (б) вывести discovery/windowed-цикл в ОТДЕЛЬНЫЙ от `ChunkSource`
  механизм (свой интерфейс + собственная checkpoint-схема в `ExtractionOrchestrator`, не через
  `runWholeDocument()`); (в) сознательно принять «discovery одного документа — один
  не-чекпointящийся вызов» (нарушение Code Guidance P6.4, риск таймаута на гигантских документах)
  с явным решением владельца и правкой Code Guidance. Разрешение — новое `D#` в plan.md.

Context: эскалация — свежий handoff, перед `/task:plan-design EST-AI P6.4` рекомендован /clear.
