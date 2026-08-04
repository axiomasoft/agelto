# HANDOFF — 2026-08-04 — after P2.7

**Next:** «ЗАПУСК ВРУЧНУЮ: frontier/high» — P3.1

| Параметр | Значение |
|:--|:--|
| Model | frontier (opus/fable) |
| Thinking | high — открытое design-решение: правило размещения выводится из противоречивой фактуры и замораживается arch-тестом на весь проект (§3 Routing) |
| Context | continue (/clear) — ручной item |
| Суть | P3.1 — первый item фазы P3 (доменная раскладка): зафиксировать правило размещения (класс A/B, объём необратимого переноса) |
| Команда | `—` (Exec=manual, `/task:plan-exec` пинит sonnet/medium и по гейту п.2 обязан отклонить этот item) |

```
/task:plan-design 2026.07.31-SYS-INTEGRITY P3.1
```

Либо: `/model opus` + `/effort high` + `/task:plan-run 2026.07.31-SYS-INTEGRITY P3.1` (D18).

**Сделано этим заходом (P2.7, plan-exec, sonnet/low, 2026-08-04).**

Доки/спека/скил/оба глоссария синхронизированы под фактическое разделение «схема/правила»
(P2.3–P2.5): `PromptContextGenerator` печатает в системную часть промта ТОЛЬКО словарь синонимов
`#[Alias]` (печать очищенного PHP-исходника снята вариантом C, P2.5) — структуру ответа (типы/
`description`/порядок) целиком несёт исходящая схема (`response_schema` через `Prism\Schema\
RawSchema`, читается `#[Description]`-атрибутом + `ContractAttributeReader`, P2.3/P2.4). Имя канала
исправлено с устаревшего `responseJsonSchema` на `response_schema` в
`docs/03_Dev/ai/prompts.md`, `docs/specs/v2/08-estimate-extraction.md` §6 (с пометкой «исправлено
P2.7») и в трёх доках ВНЕ `Files` item'а, где найдена та же неточность (`docs/03_Dev/architecture.md`,
`docs/03_Dev/recipes.md`, `docs/03_Dev/ai/feature-card-screening.md`) — без них Validation-грep по
всему `docs/`/`.ai/` не сходился в ноль. `docs/03_Dev/llm-providers.md` несёт строку риска EOL
(«Legacy»-пометка канала). `docs/03_Dev/ai/training-loop.md` несёт абзац «восстановление корпуса в
пустой БД» (D94, три команды по порядку + граница «git-SSOT пуст → эскалация»). Оба глоссария и
скил `prompt-engineering-agelto` (локальный, без `swissknifeman:managed`) дополнены. `CLAUDE.md` —
ОДНА строка перенесена точечным `git add -p` (в файле уже лежал посторонний дифф swissknifeman hub
sync — оставлен нетронутым).

Приёмочный прогон на item-коммите `529df8f`: тесты 969 passed / **10 failed** / 1 skipped (4057
assertions) — все 10 pre-existing (partition `prc_events` под дату корпуса + код домена, которого
диф-доков не касается); `pint --test` — 4 issue, все вне Scope (2 файла кода вне `Files`, посторонний
`config/cms.php`, `TestEnvironmentGuard.php`); `phpstan analyse` — 0 ошибок, baseline не вырос
(62=62). **P2.7 закрыт 🟠 Done with deviations** — (1) дифф вне `Files` (три доки), (2) Validation
«полный сюит зелёный» не выполнена буквально (обе причины — pre-existing/вне Scope, не от диффа
доков).

**Фаза P2 терминальна: 13/13 items закрыты (доска — 5/13 🟢, счётчик «🟢/всего» несёт только
зелёные; 8 items закрыты 🟠 Done with deviations).**

**Done:**

- **P2 5/13** 🟠 Done with deviations — фаза закрыта, ВСЕ 13 items терминальны (P2.1🟢 P2.2🟢
  P2.3🟠 P2.4🟠 P2.5🟠 P2.6🟠 **P2.7🟠** P2.8🟠 P2.9🟠 P2.10🟢 P2.11🟢 P2.12🟠 P2.13🟢).
- **P1 4/10** — фаза закрыта повторно `🟠` (без изменений в этом заходе).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7` — P1 и P2 терминальны, следующая фаза
  — **P3** (Доменная раскладка и arch-тесты).
- **P3.1** — первый item P3, `Exec=manual` (frontier/high, открытое design-решение о правиле
  размещения). Формально сначала может понадобиться `plan-close Pn` для P2 (bookkeeping фазы,
  Phase Handoff в `phases/P2.md` пуст — «—») — решение по порядку шагов за следующей сессией/
  владельцем.
- Десять pre-existing красных тестов (партиционирование `prc_events`/аналог под даты корпуса
  эталонов) — вне Scope P2, follow-up вне плана (владелец/`plan-design`).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/plan.md` — Meta, Routing §3 (строка P3.1), Status Board §4 (P2
  терминальна), Update Log (строка закрытия P2.7).
- `plans/2026.07.31-SYS-INTEGRITY/phases/P2.md` — P2.7 Completion Notes/Known Deviations (закрытие,
  последний item фазы); `## Phase Handoff` P2 всё ещё «—» (не собран этим item'ом — не входит в
  Scope `plan-exec`).
- `plans/2026.07.31-SYS-INTEGRITY/phases/P3.md` — P3.1 ТЗ целиком (следующий шаг).
- `docs/03_Dev/ai/prompts.md`, `docs/specs/v2/08-estimate-extraction.md` §6,
  `docs/03_Dev/ai/training-loop.md`, `docs/03_Dev/llm-providers.md` — синхронизированные доки.

**Open risks:**

- **Phase Handoff `phases/P2.md`** не собран (остаётся «—») — bookkeeping закрытия фазы (не
  item'а) обычно делает `plan-close`, не входил в Scope этого `plan-exec`-item'а.
- Десять pre-existing failing тестов (`SupplierResultsProcessor`×3, `CostReportTest`,
  `PromptApplyTest`×2, `TextGridRenderer…`, `CoreKnowsNoDocumentTypeTest`×2,
  `DetailAnalysisProcessorTest`) — партиция `prc_events` под `2026-07-15` отсутствует + код вне
  Scope P2 — фикс-предложение: партиционирование `pg_partman` под даты корпуса эталонов, follow-up
  вне плана.
- Незакоммиченный чужой диф в дереве (не тронут): `M .env.example`, `M CLAUDE.md` (частично —
  hub-sync хвост) · untracked `config/cms.php`, `database/seeders/User/`,
  `estimates/gemini-analys.md`, `.swissknife.json`, `.swissknife/`, `.swissknifeman/`,
  `plans/**/.claude/`, `plans/**/.swissknife/`. Состав снимать `git status --porcelain` перед
  каждым следующим item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker-compose.yml:180` требует его через `${...:?}`;
  обход — прямой `docker exec agelto_app …` вместо `docker compose exec …` (использован и в этом
  заходе).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app …` вместо `docker compose exec …` (обходит
  `MINIO_ROOT_PASSWORD`); `git add -p` для файлов с посторонним дифом (`CLAUDE.md`).
- **Deferred:** типизированный носитель семантики кейсов enum (`RowType`) — OQ-6; переписывание
  текстов сверх дедупликации — OQ-7 (D110); дефект `bcsub` на `EST-XLSX-KS2-001` и Prism
  `unhandled finish reason` — PROMOTE-CANDIDATE; партиционирование `prc_events` под даты корпуса
  эталонов (найдено этим заходом).
- **Open questions:** OQ-3, OQ-4 (`Exploration`), OQ-5 (`Decision pending`), OQ-6, OQ-7
  (`Exploration`) — `open-questions.md`.
