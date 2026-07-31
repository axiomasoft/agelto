# HANDOFF — 2026-07-31 — after P7.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — Routing `implementation/high`, `Exec = manual` |
| Context | continue (/clear) — ручной item |
| Суть | P7.4 — документация обучающего контура (`training-loop.md`), операторский SOP (`docs/04_Operators/**`), перенос ADR формата входа в `docs/03_Dev/adr/`, свод дрейфа `CLAUDE.md` (D60) |

**ЗАПУСК ВРУЧНУЮ: sonnet/high** (Routing P7.4 = `implementation/high` · `manual` — выше пина
`task:plan-exec` implementation/medium; либо `/model sonnet` + `/effort high` +
`/task:plan-run 2026.07.28-EST-ARCH P7.4`, D18):

```
Исполни P7.4 плана 2026.07.28-EST-ARCH строго по ТЗ phases/P7.md (Required Reads → Implementation
Rules → Validation → закрытие полным протоколом plan-exec.md §8). Cold-start: plan.md (Context,
Execution Rules, §3 Routing, Status Board), handoff.md, phases/P7.md (Phase Context + P7.4 целиком).
```

**Done:** фазы P1–P6 закрыты как в предыдущем handoff'е (все `🟠 Done with deviations`, P6
терминальна и аудирована — вердикт ATTENTION, продукт цел). Батч B4 (P7.5+P7.6) закрыт 🟢🟢, батч
B2 (P7.1+P7.2) закрыт 🟠🟢 (предыдущие сессии). **P7.3 закрыт этой сессией:**
- **P7.3 (`35aaf3e`) 🟢.** `docs/specs/v2/08-estimate-extraction.md` сведён с фактами P1–P7: домен
  `Llm` (`llm_providers`/`llm_routes`/`llm_prompts`/`llm_calls`), снесённый `prc_document_types`
  (SSOT — `AiDocumentType` + `DocumentAnalyzerRegistry`), `estimate:*`-команды (12 из 19 текущих
  `ai:*`/`estimate:*`), пять значений `AiDocumentType` в §3.5 вместо трёх (найден и починен факт-
  дефект). Добавлен §8.7 «Экспорт под локальную LLM» — контракт по фактам Deliverables P7.1
  (сигнатура команды, основание доверия, `DatasetInputBuilder`, маркировка, манифест, что не
  экспортируется). §8.3 честно называет НЕсведённый третий путь `estimate:dataset:import`
  (`DatasetImporter::importPair()`, D57) рядом с единым сеймом `WorkingCopyProvisioner`
  (`estimate:gold:import` ↔ P5.2), вместо выдуманной единственности; добавлена ось контура
  (`platform_id`/«Обучалка», D91) и `analysis_id` (P7.1). Точечно поправлены `01-database.md`
  («Канон» + четвёртый префикс `llm_` + снос `prc_document_types`, включая §6/§6c того же файла —
  Validation 2/3 гейтит ВЕСЬ `docs/specs/v2/**`, не только узкий Files-текст) и
  `04-llm-providers.md` (8 вхождений `llm_*`); тот же класс точечной правки в четырёх файлах D65
  (`03-document-pipeline.md`, `05-admin-observability.md`, `06-roadmap.md`,
  `07-carryover-from-v1.md`) — суммарно 22 изменённые строки (≤ 32). Сняты голые ссылки на items
  чужих планов (`EST-AI`/`2026.07.26-EST-TRAIN`): 54 → 37 вхождений `Pn.m`/`EST-TRAIN`/`EST-AI` в
  `08`, остаток — легальные ссылки на живые items ЭТОГО плана, явно поименованный чужой план или
  реальные пути к артефактам. `plan-lint` — 1 ERROR/0 WARN, K=0 новых (предсуществующий
  `phases/P4.md:481`). **Known Deviations (процесс, 🟢 сохранён):** форма ссылки на неархивированные
  чужие планы (имя плана вместо литерала `plans/archive/**`, которого физически не существует);
  правка `01-database.md` вышла за буквальный текст Scope Included (только §6/§6c того же файла, по
  требованию Validation-гейта на весь каталог).

**Remaining:** **P7 — 4/6 🟢 (+2 🟠), фаза `🟡 In progress`.** Порядок исполнения: ~~B4~~ ~~B2~~
~~P7.3~~ **→ P7.4**. После P7.4 фаза P7 станет терминальной (все items 🟢/🟠) — тогда `plan-close`.
Реконсилирующее закрытие фазы P4 всё ещё не выполнено (наследуемый риск, P7 не блокирует).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules, D140), §3 Routing, §5
(D1–D140, включая D41/D56/D57/D91) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`,
`root/document-contour.md` (§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`,
`root/service-layer-map.md`, `root/usage-source-contract.md`, `root/naming-canon.md` ·
`phases/P6.md` (терминальна) · `phases/P7.md` (P7.1/P7.2/P7.3/P7.5/P7.6 Completion Notes,
оставшийся P7.4) · ревизованный `docs/specs/v2/08-estimate-extraction.md` (§8.7 — источник для
P7.4's `training-loop.md`) · `docs/specs/v2/01-database.md`, `04-llm-providers.md` (точечно
правлены P7.3) · `research/01-target-architecture.md` §8 (coupling-таблица, D15) ·
`findings/inherited-debts-2026-07-29.md` §2 · `roadmap.md` · `open-questions.md` (OQ-15, OQ-18).

**Open risks:**
- **PROMOTE-CANDIDATE владельцу — правило `Files` ⊇ `Validation` в скилл `plan-protocol`** (D140).
- **Остаточная дупликация `estimate:dataset:import`** (`DatasetImporter::importPair()`, D57) —
  собственная копия создания рабочей копии, НЕ сведённая к сейму `WorkingCopyProvisioner`;
  объединение — новый D# и отдельный item, назван честно в `08` §8.3 этим item'ом.
- **F1 EST-ARCH P6.1 не перемерян боевым прогоном** (D52) — боевой замер — P7.6 плана
  `2026.07.26-EST-TRAIN`, гейт владельца.
- **OQ-18 — расширять ли словари §2.1/§2.2 на четыре шага извлечения** (открыт D137, текстом закрыт
  P7.5). Решается числом `estimate:eval:compare` на holdout; ни один item плана от решения не
  зависит.
- **Форма ссылки на неархивированный чужой план** (Known Deviations P7.3) — если `EST-AI`/
  `2026.07.26-EST-TRAIN` когда-нибудь переедут в `plans/archive/`, строки-цитаты в `08` можно (не
  обязательно) привести к буквальной форме — не блокирует ничего.
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
