# HANDOFF — 2026-07-31 — after P6.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet (перебор до opus безвреден — §9, назвать в Known Deviations) |
| Thinking | high — `implementation/high` по §3 Routing, `Exec = manual` |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить P6.2 — устойчивое сопоставление смет `estimate_number → адрес → позиция` вместо позиционного |

```
/model sonnet
/effort high
/task:plan-run 2026.07.28-EST-ARCH P6.2
```

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢), P4 (🟠, 4/6 🟢), P5 (🟠, 1/6 🟢)
закрыты как в предыдущем handoff'е. **Фаза P6 начата этой сессией:** P6.1 🟠 (`e1ab08b`, плюс
подготовительный `2fe0122` — golden-фикстура JSON-схем, снятая ДО рефакторинга).

**Эта сессия (`plan-run P6.1`):** правило обхода графа типов вынесено в единственного владельца
`App\Services\Llm\Schema\DataTypeGraph` (общий с `JsonSchemaGenerator`); `PromptContextGenerator`
печатает транзитивное замыкание переданных корней, а не переданный список; словарь, до которого
обход не доезжает (`EstimateHeaderMap`), объявляет тип —
`App\Services\Procurement\Analyzers\Estimate\EstimateStepDictionaries`, читают все три потребителя
шага строк; тест словарей переехал с ручной передачи классов в генератор на боевой путь через
`PromptComposer`, добавлен тест когерентности двух носителей. Системная часть шага строк выросла
9092 → 26202 символов, `rules_hash` сменился на всех шести шагах. Docs-sync:
`docs/specs/v2/08-estimate-extraction.md` §6, `docs/03_Dev/ai/prompts.md`.

**Remaining:** 6 items ⬜ — P6 (2: P6.2, P6.3 — строго последовательно, D50), P7 (4, B2 = P7.1+P7.2).
Реконсилирующее закрытие фазы P4 всё ещё не выполнено (наследуемый риск, не блокирует P6).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D136) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`
(§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P6.md` (Phase Context, Completion Notes P6.1) ·
`findings/inherited-debts-2026-07-29.md` §3 (вход P6.2) · `roadmap.md` (P6.2 — solo,
implementation/high) · `open-questions.md` (OQ-15).

**Open risks:**
- **F1 после смены состава промта НЕ перемерян** (D52) — вход модели изменился на КАЖДОМ вызове
  извлечения (шаг строк ×2.9 по длине системной части), качество не проверялось. Боевой замер —
  P7.6 плана `2026.07.26-EST-TRAIN`, гейт владельца.
- **Спека §2 расходится с объявлением словарей** — `docs/specs/v2/08-estimate-extraction.md:103`
  утверждает оба словаря для четырёх шагов извлечения, код отдаёт `EstimateHeaderMap` только
  `extraction:rows` (по ТЗ P6.1). Нужна D-запись: расширить объявление либо исправить §2.
- **`rules_hash` сменился на всех шести шагах** — живые explicit-кэши анализов инвалидируются
  штатно, единожды; первый вызов каждого документа после деплоя дороже.
- **ACL каталогов, создаваемых контейнером** — правка файлов, созданных `www-data`, требует
  `docker exec … chgrp 1000 && chmod g+w`. Наследуется с P5.4/P5.5.
- **`guard:catalog:validate --strict` системно красный** — `159 permissions in catalog, 9
  permissions in policies`. Проект на enum-RBAC, вне плана.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource`** — чинится переименованием enum'а вместе с
  ролями, отдельный item.
- **Систематический пробел `larastan` на свойствах моделей** — `Prompt::$content`,
  `Analysis::$result`/`$last_error`, `DatasetExample::$task/$version/$source`.
- **Кнопка прогона требует `AI_SCREENING_ENABLED`** (дефолт `false`) — с P5.2.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94).
- Две точки проверки формы профиля — принятый долг D132, адресат OQ-15.
- **Реконсилирующее закрытие фазы P4 не выполнено** — не блокирует P6.
- **Остаточная ошибка `plan-lint` вне Scope P6.1** (N−K может быть > 0): `phases/P4.md:481` — P4.5
  закрыт 🟢 без строки `write-site: pre_mutation: … · pre_final: …`. Чинится при `plan-close` P4.
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Посторонний дифф в дереве (не мой, не тронут):** массовое удаление файлов каталога `Old/`
  (легаси v1-приложение) — по-прежнему в `git status` как unstaged `D`; плюс неотслеживаемые
  `.swissknife/` и `estimates/gemini-analys.md`. Требует решения владельца ДО следующего коммита.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app` (голый падает на
  незаданном `MINIO_ROOT_PASSWORD` при интерполяции compose); резолв
  `task.task_contract`/`task.route_evidence`/`plan-lint.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98); правка файлов, созданных
  контейнером, — через `docker exec … chgrp 1000 && chmod g+w`.
- deferred: пере-замер F1 (P7.6 EST-TRAIN); сведение объявления словарей со спекой §2; сведение
  двух точек валидации профиля к одной (OQ-15); чистка `AiRoutePermission`↔`LlmRouteResource`;
  Policy-покрытие каталога прав под `--strict`; предсуществующий `plan-lint` ERROR на
  `phases/P4.md:481`; докблоки `@property` на `Analysis`/`DatasetExample`; реконсилирующее
  закрытие фазы P4.
- open_questions: без изменений — OQ-15 (`Decision pending`), вне плана OQ-14.
