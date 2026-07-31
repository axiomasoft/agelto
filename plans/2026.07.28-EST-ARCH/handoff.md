# HANDOFF — 2026-07-31 — after P7.6

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — Routing `implementation/high`, `Exec = manual` (батч B2, roadmap.md) |
| Context | continue (/clear) — ручной item |
| Суть | Батч B2 (P7.1 + P7.2, одна сессия): экспорт датасета под дообучение локальной модели и ревизия единственности сейма рабочей копии эталона (D41); P7.2 может эскалировать (D57) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.28-EST-ARCH P7.1-P7.2
```

**Done:** фазы P1–P6 закрыты как в предыдущем handoff'е (все `🟠 Done with deviations`, P6
терминальна и аудирована — вердикт ATTENTION, продукт цел). **Батч B4 (P7.5+P7.6) закрыт этой
сессией:**
- **P7.5 (`f749ece`) 🟢.** Абзац §2 `docs/specs/v2/08-estimate-extraction.md` (строка 103) приведён
  к факту: шаг `extraction:rows` несёт оба словаря (`EstimateHeaderMap` — явным объявлением,
  тип ресурса — транзитивным замыканием `RowsChunkResponse → EstimateRow → RowResource`); три
  остальных шага извлечения (`extract_passport`, `extract_totals`, `extraction:discovery`) не несут
  ни того ни другого. Вопрос расширения на четыре шага остался в измеряемом **OQ-18** (уже был заведён
  заходом дизайна, D137) — этот item его не решает. Пункт 2 `Pending Work` P6.1 вычеркнут строкой
  «ЗАКРЫТО 2026-07-31 (`P7.5`, `f749ece`)» бухгалтерским коммитом (carve-out P7 Scope Excluded).
  Гейт D89 на `<base>` (`afcdaa6`) дал КРАСНЫЙ исход (строка 103 существовала) до первого коммита.
  `git diff afcdaa6..HEAD -- app/ resources/ config/ database/` пуст, периметр `docs/` — ровно один
  файл / 1+1 строка. `PromptComposition` — 8 passed (57 assertions), тест не правился.
- **P7.6 (`7a19f0c`) 🟢.** Таблица «Распределение по `identifier` — до/после»
  (`docs/03_Dev/static-analysis.md`) дополнена пропущенной строкой `argument.unresolvableType`
  (**1 → 0**, класс ушёл в ноль тем же рычагом `@property`, скрытого долга нет) и строкой итога
  (**Σ 240 / 74**, 18 identifier'ов) с командой суммирования; добавлена строка инструкции —
  дописывающий обязан сверять СУММУ колонки, а не только свою строку. `phpstan-baseline.neon`/
  `phpstan.neon` не тронуты (`git diff` пуст), `phpstan analyse` — `[OK] No errors` (526/526). Гейт
  D89 на `<base>` дал КРАСНЫЙ исход (пустой грep) до первого коммита. Обе перепрогнанные команды дали
  ожидаемые числа (16 строк/Σ240 «до», 17 строк/Σ74 «после») — независимо совпало с
  `research/07-audit-p6-followup-map.md` §3.

Оба item'а исполнены `sonnet/medium` — ровно Routing (`Exec = plan-exec`), перебора тира нет.
Material-отклонений нет у обоих (`Known Deviations: —`), эскалаций не было.

**Remaining:** **P7 — 2/6 🟢, фаза `🟡 In progress`.** Порядок исполнения: ~~B4~~ **→ B2 (P7.1+P7.2)
→ P7.3 → P7.4** (B4 закрыт этой сессией). Реконсилирующее закрытие фазы P4 всё ещё не выполнено
(наследуемый риск, P7 не блокирует).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules, D140), §3 Routing, §5
(D1–D140) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md` (§3.2/§3.5/§3.7/
§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P6.md` (Completion Notes P6.1–P6.3, `## Phase Handoff`,
`## Audit P6 — 2026-07-31`, P6.1 Pending Work п.2 — ЗАКРЫТ) · `phases/P7.md` (Phase Context, P7.5/P7.6
Completion Notes, оставшиеся четыре items) · `research/07-audit-p6-followup-map.md` ·
`docs/specs/v2/08-estimate-extraction.md` §2 (правлен) · `docs/03_Dev/static-analysis.md`
(правлен) · `findings/inherited-debts-2026-07-29.md` §2 · `roadmap.md` · `open-questions.md` (OQ-15,
OQ-18).

**Open risks:**
- **PROMOTE-CANDIDATE владельцу — правило `Files` ⊇ `Validation` в скилл `plan-protocol`** (D140).
  Внутри плана правило действует с 2026-07-31 (§2 Execution Rules); правка vendor-копии из
  потребителя запрещена — решение владельца, заводить ли issue в `swissknifeman`. План от решения не
  зависит.
- **F1 EST-ARCH P6.1 не перемерян боевым прогоном** (D52) — боевой замер — P7.6 плана
  `2026.07.26-EST-TRAIN`, гейт владельца.
- **OQ-18 — расширять ли словари §2.1/§2.2 на четыре шага извлечения** (открыт D137, текстом закрыт
  P7.5). Решается числом `estimate:eval:compare` на holdout; ни один item плана от решения не
  зависит.
- **Единственный числовой бюджет сюита меньше боевой системной части в 3.5 раза** (F5 аудита P6,
  носителя намеренно нет) — читается как граница, которой нет; правится вместе с пере-замером F1.
- **`EvalDiffCommand::renderMarkdown()` не показывает таблицу сопоставления** (P6.2).
- **Живой дефект `Section::$section_number`** (найден P6.3, НЕ починен) — follow-up item.
- **ACL каталогов, создаваемых контейнером** — `docker exec … chgrp 1000 && chmod g+w` (с P5.4/P5.5).
- **`guard:catalog:validate --strict` системно красный** — вне плана.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource`** — отдельный item.
- **Кнопка прогона требует `AI_SCREENING_ENABLED`** (дефолт `false`) — с P5.2.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94).
- Две точки проверки формы профиля — принятый долг D132, адресат OQ-15.
- **Реконсилирующее закрытие фазы P4 не выполнено** — не блокирует P7.
- **Предсуществующий ERROR `plan-lint`: `phases/P4.md:481`** — P4.5 закрыт 🟢 без строки
  `write-site: …`. Чинится при `plan-close` P4.
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Посторонний дифф в дереве (не мой, не тронут):** массовое удаление файлов каталога `Old/`
  (легаси v1-приложение) — по-прежнему unstaged `D`; изменённый `.env.example`; неотслеживаемые
  `.swissknife/`, `estimates/gemini-analys.md`, `config/cms.php`,
  `database/seeders/User/AdminUserSeeder.php`. Требует решения владельца ДО следующего коммита.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app`; резолв
  `task.task_contract`/`task.route_evidence`/`plan-lint.py`/`journal.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98); правка файлов, созданных
  контейнером, — через `docker exec … chgrp 1000 && chmod g+w`.
- deferred: пере-замер F1 (P7.6 EST-TRAIN); расширение объявления словарей на четыре шага (OQ-18 —
  ТЕКСТОВОЕ расхождение §2 закрыто P7.5, вопрос по существу остаётся); сведение двух точек
  валидации профиля (OQ-15); чистка `AiRoutePermission`↔`LlmRouteResource`; Policy-покрытие каталога
  прав под `--strict`; предсуществующий `plan-lint` ERROR на `phases/P4.md:481`; реконсилирующее
  закрытие фазы P4; таблица сопоставления в markdown-выводе `estimate:eval:diff`; дефект
  `Section::section_number` (P6.3); классифицированный остаток `phpstan-baseline.neon`; бюджет
  `PromptCompositionTest` (F5).
- open_questions: OQ-18 без изменений (`Decision pending`, владелец); OQ-15 без изменений (`Decision
  pending`); вне плана — OQ-14 (дефект компилятора Task Contract) и PROMOTE-CANDIDATE D140 в
  `plan-protocol`.
