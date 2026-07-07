# Отчёт L — pre-execution аудит плана EST-AI №2 (2026-07-07, внешняя верификация perplexity-web)

Второй adversarial-проход по плану ПЕРЕД исполнением (первый — research/K). Фокус запроса владельца:
неточности, универсальность, надёжность, оптимизация алгоритмов, docker/infra под большой объём данных.
Внешние факты сверены через perplexity-web (ai.google.dev deprecations/pricing, prismphp.com, Packagist,
setasign, vLLM docs, GlitchTip, PhpSpreadsheet/mPDF issues, PG17/pg_partman/Redis best practices).
Вердикт: **ATTENTION** — 2 HIGH + 5 MED + 8 LOW; структура плана и декомпозиция здоровые, пересборка не нужна,
нужны точечные правки решений/items (plan-design).

## Findings

### HIGH

1. **[HIGH · D4/P7.2/P3.2/спека 08 · plan.md §5 D4] Вся стартовая матрица прибита к моделям с объявленным EOL 2026-10-16.**
   Официальная страница ai.google.dev/gemini-api/docs/deprecations (обновлена 2026-07-02): `gemini-2.5-flash`,
   `gemini-2.5-flash-lite`, `gemini-2.5-pro` — end of service **2026-10-16** (рекомендованные замены:
   3.1-flash-lite / 3.5-flash / 3.1-pro-preview). Research E зафиксировал отключение 2.0 (01.06.2026) и цены 3.x,
   но EOL 2.5 не заметил — D4 повторяет ошибку v1 («перенос v1-ID невозможен — 2.0 отключены»), закладывая в сиды
   модели, умирающие через ~3 месяца. Failure scenario: план исполняется/промты тюнятся до осени → 16 октября
   скрининг и извлечение падают всем потоком; хуже — SFT-датасет и eval-байзлайны накоплены на модели, которой нет.
   Fix (решение владельца, зафиксировать новым D#): (а) сидировать сразу 3.x-линейку (дороже: 3.1-flash-lite in $0.25/1M
   против $0.10) ЛИБО (б) стартовать на 2.5 (дёшево тюнить сейчас), но: записать EOL-дату в D4/config,
   добавить в P7.3 проверку «модель маршрута близка к EOL» (config-дата) и строку эскалации на 3.x в сиды P7.2.
   `PROMOTE-CANDIDATE`: анти-паттерн «пиновать model ID по pricing-странице без сверки deprecation schedule» —
   кандидат в правило verify-claims/llm-скиллы экосистемы.

2. **[HIGH · P5.1 (каскадом P6.4, D6) · phases/P5.md P5.1] `PdfPageSlicer` на setasign/fpdi не прочитает значительную долю реальных PDF.**
   Бесплатный парсер FPDI не поддерживает PDF ≥1.5 с compressed cross-reference/object streams (официальная позиция
   Setasign; нужен платный FPDI PDF-Parser). Современные генераторы (Гранд-Смета, принтеры ЕИС, MS Office) массово
   выпускают 1.5–1.7. Failure scenario: скрининг падает на резке первых N страниц у заметной доли документов →
   error-блок/failed_permanently на ровном месте; P6.4 (оконный fallback тем же слайсером) деградирует на самых
   больших документах — где он и нужен. Research E §3 сам называл канон «qpdf/pdftk/FPDI» — в item попал только FPDI.
   Fix: primary — `qpdf` CLI в worker-образ (`qpdf in.pdf --pages . 1-N -- out.pdf`; нативно читает 1.5+), PHP-обёртка;
   FPDI — убрать или fallback после нормализации (`qpdf --stream-data=uncompress --force-version=1.4`). Обязательная
   тест-фикстура: PDF 1.7 с compressed xref. Правка затрагивает P5.1 Files/Implementation Rules + Dockerfile-пункт (как gd/zip в P4.2).

### MED

3. **[MED · P7.6 · phases/P7.md] Надёжность очередей покрыта для деплоя, но не для сбоя: Redis без AOF.**
   `docker-compose.prod.yml`: `redis:7-alpine` без конфига — только дефолтные RDB-снапшоты. Crash/OOM Redis теряет
   jobs с последнего снапшота (до часа работы пайплайна) — требование «очереди не теряются» нарушается не деплоем,
   а первым же сбоем. Fix в P7.6 Scope: `appendonly yes` + `appendfsync everysec` (+ явный `maxmemory-policy noeviction`
   для queue-Redis); канон 2026 — AOF everysec + RDB для бэкапа.

4. **[MED · P7 (gap) · plan.md] Нет data-lifecycle и бэкапов — главный долгосрочный риск «большого объёма данных».**
   (а) pg_partman зарегистрирован без retention (миграция 100047): партиции `prc_ai_llm_calls/fetches/operation_logs/
   events/revisions` копятся вечно — bloat, autovacuum, разбухание бэкапов; (б) `request_ref`/`response_ref` — блоб на
   КАЖДЫЙ LLM-вызов (включая re-ask/shadow) в MinIO без ILM — миллионы файлов без политики; (в) бэкапы Postgres/MinIO
   вообще не в плане (volume ≠ бэкап). Fix: новый item (P7.7 «Data lifecycle & backup»): retention-окна per-таблица в
   part_config (+ явный список «хранится вечно под обучение»: gold, raw_result смет, LlmCall-метаданные без блобов),
   MinIO ILM-правила для request/response-блобов, pgBackRest (или хотя бы pg_dump-крон с проверкой восстановления).
   Дешёвая настройка autovacuum per-partition для hot-таблиц — туда же.

5. **[MED · P4.2 · phases/P4.md] SpreadsheetStrategy (PhpSpreadsheet+Mpdf) — OOM на больших xlsx.**
   Замеры сообщества: ~126MB RAM на 10k строк только на load(); сметы бывают 10k+ строк. OOM → SIGKILL воркера →
   retry-цикл до failed_permanently, попутно убивая соседние jobs супервизора. Fix: конфиг-гейт размера/строк xlsx
   (сверх — failed_permanently с внятной ошибкой ЛИБО fallback-конвертер), конверсия в отдельном процессе с явным
   memory_limit; стратегически — LibreOffice headless (`soffice --headless --convert-to pdf`) надёжнее по fidelity
   (сохраняет print-setup/merged cells; дефекты — сериализовать вызовы), ценой пакета в worker-образе. Минимум —
   зафиксировать выбор и лимиты в item (v1-урок landscape+fitToWidth сохранить в любом варианте).

6. **[MED · P5.2 · phases/P5.md] Порог confidence 0.6 → unknown → ignored: молчаливые false-negative.**
   Самооценка confidence у LLM плохо калибрована; реальная смета с confidence 0.55 уходит в терминальный `ignored`
   без следа для человека. «Всплеск unknown» в P7.3 ловит только массовые сбои, не хвост. Fix (дёшево): borderline-зона
   (например 0.4–0.6) — эскалация скрининга на модель классом выше (скрининг копеечный: ~1k ток.) либо отдельная
   пометка в result для выборочного операторского ревью (SOP в P10.4 уже есть куда вписать).

7. **[MED · процесс] Финальная версия плана не закоммичена.**
   `git status`: plan.md, все phases/*, research/{H,I,J,K} — modified/untracked. Потеря рабочего дерева = потеря
   результата четырёх итераций; cold-start по git log увидит старую версию. Fix: закоммитить plans/EST-AI/** до P1.1.

### LOW

8. **[LOW · D14/research G] Prism уже v0.100.x (не v0.91), typed-кэширование Gemini подтверждено — но два нюанса к P3.2.**
   Подтверждено (prismphp.com/providers/gemini): `$provider->cache(model, messages, systemPrompts, ttl)` → typed объект,
   использование через `withProviderOptions(['cachedContentName' => ...])`. Нюансы: (а) refresh/delete/list кэшей может
   не быть в публичном API — эскалационный пункт P3.2 «дописать тонкий HTTP-клиент» оставить в силе; (б) structured
   output в Prism — собственный Schema DSL (ObjectSchema/StringSchema...), а не сырой `responseJsonSchema`: генератору
   P2.3 нужен маппер JSON Schema → Prism DSL либо raw-passthrough через providerOptions — заложить в P3.2 Scope явно.

9. **[LOW · P6.1] Reuse file handle для документов ≤ N страниц — добавить проверку TTL.**
   Files API хранит 48ч; screened→extraction gap при бэклоге/паузе может превысить TTL. `LlmFileHandle.expiresAt`
   в контракте уже есть — оркестратор обязан проверять его перед reuse (одна строка в Implementation Rules).

10. **[LOW · P8.1/Д3] Implicit cache Gemini требует префикс ≥2048 токенов — скрининговая системная часть может не дотянуть.**
    Промты P8.1 сжатые; если системная часть скрининга <2048 ток., implicit-хитов не будет вовсе. По деньгам неважно
    (скрининг и так копеечный), но в спеке 08 §экономика не закладывать cache-скидку на скрининг.

11. **[LOW · D16/P9.4] Конфликты внешних данных по локальной LLM — писать диапазонами.**
    (а) объём SFT: research G «1–3k sweet-spot» vs внешние гайды 2026 «5k–50k» — истина зависит от узости схемы;
    (б) vLLM APC default: G «on по умолчанию в V1» vs другой источник «выключен, нужен --enable-prefix-caching».
    Обе темы вне исполнения плана; в docs-private/local-llm-training.md фиксировать диапазоны + «перепроверить на момент
    обучения», а APC включать флагом явно (не полагаться на дефолт).

12. **[LOW · P4.3] Мост — только поллер everyMinute: v1-слабость «латентность» переносится в v2.**
    Существующий вызов `PipelineDispatcher::dispatch($procurement)` из crawl-потока сохранить как прямой путь
    (afterCommit), поллер — страховка/бэклог. Одна строка в Scope P4.3.

13. **[LOW · P7.6] `stop_grace_period` у horizon-сервиса УЖЕ есть (docker-compose.prod.yml:70, 600s).**
    Item сформулирован как «добавить»; переформулировать в «выровнять с самым длинным job + конфиг-инвариант» —
    иначе исполнитель может продублировать/сломать существующее.

14. **[LOW · спека 08/P12.1] Indirect prompt injection из документов не упомянут.**
    Содержимое закупочных PDF — untrusted input; в спеку 08 и скилл llm-layer-dev добавить правило: extraction-вызовы
    без tools, системные правила не переопределяются текстом документа, подозрительные инструкции в документе → флаг.

15. **[LOW · P1.4/P8.1/P9.1] Правка применённых миграций против .claude/rules/migrations.md — гейт сформулирован как констатация.**
    «Ветка не в проде и данных нет» — записано, но не проверяется. Добавить в Implementation Rules явный чек перед
    правкой (окружения с применёнными миграциями = только dev/test; иначе alter-миграции) — риск уже назван в handoff.

## Верифицировано и СОШЛОСЬ (внешние факты плана)

- Files API: 48ч TTL, PDF ≤50MB/≤1000 стр., 258 ток./стр. (планировочная константа ок), page-range параметром НЕТ — резка на бэкенде обязательна (D6 верен).
- Structured output: responseJsonSchema = subset JSON Schema ($ref только «чистый», рекурсия ограничена non-required), сложная схема → 400, схема тарифицируется, MAX_TOKENS → невалидный JSON без авторемонта (finishReason-гейт P3.4 — правильный канон).
- GlitchTip: официальный sentry-laravel SDK со сменой DSN, ~4 контейнера vs 20+ у self-hosted Sentry (8–32GB RAM) — D25 верен.
- Pulse (Redis ingest), Context через очередь, horizon:terminate/pause-семантика, инвариант job timeout < supervisor timeout < retry_after, миф stopWaitingSeconds — research J подтверждён.
- vLLM: structured_outputs + xgrammar — рекомендованный путь, guided_json — legacy (D16 верен).
- laravel/ai v0.8.1, всё ещё 0.x — выбор Prism-за-фасадом (D14) обоснован; Prism v0.100.x, millions installs, 0 security advisories.
- Репо: миграции 100010–100047 на месте, pg_partman + `partitions:maintain` daily уже в scheduler, PipelineDispatcher-заглушка (:25), 8 Ai-моделей, спеки 00–07, Old/ и tmp/agelto*.md на месте; счёт 12 фаз / 39 items сходится; handoff.md достаточен для cold-start; PromptStep 7 значений = P8.1; все 24 находки K действительно вшиты в текущий текст items.

## Дополнение (2026-07-07, вводная владельца): гигантские документы / скан-PDF / провайдерские лимиты

Сценарий: документ 2–200 МБ, страницы — фото (скан-PDF), внутри несколько перемешанных смет.
Верифицированный факт: у Gemini лимит обработки PDF — **50 МБ / 1000 страниц НА ЗАПРОС, даже через Files API**
(upload до 2 ГБ пройдёт, generateContent вернёт INVALID_ARGUMENT). Токены при этом не проблема (258 ток./стр.
фиксированно, скан или текст — неважно); проблема — РАЗМЕР файла и отсутствие пути «паспорт без целого документа».

### Findings (продолжение нумерации)

16. **[HIGH · P6.1/P6.4 · phases/P6.md] Триггер fallback — только page_count; провайдерский size-лимит не проверяется нигде.**
    P6.1 «загрузка ПОЛНОГО подготовленного файла» упирается в 50 МБ: скан-PDF 150 стр. × 1.5 МБ не триггерит
    оконный режим (порог 300 стр.) и гарантированно фейлится на каждом ретрае, сжигая attempts. Fix:
    (а) **capability-матрица провайдера в config/ai.php** (max_request_file_mb, max_pages, max_inline_mb,
    supports_files_api) — расширение D14 с кэша на транспортные лимиты; (б) **preflight в оркестраторе ДО upload**:
    размер файла + число страниц (qpdf --show-npages / pdfinfo — qpdf уже нужен по finding 2) против матрицы
    актуального маршрута → выбор пути: целиком / окнами / unprocessable; (в) триггер P6.4 = предикат по
    (page_count ИЛИ file_size ИЛИ ошибка провайдера о лимите), не одна константа страниц.

17. **[HIGH · P6.4 · phases/P6.md] Оконный режим не имеет своего паспорта — мульти-сметный гигант неразбираем.**
    P6.4 гоняет по окнам только rows-контракт, а список смет/границы/rows_expected берёт из паспорта P6.1,
    которому нужен весь документ в контексте/кэше — для файла сверх лимита этого шага не существует. Fix —
    трёхстадийный windowed-путь (по духу tmp/agelto2.md, транскрибировать в спеку 08 §fallback):
    (1) **discovery-проход**: дешёвый вызов на окно (flash-lite-класс) «маркеры начала/конца смет + заголовки
    на этих страницах» → бэкенд детерминированно склеивает сегменты «смета k = страницы a..b»;
    (2) **per-estimate извлечение**: rows-окна внутри сегмента каждой сметы (тот же контракт, incomplete-буфер,
    stitcher); (3) **totals по последним страницам сегмента**. Паспорт-DTO для этого пути собирается бэкендом
    из discovery-результатов, а не одним LLM-вызовом. P2.2 дополнить контрактом discovery-ответа
    (маркеры: estimate_start/estimate_end/continuation, page, title?).

18. **[MED · P6.1 · phases/P6.md] Один job на весь разбор гиганта — таймаут-бомба; нужен checkpoint-requeue.**
    500-страничный документ = сотни LLM-вызовов последовательно в одном handle() — упрётся в job timeout /
    stop_grace_period при любом деплое. Progress/resume в P6.1 уже есть — дополнить канон «постепенной проработки»:
    после M окон/диапазонов ИЛИ T секунд job персистит progress и **re-dispatch'ит сам себя** (с задержкой),
    выходя успешно; ShouldBeUnique этому не мешает (lock снимается на завершении). Инвариант: любой одиночный
    запуск job ограничен по времени константой < job timeout; разбор любого размера = цепочка коротких запусков.

19. **[MED · P5.2/P6.1/P7.3] «Не можем обработать» — отдельный терминальный исход с причиной и алертом, не generic failed.**
    Требование владельца: упёрлись в лимит провайдера → сразу видно в системе. Сейчас это утонет в
    failed_permanently/last_error. Fix: taxonomy причин — enum `UnprocessableReason`
    (provider_limit_size / provider_limit_pages / unreadable_format / password_protected / zip_bomb / ...) +
    поле в analysis.result; статус документа `failed` с failure_reason ИЛИ отдельный `unprocessable` (решение
    plan-design — трогает enum DocumentStatus); P7.3 — алерт-триггер «новые unprocessable за окно» + счётчик в
    pipeline:status; P10.4 — SOP оператора «что делать с необрабатываемым документом»; unprocessable НЕ ретраится
    (это не сбой, это констатация) — attempts не жжём.

20. **[LOW · P4.2] Опциональный рычаг для скан-PDF: сжатие перед windowed-путём.**
    Ghostscript-даунскейл (150 dpi, jpeg) может ужать 200 МБ фото-PDF в разы — иногда возвращает документ в
    «целиком»-путь (кэш + row-range = дешевле и стабильнее окон). Не обязателен к реализации сейчас —
    зафиксировать в спеке 08 как задокументированный рычаг (вместе с Batch API D7), появится по профилю реальных данных.

### Сводный образ целевого поведения (для спеки 08 §алгоритм, диаграмма Д4)

```
preflight(file, route.capabilities)
├─ size ≤ lim && pages ≤ lim → ЦЕЛИКОМ: upload → [cache] → passport → row-range → totals   (P6.1, канон)
├─ сверх лимита              → ОКНАМИ:  discovery(окна) → сегменты смет → per-estimate rows(окна) → totals  (P6.4+)
│                              каждые M окон / T сек → progress + self-requeue job          (finding 18)
└─ не читается вовсе         → unprocessable(reason) + алерт + SOP                          (finding 19)
```
Оба пути: один Stitcher/Validator/Persister; incomplete-буфер в контракте обоих; мульти-смета через
ordinal (D12/D24) работает одинаково.

## Роутинг правок

HIGH-1/HIGH-2/HIGH-16/HIGH-17 + MED-3..6, MED-18, MED-19 — правки Decision Log и текстов items →
`/task:plan-design EST-AI` (пятая итерация, точечная); MED-7 — просто закоммитить; LOW — вписать той же
итерацией plan-design одним проходом. Findings 16–19 требуют: новое D# (capability-матрица транспортных
лимитов + preflight + checkpoint-requeue + unprocessable-таксономия), правки P2.2 (discovery-контракт),
P6.1/P6.4 (переработка триггера и windowed-паспорта), P5.2/P7.3/P10.4 (unprocessable-ветка), спека 08 §fallback.
