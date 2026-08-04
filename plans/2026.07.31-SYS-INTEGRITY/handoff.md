# HANDOFF — 2026-08-04 — after P3.2

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P3.3

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — та же механика на утверждённых D113 трёх слоях (Exceptions/Dto/Repositories), баланс переноса и path-baseline |
| Context | continue (/clear) — ручной item |
| Суть | Батч B2 (продолжение): перенести три утверждённых D113 слоя класса A по механике P3.2 |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P3.3
```

**Сделано этим заходом (P3.2, `plan-run`, sonnet/high, 2026-08-04).**

Item-коммит **`c25bf2e`** (285 файлов, +537/−535): 33 из 40 файлов корня `app/Enums/Procurement`
перенесены по таблице `research/04` §3 — `Crawler/` (3), `Proxy/` (2), `Document/` (4), `Ai/` (11),
`Estimate/` (12), `FinishReason` → `App\Enums\Llm\` (1, D113); 7 ядровых enum'ов остаются в корне
(`AwardStatus`, `BidStatus`, `EisProcedureType`, `ProcurementEventType`, `ProcurementStatus`,
`ProtocolType`, `OperationLogLevel`). Правка 353-файловой поверхности (`use`-импорты в `app/`,
`tests/`, `database/factories/`, двух сидерах, 13 миграциях включая экранированную форму, двух
конфигах) плюс `routes/console.php` (вне 353-файловой поверхности — грепы `research/04` §5 не
сканировали `routes/`, найдено полным прогоном сюита) и явный `use` в
`PipelineStage.php::fromAnalysisStep()` (implicit same-namespace ссылка на `AnalysisStep` сломалась
после разъезда по разным подкаталогам). `pint --dirty` (с хоста) причесал 32 файла форматированием
(`ordered_imports` + единичные pre-existing нарушения тех же файлов). Полные числа, разбор
Validation-клауз и Known Deviations — `phases/P3.md` P3.2 Completion Notes.

**Status:** 🟠 Done with deviations. Причины: (1) item-коммит несёт дифф вне `Files` item'а
(`routes/console.php`, находка полного прогона, не в поверхности research/04); (2) детектор
плоского FQN (Validation клауза 2) на `c25bf2e` даёт 105, не 0 — 63 легитимные ссылки на 7 ядровых
enum'ов + 32 собственные `namespace`-декларации перенесённых файлов (детектор не отличает их от
`use`) + 10 в `docs/**`/`.ai/**` (Scope Excluded — P3.5); ноль реальных непереехавших ссылок
подтверждено отдельным repo-wide грепом; (3) полный сюит — 969 passed / 10 failed / 1 skipped, все
10 pre-existing (партиции `prc_events`/`llm_calls`, права на запись `resources/**/ai/prompts`,
`memory_limit` контейнера, EIS-фикстура, канарейка-счётчик и коллизия имён `Estimate/` в
`Tests\Unit\Architecture\CoreKnowsNoDocumentTypeTest` — все сверены как красные уже на `HEAD` до
P3.2). phpstan (baseline 62, без изменений) и pint по файлам `Files` item'а — зелёные.

**Инцидент исполнения (устранён, без потери данных):** `git stash push -u` во время диагностики
упёрся в ACL-ограниченные каталоги `app/Filament/Resources/Procurement/Ai/Fixtures/**`
(`mask::r-x` для группы — рабочая группа `vostrikov` не может unlink внутри) и прервался частично,
откатив ~285 файлов к HEAD и удалив untracked чужой диф. Полностью восстановлено из `stash@{0}`
(включая untracked-дерево, третий родитель стэша) — сверено `git diff --name-only stash@{0} -- .`
до пустого перед коммитом, стэш дропнут. **Впредь не использовать `git stash` в этом дереве** —
`app/Filament/Resources/Procurement/**` и, вероятно, другие каталоги, созданные контейнером
(`www-data`), несут ACL с `mask::r-x`, блокирующий unlink для группы `vostrikov`; для точечного
отката файла — `git checkout <rev> -- <path>` (работает, unlink не требуется при перезаписи
существующего файла) или прямая правка, не `stash`/`reset --hard`/`checkout .` на весь working tree.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна (все 13 items закрыты, 8 из них 🟠).
- **P3 1/5** 🟡 In progress — P3.1 🟢 Done (`1fc3b8b`), **P3.2 🟠 Done with deviations (`c25bf2e`)**.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Текущая фаза — **P3**.
- **P3.3** (`Exec=manual`, implementation/high, батч B2 — та же серия). Вход готов: утверждённые
  D113 три слоя класса A — `app/Exceptions/Procurement` (15 файлов), `app/Dto/Procurement` (4 файла
  корня — подобласть `Crawler`), `app/Repositories/Procurement` (13 из 16; 3 записи `path:` в
  baseline переносятся, не добавляются). Механика — та же, что P3.2 (`phases/P3.md` P3.2
  Implementation Rules/Code Guidance, читаются по Required Reads P3.3). Переносить ПО ОДНОМУ слою с
  полным прогоном после каждого (Code Guidance P3.3).
- **P3.4** — реестр I3 на момент закрытия P3.2/P3.3: 7 файлов корня слоя enum'ов (без временного
  исключения `FinishReason` — перенесён) плюс корни трёх слоёв P3.3 (после их переноса).
- **`## Phase Handoff` фазы P2 не собран** («—») — bookkeeping закрытия фазы; носитель —
  `close: task:plan-close 2026.07.31-SYS-INTEGRITY P2`. Фазу P3 не блокирует.
- **Follow-up вне плана (не блокирует P3):** десять pre-existing красных тестов (партиции
  `prc_events`/`llm_calls`, права на запись `resources/**/ai/prompts`, `memory_limit` контейнера,
  EIS-фикстура `DetailAnalysisProcessorTest`) — все воспроизводятся на `HEAD` до P3, вне Scope P2/P3.
  Отдельно — `Tests\Unit\Architecture\CoreKnowsNoDocumentTypeTest`: канарейка-счётчик периметра
  «ядра» уже красная на `HEAD` (100≠98 до P3.2, 101 после — P3.2 добавляет легитимный
  `App\Enums\Llm\FinishReason` в уже сломанный счётчик) и коллизия имён между `Estimate/` (новая
  подобласть домен-модели, `research/04`) и `Analyzers\Estimate\` (ось типа документа, отдельный
  контракт P2/D14) — оба вопроса не Scope P3, решение — за владельцем контракта арх-теста.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/04-domain-layout.md` — правило размещения, таблица
  адресов, классы A/B, утверждённый гейтом список (раздел 7 + D113). **Приоритетнее любых прежних
  формулировок раскладки.**
- `plans/2026.07.31-SYS-INTEGRITY/plan.md` — §3 Routing (строки P3.3–P3.5), §4 Status Board, D113,
  Update Log; `phases/P3.md` — ТЗ P3.3–P3.5 и Completion Notes P3.1/P3.2.
- `plans/2026.07.31-SYS-INTEGRITY/open-questions.md` — OQ-8 закрыта D113.

**Open risks:**

- **Периметр P3.3 закрыт D113 и расширению не подлежит.** `Services/Procurement`, `Services/Llm`,
  `Attributes/Ai` — класс A, но владельцем НЕ утверждены; их перенос «заодно» = материальное
  отклонение (D28). Класс B — основание отдельного плана, OQ-8 п.3.
- **`app/Filament/Resources/Procurement/**` (и, вероятно, другие контейнер-созданные каталоги) несут
  restrictive ACL (`mask::r-x` для группы) — блокируют `git stash`/полнодеревные reset-подобные
  операции для пользователя `vostrikov`. Точечные `git checkout <rev> -- <path>` и прямые правки
  файлов работают штатно (write, не unlink+directory-op).
- Незакоммиченный чужой диф в дереве (не тронут): `M .env.example`, `M CLAUDE.md` · untracked
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife.json`,
  `.swissknife/`, `.swissknifeman/`. Состав снимать `git status --porcelain` перед каждым
  item-коммитом; `git add -A` запрещён.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker-compose.yml:180` требует его через `${...:?}`;
  обход — `docker exec agelto_app …` вместо `docker compose exec …`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app …` вместо `docker compose exec …`; `git add -p` для
  файлов с посторонним дифом (`CLAUDE.md`); `git checkout <rev> -- <path>` вместо `git stash`/
  `reset --hard` в дереве с ACL-каталогами.
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close P2`); слои класса B и три не утверждённых
  D113 слоя класса A — отдельный план (OQ-8 п.3); типизированный носитель семантики кейсов `RowType`
  — OQ-6; переписывание текстов промтов сверх дедупликации — OQ-7 (D110); партиционирование
  `prc_events`/`llm_calls` под даты корпуса; follow-up по `CoreKnowsNoDocumentTypeTest` (канарейка +
  коллизия имён `Estimate/`) — новый, вне плана.
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8 закрыта
  D113.
