# HANDOFF — 2026-07-31 — after P6.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet (перебор до opus безвреден — §9, назвать в Known Deviations) |
| Thinking | high — `implementation/high` по §3 Routing, `Exec = manual` |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить P6.3 — разбор `phpstan-baseline.neon` и политика статического анализа (последний item фазы, D50) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.28-EST-ARCH P6.3
```

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢), P4 (🟠, 4/6 🟢), P5 (🟠, 1/6 🟢)
закрыты как в предыдущем handoff'е. **Фаза P6 продолжена этой сессией:** P6.1 🟠 (`e1ab08b`,
предыдущая сессия) → P6.2 🟢 (`18019e5`, эта сессия). Осталось P6.3 (последний item фазы).

**Эта сессия (`plan-run P6.2`):** позиционное сопоставление смет (индекс в списке `estimates`)
заменено явным парным проходом `EvalMetrics::matchEstimates()` — порядок правил `estimate_number`
→ `passport.address` → позиция среди оставшихся, дубль на одной стороне неинформативен для ВСЕХ
его носителей на этой стороне (D54 п.3). `flattenRows()`/`flattenPassport()`/`flattenTotals()` и
`EstimateDiffReport::diffArithmetic()` переведены с индекса списка на номер СЛОТА; непарные сметы
получают собственный слот и считаются целиком (FN у gold, FP у предсказания); `DiffReport` несёт
новое поле `matches` — таблицу сопоставления. На сдвинутом-на-одну-смету входе `overall.f1` после
правки строго выше, чем на `<base>` (0.8000 > 0.6667, измерено изолированным скриптом против
кода `e5c9c0e`). Docs-sync: `docs/specs/v2/08-estimate-extraction.md` §8.6. Детали — см.
`phases/P6.md` P6.2 Completion Notes.

**Remaining:** 5 items ⬜ — P6 (1: P6.3, D50), P7 (4, B2 = P7.1+P7.2). Реконсилирующее закрытие
фазы P4 всё ещё не выполнено (наследуемый риск, не блокирует P6).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D136) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`
(§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P6.md` (Phase Context, Completion Notes P6.1/P6.2) ·
`findings/inherited-debts-2026-07-29.md` §2 (вход P6.3) · `roadmap.md` (P6.3 — solo,
implementation/high) · `open-questions.md` (OQ-15).

**Open risks:**
- **F1 после смены состава промта (P6.1) и правила сопоставления (P6.2) НЕ перемерян боевым
  прогоном** (D52) — оба item'а закрыты измеримыми числами без обращения к провайдеру. Боевой
  замер — P7.6 плана `2026.07.26-EST-TRAIN`, гейт владельца.
- **Спека §2 расходится с объявлением словарей** — `docs/specs/v2/08-estimate-extraction.md:103`
  утверждает оба словаря для четырёх шагов извлечения, код отдаёт `EstimateHeaderMap` только
  `extraction:rows` (по ТЗ P6.1). Нужна D-запись: расширить объявление либо исправить §2.
- **`rules_hash` сменился на всех шести шагах** (P6.1) — живые explicit-кэши анализов
  инвалидируются штатно, единожды; первый вызов каждого документа после деплоя дороже.
- **`EvalDiffCommand::renderMarkdown()` не показывает таблицу сопоставления** (P6.2) — новое поле
  `DiffReport::$matches` видно в `--format=json`, но не отрисовано отдельной секцией в
  `--format=md`; сигнатура `EstimateDiffReport::build()` не менялась, поэтому вне Files P6.2 —
  follow-up при необходимости у оператора.
- **ACL каталогов, создаваемых контейнером** — правка файлов, созданных `www-data`, требует
  `docker exec … chgrp 1000 && chmod g+w`. Наследуется с P5.4/P5.5.
- **`guard:catalog:validate --strict` системно красный** — `159 permissions in catalog, 9
  permissions in policies`. Проект на enum-RBAC, вне плана.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource`** — чинится переименованием enum'а вместе с
  ролями, отдельный item.
- **Систематический пробел `larastan` на свойствах моделей** — `Prompt::$content`,
  `Analysis::$result`/`$last_error`, `DatasetExample::$task/$version/$source`. Адресат P6.3
  (механический рычаг `@property`/`databaseMigrationsPath`).
- **Кнопка прогона требует `AI_SCREENING_ENABLED`** (дефолт `false`) — с P5.2.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94).
- Две точки проверки формы профиля — принятый долг D132, адресат OQ-15.
- **Реконсилирующее закрытие фазы P4 не выполнено** — не блокирует P6.
- **Остаточная ошибка `plan-lint` вне Scope P6.1/P6.2** (N−K может быть > 0): `phases/P4.md:481` —
  P4.5 закрыт 🟢 без строки `write-site: pre_mutation: … · pre_final: …`. Чинится при `plan-close` P4.
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
  `phases/P4.md:481`; докблоки `@property` на `Analysis`/`DatasetExample` (адресат P6.3);
  реконсилирующее закрытие фазы P4; таблица сопоставления в markdown-выводе `estimate:eval:diff`
  (если понадобится оператору).
- open_questions: без изменений — OQ-15 (`Decision pending`), вне плана OQ-14.
