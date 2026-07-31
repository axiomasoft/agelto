# HANDOFF — 2026-07-31 — after P6.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — механическая сверка закрытой фазы по git-фактам, `task:plan-close` пин |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P6 (терминальна: P6.1 🟠/P6.2 🟢/P6.3 🟢) — таблицы, Phase Handoff, lint |

```
/task:plan-close 2026.07.28-EST-ARCH P6
```

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢), P4 (🟠, 4/6 🟢), P5 (🟠, 1/6 🟢)
закрыты как в предыдущем handoff'е. **Фаза P6 закрыта этой сессией (терминальна, 2/3 🟢):** P6.1 🟠
(`e1ab08b`, предыдущая сессия) → P6.2 🟢 (`18019e5`, предыдущая сессия в этом же дне) → P6.3 🟢
(`72a4ff3`, эта сессия).

**Эта сессия (`plan-run P6.3`):** механический рычаг `property.notFound` — `@property`/
`@property-read` докблоки на 16 моделях AI-контура/`Llm`/`Document`/`Estimate` (`Analysis`,
`Correction`, `DatasetExample`, `EvalResult`, `EvalRun`, `LlmCall`, `LlmRoute`, `Prompt`, `Provider`,
`Document`, `DocumentVersion`, `Estimate`, `Item`, `Resource`, `Section`, `Total`); `phpstan-
baseline.neon` пересчитан ЦЕЛИКОМ: `240 → 74` записей / `309 → 84` ошибок (`property.notFound`
`203 → 41`). `databaseMigrationsPath` опробован (в `phpstan.neon`) и ОТКАЧЕН — 0 эффекта: 47 из 51
миграции резолвят имя таблицы динамически (`Schema::create($this->table(), …)`), Larastan's
`SchemaAggregator` распознаёт только литеральный `String_`-узел. Остаток классифицирован по причине
в новом `docs/03_Dev/static-analysis.md` (20 строк, 17 `identifier`) + строка в
`.claude/rules/agelto-reviewer-overrides.md` («baseline не растёт без D-записи»). **Найден и НЕ
починен вживую дефект:** `EstimateStoreRepository::findOrCreateSection()` пишет/читает
`Section::$section_number` — колонка удалена миграцией D100; запись молча гасится mass-assignment
guard'ом (не SQL-ошибка), сравнение всегда `null !== $sectionNumber` → лишний `update()` на каждом
резолве раздела. Эскалирован под отдельный follow-up item (Pending Work phases/P6.md P6.3). Детали —
`phases/P6.md` P6.3 Completion Notes.

**Remaining:** фаза P6 закрыта, но НЕ реконсилирована (`task:plan-close` — следующий шаг выше);
P7 (4 item'а ⬜, B2 = P7.1+P7.2) — единственная незакрытая фаза плана. Реконсилирующее закрытие
фазы P4 всё ещё не выполнено (наследуемый риск, не блокирует P6/P7). Follow-up вне плана: дефект
`Section::section_number` (найден P6.3); классифицированный остаток baseline (crawling-контур 19 ·
`Procurement`/`ProcurementRevision` 13 · кросс-доменные `$uuid` 4 записи) — тем же рычагом, когда
эти домены войдут в периметр другого плана.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D136) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`
(§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P6.md` (Phase Context, Completion Notes P6.1/P6.2/P6.3) ·
`docs/03_Dev/static-analysis.md` (новый, P6.3) · `findings/inherited-debts-2026-07-29.md` §2 ·
`roadmap.md` · `open-questions.md` (OQ-15).

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
  `--format=md`; follow-up при необходимости у оператора.
- **Живой дефект `Section::$section_number`** (найден P6.3, НЕ починен) — мёртвая запись/сравнение
  в `EstimateStoreRepository::findOrCreateSection()` на колонку, удалённую D100; безвредно
  (mass-assignment guard гасит запись), но вводит в заблуждение и тратит лишний `update()`-запрос на
  каждом резолве раздела. Follow-up item.
- **ACL каталогов, создаваемых контейнером** — правка файлов, созданных `www-data`, требует
  `docker exec … chgrp 1000 && chmod g+w`. Наследуется с P5.4/P5.5.
- **`guard:catalog:validate --strict` системно красный** — `159 permissions in catalog, 9
  permissions in policies`. Проект на enum-RBAC, вне плана.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource`** — чинится переименованием enum'а вместе с
  ролями, отдельный item.
- **Кнопка прогона требует `AI_SCREENING_ENABLED`** (дефолт `false`) — с P5.2.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94).
- Две точки проверки формы профиля — принятый долг D132, адресат OQ-15.
- **Реконсилирующее закрытие фазы P4 не выполнено** — не блокирует P6/P7.
- **Остаточная ошибка `plan-lint` вне Scope P6.1/P6.2/P6.3** (N−K может быть > 0): `phases/P4.md:481` —
  P4.5 закрыт 🟢 без строки `write-site: pre_mutation: … · pre_final: …`. Чинится при `plan-close` P4.
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Посторонний дифф в дереве (не мой, не тронут):** массовое удаление файлов каталога `Old/`
  (легаси v1-приложение) — по-прежнему в `git status` как unstaged `D`; изменённый `.env.example`
  (предшествует этой сессии); неотслеживаемые `.swissknife/`, `estimates/gemini-analys.md`,
  `config/cms.php`, `database/seeders/User/AdminUserSeeder.php`. Требует решения владельца ДО
  следующего коммита.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app` (голый падает на
  незаданном `MINIO_ROOT_PASSWORD` при интерполяции compose); резолв
  `task.task_contract`/`task.route_evidence`/`plan-lint.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98); правка файлов, созданных
  контейнером, — через `docker exec … chgrp 1000 && chmod g+w`.
- deferred: пере-замер F1 (P7.6 EST-TRAIN); сведение объявления словарей со спекой §2; сведение
  двух точек валидации профиля к одной (OQ-15); чистка `AiRoutePermission`↔`LlmRouteResource`;
  Policy-покрытие каталога прав под `--strict`; предсуществующий `plan-lint` ERROR на
  `phases/P4.md:481`; реконсилирующее закрытие фазы P4; таблица сопоставления в markdown-выводе
  `estimate:eval:diff`; дефект `Section::section_number` (P6.3); классифицированный остаток
  `phpstan-baseline.neon` (crawling-контур/`Procurement`/кросс-домен, P6.3).
- open_questions: без изменений — OQ-15 (`Decision pending`), вне плана OQ-14.
