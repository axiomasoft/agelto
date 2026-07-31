# HANDOFF — 2026-07-31 — after P7.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — Routing `implementation/high`, `Exec = manual` |
| Context | continue (/clear) — ручной item |
| Суть | P7.3 — ревизия нормативной спеки `docs/specs/v2/08-estimate-extraction.md` целиком (+ точечная сверка `01-database.md`/`04-llm-providers.md`) против фактов всех фаз P1–P6 |

**ЗАПУСК ВРУЧНУЮ: sonnet/high** (Routing P7.3 = `implementation/high` · `manual` — выше пина
`task:plan-exec` implementation/medium; либо `/model sonnet` + `/effort high` +
`/task:plan-run 2026.07.28-EST-ARCH P7.3`, D18):

```
Исполни P7.3 плана 2026.07.28-EST-ARCH строго по ТЗ phases/P7.md (Required Reads → Implementation
Rules → Validation → закрытие полным протоколом plan-exec.md §8). Cold-start: plan.md (Context,
Execution Rules, §3 Routing, Status Board), handoff.md, phases/P7.md (Phase Context + P7.3 целиком).
```

**Done:** фазы P1–P6 закрыты как в предыдущем handoff'е (все `🟠 Done with deviations`, P6
терминальна и аудирована — вердикт ATTENTION, продукт цел). Батч B4 (P7.5+P7.6) закрыт 🟢🟢
(предыдущая сессия). **Батч B2 (P7.1+P7.2) закрыт этой сессией:**
- **P7.1 (`6837f7d`, bookkeeping `6610bf8`) 🟠.** `estimate:dataset:export` несёт РЕАЛЬНЫЙ текст
  представления во входе (`DatasetInputBuilder` — единый строитель SFT/DPO), основание доверия и
  ВСЕ причины пропуска (`not_trusted`/`no_textual_representation`/`input_too_large`/
  `no_analysis_id`/`no_parent_analysis`/`parent_result_empty`) считаются в манифесте. DPO резолвит
  предшественника через СОХРАНЁННЫЙ `analysis_id` записи (миграция `2026_07_31_100060`, ALTER,
  D56), а не через активный анализ версии (чинимый дефект — доказано тестом с ДВУМЯ gold-версиями
  и разными предшественниками). Новые опции `--split=all|tune|holdout`, `--source=*`,
  `--dpo-schema=conversational|standard`. Десять обязательных Pest-кейсов зелёные (10/50);
  `GoldExampleRecorderTest`/`DatasetImportTest` зелёные БЕЗ правок. `phpstan` — `[OK]`, baseline не
  тронут. Боевые прогоны дали «Экспортировано 0» — корпус пуст в этой среде (D13), корректно по
  построению (Pending Work). **Known Deviations (🟠):** item-коммит несёт дифф вне буквального
  `Files` (`DatasetExporter.php`/`DatasetExampleReadRepository.php` — единственные фактические
  носители логики после P3.3, текст `Files` устарел постфактум); `GoldExampleRecorderTest.php`
  заявлен как «(дополнение)», но НЕ правился — более специфичная инструкция того же ТЗ прямо
  требует оставить его зелёным без правок.
- **P7.2 (`8dd6890`) 🟢.** Предсказание D41 ПОДТВЕРЖДЕНО фактом кода: `WorkingCopyProvisioner::
  provision()` — ЕДИНСТВЕННАЯ реализация (грep = 1 совпадение), оба вызывающих
  (`estimate:gold:import`, действие «Прогнать» P5.2) зовут её напрямую — эскалация не
  понадобилась. Докблок дополнен именами обоих вызывающих; новый тест `FixtureRunTest` доказывает
  единство пути по `sha256` (7 + 26 passed). `phpstan`/`pint` зелёные, baseline не тронут.
  Остаточная дупликация `estimate:dataset:import` НЕ закрыта (D57, сознательно вне Scope) —
  названа в Pending Work.

Оба item'а исполнены `sonnet/high` — ровно Routing (`Exec = manual`), перебора тира нет.

**Remaining:** **P7 — 3/6 🟢 (+1 🟠), фаза `🟡 In progress`.** Порядок исполнения: ~~B4~~ ~~B2~~
**→ P7.3 → P7.4**. Реконсилирующее закрытие фазы P4 всё ещё не выполнено (наследуемый риск, P7 не
блокирует).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules, D140), §3 Routing, §5
(D1–D140, включая D41/D56/D57) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`,
`root/document-contour.md` (§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`,
`root/service-layer-map.md`, `root/usage-source-contract.md` · `phases/P6.md` (терминальна) ·
`phases/P7.md` (P7.1/P7.2/P7.5/P7.6 Completion Notes, оставшиеся P7.3/P7.4) ·
`docs/specs/v2/08-estimate-extraction.md` (целиком — предмет P7.3) · `docs/specs/v2/01-database.md`,
`docs/specs/v2/04-llm-providers.md` (точечная сверка P7.3) · `research/01-target-architecture.md` §8
(coupling-таблица, D15) · `findings/inherited-debts-2026-07-29.md` §2 · `roadmap.md` ·
`open-questions.md` (OQ-15, OQ-18).

**Open risks:**
- **PROMOTE-CANDIDATE владельцу — правило `Files` ⊇ `Validation` в скилл `plan-protocol`** (D140).
- **`Files` двух items P7 (P7.1) устарел постфактум P3.3** — при заходе `plan-design`/`plan-close`
  по фазе P7 стоит сверить P7.3/P7.4 на тот же класс дрейфа текста ТЗ vs факт кода.
- **Остаточная дупликация `estimate:dataset:import`** (`DatasetImportCommand.php:178-236`, D57) —
  собственная копия создания рабочей копии, НЕ сведённая к сейму `WorkingCopyProvisioner`;
  объединение — новый D# и отдельный item, не расширение P7.2.
- **F1 EST-ARCH P6.1 не перемерян боевым прогоном** (D52) — боевой замер — P7.6 плана
  `2026.07.26-EST-TRAIN`, гейт владельца.
- **OQ-18 — расширять ли словари §2.1/§2.2 на четыре шага извлечения** (открыт D137, текстом закрыт
  P7.5). Решается числом `estimate:eval:compare` на holdout; ни один item плана от решения не
  зависит.
- **Единственный числовой бюджет сюита меньше боевой системной части в 3.5 раза** (F5 аудита P6) —
  правится вместе с пере-замером F1.
- **`EvalDiffCommand::renderMarkdown()` не показывает таблицу сопоставления** (P6.2).
- **Живой дефект `Section::$section_number`** (найден P6.3, НЕ починен) — follow-up item.
- **Первая непустая DPO/SFT-выгрузка не продемонстрирована** (P7.1 Pending Work) — корпус пуст в
  этой среде; снимается отдельным прогоном после первого approve через админку, вне скоупа P7.1.
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
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app` (compose здесь не
  поднимается из-за отсутствующей `MINIO_ROOT_PASSWORD` — контейнеры уже подняты отдельно);
  резолв `task.task_contract`/`task.route_evidence`/`plan-lint.py`/`journal.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98); правка файлов, созданных
  контейнером, — через `docker exec … chgrp 1000 && chmod g+w`.
- deferred: пере-замер F1 (P7.6 EST-TRAIN); расширение объявления словарей на четыре шага (OQ-18);
  сведение двух точек валидации профиля (OQ-15); чистка `AiRoutePermission`↔`LlmRouteResource`;
  Policy-покрытие каталога прав под `--strict`; предсуществующий `plan-lint` ERROR на
  `phases/P4.md:481`; реконсилирующее закрытие фазы P4; таблица сопоставления в markdown-выводе
  `estimate:eval:diff`; дефект `Section::section_number` (P6.3); классифицированный остаток
  `phpstan-baseline.neon`; бюджет `PromptCompositionTest` (F5); первая непустая DPO/SFT-выгрузка
  (P7.1); объединение `estimate:dataset:import` с сеймом рабочей копии (P7.2/D57).
- open_questions: OQ-18 без изменений (`Decision pending`, владелец); OQ-15 без изменений (`Decision
  pending`); вне плана — OQ-14 (дефект компилятора Task Contract) и PROMOTE-CANDIDATE D140 в
  `plan-protocol`.
