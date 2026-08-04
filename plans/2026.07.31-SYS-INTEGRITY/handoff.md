# HANDOFF — 2026-08-04 — after P3

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P3.1

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | Исполнить P3.1: машинный инвентарь `app/**`, правило размещения, таблица адресов 40 enum'ов, классы A/B, предложение гейта владельца |
| Команда | `/task:plan-run 2026.07.31-SYS-INTEGRITY P3.1` (Exec=manual; `plan-run` наследует маршрут сессии — перед запуском `/model opus` + `/effort high`, D18) |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P3.1
```

**Сделано этим заходом (ре-дизайн P3.1, `plan-design <ID> Pn.m`, opus/high, 2026-08-04).**

Items НЕ закрывались — заход правил ТЗ. Фактура фазы P3 пересчитана на дереве исполнения `4b628a8`
и вынесена в слой 1: `findings/P3-layout-facts-2026-08-04.md` (8 разделов, каждое число со своей
командой). Числа §9 разведки (`8f2ccdb`, снято ДО исполнения P1/P2) в items'ах P3 больше не
цитируются — **D112**:

- enum'ов в корне `app/Enums/Procurement` — **40**, не 38 (P1.2 `8194a7f` завела `ProxyScope` и
  `ProxyFailureReason` ровно по D17);
- поверхность переноса — **353** файла, не ≈333; миграций к правке — **13** (9 с `use` + 4 только с
  комментарием), не 9; строковых ссылок — **7 вхождений в 6 файлах**, не «единственный комментарий»;
- **дыра в шаблоне грепа:** два вхождения экранированы (`App\\Enums\\Procurement\\RunMode` в
  `2026_07_29_100059`), и шаблон `App.Enums.Procurement` — тот, что стоял в Validation P3.1 и в
  встречном грепе §9.3 — их НЕ ловит. Все проверки фазы переведены на
  `-E 'App\\+Enums\\+Procurement'` (проверено пробой: вложенную форму не флагует, экранированную
  ловит); P3.2 получил детектор плоского FQN с `[^A-Za-z\\]` на хвосте;
- `phpstan-baseline.neon` — **62** записи, не 74; хардкод числа снят в P3.2/P3.3, инвариант стал
  «значение не изменилось относительно снятого ДО item'а»;
- файлов доков/скилов со ссылками — **13**: `docs/03_Dev/database.md` дописан в `Files` P3.5 (без
  этого его правка была бы диффом вне `Files`, а без правки не сошлась бы собственная Validation).

Поitem'ные усиления P3.1: правило обязано (1) назвать границу относительно **ADR-0006** — тот
предписывает strangler с фасад-алиасами, но для ОТКЛОНЁННОГО ренейма в bounded contexts, а не для
выравнивания подкаталогов внутри неизменного корня `App\Enums\Procurement`; без этой границы
исполнитель P3.2 получает законный триггер эскалации «rule conflict» против D31; (2) отвечать про
малые плоские слои (`app/Enums/Llm` — 5 файлов, `app/Enums/Organization` — 1,
`app/Models/Reference` — 4), иначе гейт P3.4 либо краснеет на легальном коде, либо не судит ничего;
(3) закрываться ПРЕДЛОЖЕНИЕМ гейта, а не ожиданием ответа владельца (нет ответа → строка
`Decision pending` в `open-questions.md`, P3.3 получает форму `waiting:`).

**DoR P3.1 — пройден:** 0 маркеров `[NEEDS CLARIFICATION]`; Scope Included/Excluded фазы заполнены;
альтернативы несущих решений — в D-логе (D28/D30/D31, теперь D112); развилки `## Обсуждение` несут
статусы; несущие факты несут RAG-маркеры (`RAG:—` repo-grounded с файлом и командой); §3 Routing
покрывает P3.1 построчно (`frontier/high | manual | full` — открытое design-решение, тир оправдан);
оркестрация не декларируется (items фазы связаны последовательно — критерий `plan-protocol` §7 не
срабатывает); `roadmap.md` обновлён точечной правкой.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна (все 13 items закрыты, 8 из них 🟠).
- **P3 0/5** ⬜ Not started — ТЗ P3.1–P3.3/P3.5 уточнены этим заходом, статусы не менялись.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Следующая фаза — **P3**, следующий
  item — **P3.1** (`Exec=manual`, frontier/high).
- После P3.1 — гейт владельца (список слоёв класса A в P3.3), затем батч **B2** = P3.2–P3.3
  (`run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P3.2-P3.3`, implementation/high).
- **`## Phase Handoff` фазы P2 не собран** («—») — bookkeeping закрытия фазы; носитель —
  `close: task:plan-close 2026.07.31-SYS-INTEGRITY P2`. Не блокирует старт P3.
- Десять pre-existing красных тестов (партиция `prc_events` под даты корпуса эталонов) — вне Scope
  P2/P3, follow-up вне плана.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/plan.md` — Meta (v0.20.0), §1 Context (40 enum'ов), §3 Routing
  (строка P3.1), §4 Status Board, D112, Update Log.
- `plans/2026.07.31-SYS-INTEGRITY/phases/P3.md` — ТЗ P3.1 целиком (11 Implementation Rules,
  Validation с рабочими шаблонами грепов) и правленые P3.2/P3.3/P3.5.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P3-layout-facts-2026-08-04.md` — числа и команды на
  `4b628a8`; приоритетнее чисел §9 `repo-recon-2026-07-31.md`.
- `docs/03_Dev/adr/{0001-prc-megadomain,0006-bounded-contexts-as-folders}.md` — границы, за которые
  правило размещения не выходит.

**Open risks:**

- Фактура снова уедет, если P3.1 стартует не сразу: за четыре дня между разведкой и этим заходом
  разъехались семь чисел из восьми, а `app/Repositories/Procurement` сам обзавёлся подкаталогом.
  Митигация зашита в ТЗ: все числа снимаются на дереве item-коммита, findings — точка сверки, а не
  источник для цитирования.
- Расхождение ADR-0006 («strangler, никогда big-bang») и D31 (один коммит, шимы запрещены) снято
  ТЕКСТОМ ТЗ, но канон ADR не правился — если P3.1 сформулирует границу иначе, чем правило 9
  предполагает, исполнитель P3.2 всё ещё может эскалировать «rule conflict». Это штатный путь
  (эскалация → D#), не дефект.
- Гейт владельца по списку класса A — календарный: P3.3 может уйти в форму `waiting:`.
- Незакоммиченный чужой диф в дереве (не тронут): `M .env.example`, `M CLAUDE.md` · untracked
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife.json`,
  `.swissknife/`, `.swissknifeman/`, `plans/**/.claude/`, `plans/**/.swissknife/`. Состав снимать
  `git status --porcelain` перед каждым item-коммитом; `git add -A` запрещён.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker-compose.yml:180` требует его через `${...:?}`;
  обход — `docker exec agelto_app …` вместо `docker compose exec …`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app …` вместо `docker compose exec …`; `git add -p` для
  файлов с посторонним дифом (`CLAUDE.md`).
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close P2`); типизированный носитель семантики
  кейсов `RowType` — OQ-6; переписывание текстов промтов сверх дедупликации — OQ-7 (D110);
  партиционирование `prc_events` под даты корпуса эталонов.
- **Open questions:** OQ-3, OQ-4 (`Exploration`), OQ-5 (`Decision pending`), OQ-6, OQ-7
  (`Exploration`) — `open-questions.md`.
