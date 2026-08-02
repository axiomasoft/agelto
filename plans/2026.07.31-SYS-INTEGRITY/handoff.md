# HANDOFF — 2026-08-02 — after P2.6

**Next:** ре-дизайн P2.6 — переписывание текстов провалило критерий и откачено; нужен пересмотр ТЗ
под найденный механизм, прежде чем тратить второй платный замер

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — пересмотр правила приёмки item'а по результату платного невоспроизводимого замера |
| Context | NEW SESSION — шаг-не-item |
| Суть | Переписать ТЗ P2.6 под две гипотезы механизма (§8.13), решить судьбу дефекта `PromptCanonSynchronizer` и носителя семантики `RowType`, задать правило приёмки повторного захода |

```
/task:plan-design 2026.07.31-SYS-INTEGRITY P2.6
```

**P2.6 закрыт 🟠 2026-08-02 — БЕЗ item-коммита** (в истории только bookkeeping): девять файлов
промтов обоих канонических корней переписаны под целевой скелет `research/03` §7, `version` 2→3
(критик 1→2), применён `ai:prompts:sync`, снят замер **M4 = EvalRun #13** — и критерий
непрегрессии его отверг. Текст откачен, как прямо предписывают Implementation Rules item'а.

**M4 (EvalRun #13, дерево `7d0f1a7` + дифф P2.6, контур идентичен M0₃/M3c):** 6/6 эталонов,
precision=0.5305, recall=0.2516, f1=0.2918, row_coverage=0.5323 (создан 03:36:02, завершён
03:41:50). `estimate:eval:compare --base=12 --candidate=13 --criterion=no-regression` →
**`reject`, пять причин**: holdout.f1 −0.0533, tune.row_coverage −0.1069, holdout.row_coverage
−0.4796, `EST-XLSX-KS2-001` −0.0604 f1, `EST-XLSX-KS2-003` −0.6229 f1.

**Почему это НЕ случай M2/M3c («внутри шума, код не откатывается»).** Все четыре диагностических
признака §8.11 проверены и ни один не сработал — и это не помогло: у P2.6 собственное, более
строгое правило приёмки, а профиль вызовов показал систематический сдвиг поведения, которого
признаки §8.11 не видят. **Вывод модели упал вчетверо** (`completion_tokens` 248 531 → 64 460),
вызовов 46 → 34, отказы `MAX_TOKENS` исчезли вовсе (3 → 0) — модель стала возвращать меньше
строк, отсюда провал `row_coverage` на 0.4796 по holdout при измеренном разбросе покрытия парного
baseline 0.0826. Механизм назван ДВУМЯ ГИПОТЕЗАМИ и намеренно не разделялся замерами (D23):
(а) усиленное «пустой ответ штатен и всегда лучше правдоподобной догадки» в `global`;
(б) императив «Включай ВСЕ строки таблицы» заменён описательным «Во фрагмент входят ВСЕ строки».
Полный разбор — `research/03-prompt-contract.md` §8.13.

**Дефект, вскрытый откатом (не Scope P2.6, follow-up).** `PromptCanonSynchronizer` гасит версию
только когда она НИЖЕ канона (`deactivateStale()`: `version < $latest`; `drift()` симметрично).
Откат канона ВНИЗ (файл вернулся на v2, в БД активна v3) не гасится ничем: композиция склеивает
ОБЕ версии — правила задваиваются, — а `ai:prompts:sync --check` рапортует «дрейфа не
обнаружено». Наблюдалось на всех девяти ключах. Рантайм приведён к канону штатным
`PromptStoreRepository::deactivate()`; фикс кода (`!==` вместо `<` в обеих половинах) + тест на
даунгрейд — вход follow-up-item'а.

**Сделано этим заходом:**

- `resources/ai/prompts/**` + `resources/estimates/ai/prompts/**` — девять файлов переписаны,
  замерены и **откачены** (`git status --porcelain resources/` пуст, дерево = `7d0f1a7`).
  Отклонённая редакция — `storage/app/p26-rejected/` (gitignored, вне диффа).
- `research/03-prompt-contract.md` — **§8.13** (объём текстов, числа M4, вердикт дословно, разбор
  по признакам §8.11, профиль вызовов, гипотезы механизма, дефект синхронизатора).
- `phases/P2.md` — P2.6 → `🟠 Done with deviations`, Completion Notes / Pending Work (4 пункта) /
  Known Deviations (6 пунктов), Phase Status.
- `plan.md` — примечание под Status Board, Update Log, `Last Updated`. Числитель доски не
  изменился (P2.6 не 🟢).
- `journal.jsonl` — старт + финал.
- **Вне плана, по прямому запросу владельца** (коммит `27d44ea`): `.claude/skills/pest-testing/`
  и `docs/03_Dev/process/testing.md` — рецепт записи графа Tia и почему `-d pcov.enabled=1` через
  `artisan test` не работает в принципе (дочерний процесс Pest не наследует флаги интерпретатора).
  Граф записан (`/var/www/.pest/tia/*/graph.json`, 717 КБ), обычный прогон снова идёт в TIA-режиме.

**Done:** **P2.1 🟢** (`ced2a4f`) · **P2.8 🟠** (`b51ce3a`+`b05235f`) · **P2.2 🟢**
(`19f9808`+`684ddfb`) · **P2.3 🟠** (`bcd7ad4`) · **P2.9 🟠** (`b667b3e`) · **P2.10 🟢** (`ed202ac`) ·
**P2.4 🟠** (`4a4ce75`) · **P2.5 🟠** (`eee4f64`) · **P2.6 🟠** (без item-коммита).

**Remaining:** 36 items из 55. Внутри фазы остался P2.7, но его Inputs ждут Deliverables P2.2–P2.6,
а P2.6 их не доставил — сначала ре-дизайн P2.6. Дальше по очереди D63: `P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.17.0; §3 Routing поitem'ный, строка P2.6 —
frontier/high/manual/full; §5 D1–D103; §4 Status Board — P2 3/10 🟢, фаза 🟡) · `phases/P2.md` ·
**`research/03-prompt-contract.md`** §7 (целевой скелет), §8.13 (M4 и разбор отказа), §8.11–§8.12
(правило чтения и прецедент P2.5) · `findings/P2-eval-baseline.md` §7 · `roadmap.md` · `index.md` ·
`open-questions.md` · `journal.jsonl`.

**Open risks:**

- **P2.6 не доставил Deliverables** — тексты промтов остались дореформенными; всё, ради чего
  фаза снимала второй носитель структуры, в текстах ещё не отражено.
- **Дефект `PromptCanonSynchronizer` живёт в коде** — любой откат версии промта вниз тихо
  задваивает правила в композиции при зелёном `--check`. Пока не починен, откат промта —
  операция, требующая ручной проверки активных версий.
- **Семантика кейсов `RowType` не имеет носителя** — схема печатает перечень значений без
  объяснения, докблок enum'а в модель не уходит с P2.5; сегодня это знает только текст промта.
- **Замер системного префикса после P2.6 не проводился** — условие пересмотра D101 остаётся
  открытым (мерить нечего: префикс откачен к исходному объёму).
- **PROMOTE-CANDIDATE: `AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (150с) недостаточен под бюджет P2.9** —
  оба прогона захода снова сняты с временным `-e …=300`.
- **PROMOTE-CANDIDATE: `vendor/prism-php` `Structured::handle()` не отображает
  `ContentFilter`/`Other`** finish reason — на M4 не воспроизвёлся.
- **Разово наблюдавшийся `Unable to cast value to a decimal.`** (`dataset_example #10`, M0₃) — на
  M4 не воспроизвёлся.
- **13 красных тестов полного сюита** (partition-drift `prc_events`/`llm_calls` — нет партиции под
  текущую дату) — вне предмета плана, зафиксировано прогоном записи графа Tia; фикс-предложение
  `phases/P1.md` P1.10 Pending Work.
- **Локальный MTU-фикс хоста** (`docker-compose.override.yml`, gitignored) — нужен для сети.
- **`implicit`-кэш системной части на `extraction:passport`/`extraction:rows` выключен сознательно**
  (D101).
- **Чисел по вариантам A и B у фазы не будет никогда** (D103 Consequences).
- **EvalRun #7/#8 незавершены** (`finished_at = NULL`) — в сравнение не подаются, не чистятся.
- **Известный флейк `bcsub()` на `EST-XLSX-KS2-001`** (P2.10 Pending Work).
- **Потолки вывода `gemini-3.1-flash-lite` и `gemini-3.1-pro-preview` `[UNVERIFIED]`** (D99).
- **Отдельная величина «дельта P2.3» утрачена навсегда** (D100 Consequences).
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (P2.8 Known Deviations).
- **Дешёвый size-gate слеп на легаси `.xls`** (P2.8 Scope Excluded).
- **DB частично гидрирована, НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=17,
  `prc_ai_eval_runs`=13. **Не запускать `migrate:fresh` до закрытия фазы P2.**
- **`EST-PDF-SCAN-001` (id 7 в манифесте фикстур)** в шестёрку обязательных gold НЕ входит.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — сопоставимо внутри P2, не с числами донора.
- **Деньги.** Этот заход — один полный прогон корпуса (34 вызова), результат отвергнут; повторный
  заход потребует ещё одного.
- **`rules_hash` инвалидирован и возвращён обратно** — правка текстов и откат меняли байты
  системной части дважды; живые кэши инвалидированы, это ожидаемая цена, не регрессия.
- **Канал проекта помечен вендором Legacy** — вход EOL-мониторинга, P2.7 фиксирует строкой риска.
- **Строка Update Log для `plan-audit P1` (заход 2) отсутствует** — вне скоупа.
- **A1 аудита P1 (Major) не закрыт**: потребление `ProxyRequirements->country` не покрыто
  дискриминирующим тестом — решение владельца.
- **`verify-citations.py` — находки вне диффа**; инструмента в `plans/` этого репозитория нет (D76).
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет фазы.
- **`(array) $provider->options` в `LlmRouteResolver::toResolvedRoute()` несёт ТОТ ЖЕ класс
  дефекта**, что был исправлен для `$route->options` в P2.9 — дормантно, PROMOTE-CANDIDATE.
- **`docker compose` на хосте**: `MINIO_ROOT_PASSWORD`-интерполяция падает — обходка
  `docker exec agelto_app` рабочая.
- **Незапланированные файлы в рабочем дереве**: `M .env.example`, untracked `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife/`,
  `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/.swissknife/` — чужой
  дифф из `plan.md` §2, не трогать. **Два последних лежат ВНУТРИ carve-out `plans/<PLAN-ID>/**` —
  `git add plans/<PLAN-ID>` затащил бы их в bookkeeping-коммит; коммитить только поимённые пути.**
  Плюс `storage/app/p25-backup/`, `storage/app/p26-rejected/` (отклонённая редакция P2.6, нужна
  следующему заходу) и `docker-compose.override.yml` — все gitignored.
  **`git stash` без путей запрещён — утащит чужой дифф.**
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется перед стартом P5.
  **P4.7 — точка невозврата по схеме**: P5 и P6 обязаны быть терминальны до него.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* `docker exec agelto_app` вместо `docker compose exec app` · временный
  `-e AI_GEMINI_REQUEST_TIMEOUT_SECONDS=300` для платных прогонов до постоянного bump'а
  (PROMOTE-CANDIDATE) · локальный `docker-compose.override.yml` для MTU VPN-хоста · ручное гашение
  осиротевших версий промта после отката канона (до фикса `PromptCanonSynchronizer`).
- *Deferred:* повторное переписывание текстов промтов с учётом §8.13 · фикс
  `PromptCanonSynchronizer` (даунгрейд канона) · носитель семантики кейсов `RowType` · замер
  префикса после следующей редакции (условие пересмотра D101) · переименование
  `PromptContextGenerator` под новую роль · чистка незавершённых EvalRun #7/#8 · строка Update Log
  для `plan-audit P1` захода 2 · A1/A2/A3/A6 аудита P1 · правка ТЗ P1.2/P1.3 (D20) · перенос слоёв
  класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) · положения (3)–(5) целевой
  модели хранения (D72) · переход транспорта ЕИС на XML/SOAP (D14) · переименование дисков (D53) ·
  судьба таблицы кэша (D75) · связь «выделенные узлы под конкретный профиль» (D80) ·
  `ProxyForm::COUNTRIES` (D86) · починка двух pre-existing `CoreKnowsNoDocumentTypeTest` (D86) ·
  partition-drift (P1.10 Pending Work) · миграция на Interactions API вендора (мониторинг) ·
  починка слепого size-gate на легаси `.xls` (P2.8 Scope Excluded) · статус `EST-PDF-SCAN-001` ·
  решение о диспетче `PrepareDocumentJob` из `estimate:gold:import --prepare` (P2.8 Pending Work) ·
  деградация оконного пути при MAX_TOKENS (P2.9 Scope Excluded) · потолки вывода `gemini-3.1-*`
  (`[UNVERIFIED]`, D99) · `(array) $provider->options` в `LlmRouteResolver` · дефект `bcsub()` на
  `EST-XLSX-KS2-001` (P2.10 Pending Work) · механический `reject` M2/M3c на разных эталонах
  (P2.4/P2.5 Pending Work) · bump `AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (PROMOTE-CANDIDATE) ·
  пробел Prism `unhandled finish reason` (PROMOTE-CANDIDATE) · вероятностный decimal-cast на
  `dataset_example #10` · MTU-раннбук для разработчиков с похожим VPN.
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2 закрыт** (D103) · OQ-3 (источник истины ЕИС,
  снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый контур, сверяется в P7.8).
