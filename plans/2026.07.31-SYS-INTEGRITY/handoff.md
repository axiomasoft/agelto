# HANDOFF — 2026-08-04 — after P3.1

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P3.2-P3.3

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — массовая правка 353 файлов + применённые миграции, цена ошибки — красный сюит |
| Context | continue (/clear) — ручной item |
| Суть | Батч B2: разложить 40 enum'ов по подкаталогам таблицы `research/04` §3 (P3.2), затем тем же приёмом три утверждённых D113 слоя класса A (P3.3) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P3.2-P3.3
```

**Гейт владельца пройден — D113 (2026-08-04).** P3.3 вышел из формы `waiting:`: утверждены три слоя
класса A (`app/Exceptions/Procurement`, `app/Dto/Procurement`, `app/Repositories/Procurement`);
`Services/Procurement`, `Services/Llm`, `Attributes/Ai` — НЕ входят. `FinishReason` переносится в
`App\Enums\Llm\` силами P3.2 (ИСКЛЮЧЕНИЕ-2 снято). Батч B2 исполняется группой, как предписывает
`roadmap.md` §B2; глубина ревью батча — `full` (максимум риска участников).

**Сделано этим заходом (P3.1, `plan-run`, opus/high, 2026-08-04).**

Коммиты: item **`1fc3b8b`** · bookkeeping `3216414` · чинящий `c3816a5` (вернул из индекса четыре untracked-файла инструментов каталога плана — Known Deviations P3.1). Item-коммит — три файла, `app/**` не тронут (`git show --stat 1fc3b8b -- app/` пуст):

- `research/04-domain-layout.md` — 7 разделов: инвентарь, правило, таблица адресов 40 enum'ов,
  классы A/B, семь строковых вхождений, проект гейта P3.4, гейт владельца;
- `artifacts/inventory.sh` + `artifacts/app-layer-inventory.json` — машинная фактура и её команда
  (`--check` сверяет JSON с деревом и краснеет на дрейфе).

**Правило размещения (раздел 2).** Доменный уровень обязателен для бизнес-кода (четыре домена,
ADR-0001 + D22); подобласть выражается подкаталогом, который заводится вместе с ПЕРВЫМ её файлом —
число файлов критерием не является; в корне доменной папки легальны только ядровая подобласть
домена и кросс-подобластные классы. Выведено из `app/Models/Procurement` (14 файлов в корне — все
закупка + `OperationLog`, ни одного из пяти подкаталочных подобластей), сверено с `Dto`, `Services`,
`Filament`. Формальная форма — три инварианта: **I1** доменный уровень, **I2** namespace↔путь и
словарь имён подобластей, **I3** реестр root-legal классов выровненного слоя.

**Граница относительно ADR-0006 названа явно** (раздел 2.3): ADR отклонил ренейм в bounded contexts
и предписал strangler ИМЕННО ему; здесь корень namespace не меняется, а механизмы, ради которых ADR
запрещал big-bang, к слою enum'ов не подключены (morph maps в проекте нет вовсе, policy-резолв идёт
от `App\Models\**`, Filament discovery — от `app_path('Filament/Resources')`). **Исполнителю P3.2:
конфликта ADR-0006 ↔ D31 нет, эскалация «rule conflict» на этой паре не обоснована.**

**Числа (item-коммит `1fc3b8b`; дерево `app/**` = `aa5e88d`).** 40 файлов в корне слоя enum'ов ·
353 файла поверхности переноса · 9 миграций с `use` / 13 всего · 7 строковых не-`use` вхождений
(два экранированы `App\\Enums\\…`) · 62 записи baseline, на `app/Enums/**` — ноль · 13 файлов
доков/скилов · инвентарь: 542 php-файла, 20 доменных папок слоёв, 116 подкаталогов, 27 каталогов
технической оси. Все восемь чисел `findings/P3-layout-facts-2026-08-04.md` (дерево `4b628a8`)
пересняты своими командами и совпали до единицы — дрейфа между ре-дизайном и исполнением нет.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна (все 13 items закрыты, 8 из них 🟠).
- **P3 1/5** 🟡 In progress — P3.1 🟢 Done (item-коммит `1fc3b8b`).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Текущая фаза — **P3**.
- **P3.2** (`Exec=manual`, implementation/high). Вход готов: таблица адресов `research/04` §3
  (40 строк, «не определено» нет), список строковых вхождений §5, шаблон грепов
  `-E 'App\\+Enums\\+Procurement'`. Строка 40 таблицы (`FinishReason` → `App\Enums\Llm\`)
  исполняется наравне с прочими — D113.
- **P3.3** (`Exec=manual`, implementation/high) — три слоя, утверждённые D113:
  `app/Exceptions/Procurement` (15 файлов), `app/Dto/Procurement` (4 файла корня — подобласть
  `Crawler`), `app/Repositories/Procurement` (13 из 16; 3 записи `path:` в baseline переносятся,
  не добавляются).
- **P3.4** — реестр I3 на момент закрытия P3.2/P3.3: 7 файлов корня слоя enum'ов из `research/04`
  §3 (временного исключения под `FinishReason` больше нет — D113) плюс корни трёх слоёв P3.3.
  Список исключений I1 обязан назвать
  `app/Models/User.php`, `app/Console/Commands/MaintainPartitions.php`, `app/Attributes/Ai/**` —
  иначе гейт красный с первого прогона.
- **`## Phase Handoff` фазы P2 не собран** («—») — bookkeeping закрытия фазы; носитель —
  `close: task:plan-close 2026.07.31-SYS-INTEGRITY P2`. Фазу P3 не блокирует.
- Десять pre-existing красных тестов (партиция `prc_events` под даты корпуса эталонов) — вне Scope
  P2/P3, follow-up вне плана.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/04-domain-layout.md` — правило размещения, таблица
  адресов, классы A/B, проект гейта. **Приоритетнее любых прежних формулировок раскладки.**
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/app-layer-inventory.json` + `inventory.sh` — машинная
  фактура; пересчитывается командой, руками не правится.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P3-layout-facts-2026-08-04.md` — точка сверки чисел
  (дерево `4b628a8`); приоритетнее чисел §9 `repo-recon-2026-07-31.md`.
- `plans/2026.07.31-SYS-INTEGRITY/plan.md` — §3 Routing (строки P3.2–P3.5), §4 Status Board, D112,
  Update Log; `phases/P3.md` — ТЗ P3.2–P3.5 и Completion Notes P3.1.
- `plans/2026.07.31-SYS-INTEGRITY/open-questions.md` OQ-8 — гейт владельца, `Decision pending`.

**Open risks:**

- **Периметр P3.3 закрыт D113 и расширению не подлежит.** `Services/Procurement`, `Services/Llm`,
  `Attributes/Ai` — класс A, но владельцем НЕ утверждены; их перенос «заодно» = материальное
  отклонение (D28). Класс B — основание отдельного плана, OQ-8 п.3.
- **`FinishReason` пересекает границу ДОМЕНА** — разрешено D113: целевой адрес
  `App\Enums\Llm\FinishReason`, переносит P3.2. Обратная сторона: это единственный файл батча,
  чей `use` меняет корень домена, — встречный греп по `App\+Enums\+Procurement` его больше не
  найдёт, полноту по нему проверять отдельно.
- **Две экранированные строки** (`2026_07_29_100059` строки 28–29) обычной заменой
  `App\Enums\Procurement\` не находятся — P3.2 обязан идти шаблоном `-E 'App\\+Enums\\+Procurement'`.
- **Фактура дрейфует сама:** между разведкой и ре-дизайном разъехались семь чисел из восьми, а
  `app/Repositories/Procurement` завёл подкаталог без правила. Между `4b628a8` и `1fc3b8b` дрейфа
  нет, но P3.2 обязан пересчитать перечень на СВОЁМ дереве, а не цитировать таблицу.
- Незакоммиченный чужой диф в дереве (не тронут): `M .env.example`, `M CLAUDE.md` · untracked
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife.json`,
  `.swissknife/`, `.swissknifeman/`, `plans/**/.claude/`, `plans/**/.swissknife/`. Состав снимать
  `git status --porcelain` перед каждым item-коммитом; `git add -A` запрещён.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker-compose.yml:180` требует его через `${...:?}`;
  обход — `docker exec agelto_app …` вместо `docker compose exec …`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app …` вместо `docker compose exec …`; `git add -p` для
  файлов с посторонним дифом (`CLAUDE.md`).
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close P2`); слои класса B и три не утверждённых
  D113 слоя класса A — отдельный план (OQ-8 п.3); типизированный носитель семантики кейсов `RowType` — OQ-6; переписывание текстов
  промтов сверх дедупликации — OQ-7 (D110); партиционирование `prc_events` под даты корпуса.
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; **OQ-8 закрыта D113** —
  `open-questions.md`.
