# HANDOFF — 2026-07-09 — after P7.3

**Next:** `/task:plan-exec EST-AI P7.4` (Датасет эталонов и eval-раннер — item уже детализирован в
`phases/P7.md`, `plan-design` не требуется).
**Done:** P1–P6 (P1/P3/P4/P5 — 🟠 Done with deviations; P2/P6 — 🟢 Done) + P7.1–P7.3 (все три
🟠 Done with deviations, P7.3 закрыт этим прогоном). Итого закрыто 21 item из 40 (P1 2/4 + P2 3/3
+ P3 4/4 + P4 3/3 + P5 2/2 + P6 4/4 + P7 3/7).
**Remaining:** P7 (4 items осталось: P7.4–P7.7, все детализированы) → P8 (2) → P9 (4) → P10 (4) →
P11 (1) → P12 (2, только opus/Fable — Routing §3). Итого 19 items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D43 — полный контекст архитектурных
решений; Update Log — детали каждого закрытого item'а) · plans/EST-AI/phases/P7.md (P7.3
Completion Notes/Known Deviations/Pending Work — детали проверок/порогов/алерта; P7.4–P7.7 уже
детализированы, готовы к исполнению) · `app/Console/Commands/Procurement/{PipelineStuckCheck,
PipelineStatus}.php` (P7.3) · `app/Notifications/PipelineAlert.php` (P7.3, Telegram-транспорт) ·
`config/ai.php` §`pipeline`/`model_eol_warn_days` (P7.3, все пороги stuck-check) ·
`config/services.php` §`telegram` (P7.3, канал алерта).
**Open risks:**
- Модели EOL (D27): линейка 2.5 умирает 2026-10-16 — `pipeline:stuck-check` (P7.3) теперь
  алертит при приближении (`config('ai.model_eol_warn_days', 30)`), сам переключение НЕ делает.
  `AiRoutingSeeder::routes()` уже несёт закомментированные 3.x-строки-преемники.
- Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца (без изменений).
- Троттлинг Gemini не откалиброван против реальной квоты провайдера (без изменений).
- `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют устаревшие
  имена таблиц (`doc_*`/`est_*`/`ai_*` вместо `prc_*`) — расхождение не устранено.
- `docker compose exec` требует ДВА `--env-file` (`--env-file .env --env-file .env.docker`);
  `pint --dirty` внутри контейнера НЕ подхватывает untracked-файлы (только `git diff
  --name-only`) — на untracked-heavy item'ах гонять `pint --test <явный список путей>`; контейнер
  `app` не может ЗАПИСАТЬ автофикс в bind-mount — автофикс делать на хосте (подтверждено ещё раз
  на P7.3: хостовой pint поправил `ordered_imports` в новом тест-файле).
- `.env`/`.env.example` закрыты от чтения — правки только append вслепую (P7.3 добавил
  `AI_MODEL_EOL_WARN_DAYS`/`AI_PIPELINE_*`/`TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` этим приёмом).
- `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется, гонять раздельными
  командами.
- `expectsOutputToContain()` в Laravel-моке консоли — одно ожидание на строку вывода (P7.2,
  без изменений в P7.3 — новые команды тестировались через `Http::fake()`/прямые запросы к БД,
  не через `expectsOutputToContain()`).
- `ReaskPolicy` НЕ используется оркестраторами (P5.1/P6.1/P6.4) — не отдаёт usage для
  `LlmCallRecorder` (без изменений).
- explicit context cache НЕ подключён к `ExtractionOrchestrator`/`RowRangeChunkSource` (P7.1
  Pending Work, без изменений).
- `EstimatePersister` пропускает сметы документов без `procurement_id` (manual-импорт, D22/P7.4)
  (без изменений, P6.3).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений, P6.3).
- `window_pages`(3)/`window_overlap_pages`(1)/`totals_tail_pages`(2) не откалиброваны (без
  изменений, P6.4) — Pending Work до датасета P7.4.
- **Новое (P7.3):** пороги `pipeline:stuck-check` (`analysis_stuck_minutes`=30/
  `checkpoint_stuck_minutes`=60/`document_stuck_minutes`=60/`spike_window_hours`=24/
  `unknown_spike_threshold`=20/`unprocessable_spike_threshold`=5/`estimate_mismatch_threshold`=5)
  — default'ы по аналогии с v1 и разумной экстраполяцией, НЕ откалиброваны на реальном трафике
  (тот же класс Pending Work, что window_pages/totals_tail_pages P6.4).
- **Новое (P7.3):** `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены в прод `.env` — до тех пор
  `PipelineAlert` уходит только в лог (fail-safe по дизайну, не баг), но алерт молча не долетает
  до канала пока владелец не заведёт бота.
- **Новое (P7.3):** `ai:caches prune` (P7.1 Pending Work) НЕ добавлен в расписание рядом со
  `pipeline:stuck-check`, хотя это естественная точка — вне Scope Included P7.3, не запрошено.
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений) `docker compose exec` — два `--env-file`; composer/pint-автофикс
  — с хоста при необходимости (bind-mount permissions); `.env.example` — слепой append; `pint
  --dirty` не видит untracked — явный список путей; `php artisan test` — один `--filter` за
  прогон; `config('ai.capabilities.<модель-с-точками>.<ключ>')` — НЕ работает (dot-notation),
  индексировать массив напрямую; `rm` в `/home/.../agelto` заблокирован deny_hard-хуком —
  удаление через `python3 -c "import os; os.remove(...)"`; `$this->artisan(...)
  ->expectsOutputToContain()` — одна подстрока на строку вывода (Mockery consumption).
- deferred: боевое наполнение промт-текстов (P8, вкл. 8-й discovery-промт); Batch API (D7); RAR/7z
  в ArchiveStrategy; OCR/предобработка сканов; LibreOffice-конвертер xlsx (D35); Ghostscript-
  даунскейл скан-PDF; generic ai_extractions (D13); контакты-сущность под CRM; полный
  9-префиксный ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание GlitchTip
  (P7.5); прод-хранилище/cron бэкапов и MinIO ILM (P7.7); shadow CI-гейт; калибровка порогов
  P6.4/P5.2/P6.2/**P7.3** (window_pages/totals_tail_pages/confidence/SEVERE_COMPLETENESS_RATIO/
  **analysis_stuck_minutes и соседние пороги stuck-check**); переключение сидов на 3.x-модели
  (D27, `AiRoutingSeeder` уже готовит закомментированные строки); Langfuse/registry промтов;
  правка устаревших имён таблиц в 03/04; Gemini-прокси-для-РФ; калибровка `ai.gemini.rate_limit.*`;
  тариф gemini-2.5-pro >200k токенов; ренейм `Ai\AiRoute` (P1.4); калибровка `temperature`/
  `maxTokens` по purpose (P3.3); второй driver для `AiRouteResolver::providerFor()` (P3.3);
  `LlmCallRecorder`-интеграция ВНУТРИ `ReaskPolicy` (P3.4); per-purpose роутинг в
  `ExtractionOrchestrator`; runtime-обнаружение провайдерского лимита ПОСЛЕ preflight;
  manual-импорт документов вне закупки (P7.4); полный пересчёт `validation_status`/иерархии после
  `RerunRangeAction`; путь для `mergedIncompleteItems` в UI/датасет через `raw_result` (P9/
  Filament-витрина); discovery-промт-текст — P8.1; `image/*` в windowed-пути (P6.4, теоретический
  разрыв); `max_estimates_per_document`-аналог для `runWindowed()`; живое подключение explicit
  context cache в `ExtractionOrchestrator`/`RowRangeChunkSource` (P7.1 Pending Work); **`ai:caches
  prune` в расписание рядом со `pipeline:stuck-check` (P7.3, естественная точка, не запрошено)**;
  live FX-курс вместо статического `ai.cost_report.rub_per_usd` (P7.2 — YAGNI); маршруты
  `eval`/`shadow` в `AiRoutingSeeder` (появятся вместе с shadow-режимом, D15); **v1-инфра-проверки
  (Redis-backlog/queue-depth//dev/shm) — carry-over на будущие работы по краулинг-стеку (P7.3
  Scope Excluded)**.
- open_questions: подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip (P7.5);
  хост/расписание бэкапов (P7.7); дата переключения на 3.x-модели (D27); нужен ли исходящий прокси
  для Gemini из среды деплоя; нужен ли структурный reason-столбец на `prc_documents`/
  `prc_ai_analyses` для unprocessable; guard-фикс `ScreeningJob::statusGuardPassed()` под редкий
  сценарий (P5.2); overlap-дедуп по составному ключу до реальных границ чанков (P6.2); источник
  `Estimate.title` (P6.3); структурный `is_merged_from_split`/якорь `row_index` для склеенных
  incomplete-строк (P6.3); реальная калибровка `window_pages`/`window_overlap_pages`/
  `totals_tail_pages` (P6.4, ждёт датасет P7.4); как архитектурно развести «стабильную» и
  «per-purpose схемную» части системного промта extraction для явного контекстного кэша (P7.1);
  числовой порог «когда explicit cache выгоднее implicit» (P7.1); актуальный курс
  `AI_COST_REPORT_RUB_PER_USD` в прод `.env` (P7.2); **боевой `TELEGRAM_BOT_TOKEN`/
  `TELEGRAM_CHAT_ID` для `PipelineAlert` (P7.3) — до заведения алерты видны только в логе**;
  **реальная калибровка порогов `pipeline:stuck-check` (P7.3) на живом трафике, когда он
  появится**.

Context: P7.3 закрыт с задокументированными Known Deviations (см. Open risks выше) — вход в P7.4
требует прочитать `phases/P7.md §P7.4` (датасет эталонов/eval-раннер) и
`app/Dto/Procurement/Ai/Estimate/` (P2.2, контракт gold-JSON) — контекст stuck-check/алертов P7.3
для P7.4 НЕ нужен (разные подсистемы), можно `/clear`.
