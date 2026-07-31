# HANDOFF — 2026-07-30 — after P5

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — implementation/high по roadmap, `Exec = manual` |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить P6.1 (первый item фазы «Наследуемые долги AI-контура») |

```
/model opus
/effort high
/task:plan-run 2026.07.28-EST-ARCH P6.1
```

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢), P4 (🟠, 4/6 🟢) закрыты как в
предыдущем handoff'е. **Фаза P5 закрыта этой сессией (`plan-close P5`):** все 6 items терминальны
— P5.1 🟠 (`9f394eb`), P5.2 🟠 (`bc2e241`), P5.3 🟠 (`cdabf3d`), P5.4 🟢 (`bc2bf45`), P5.5 🟠
(`6b5509e`), P5.6 🟠 (`de4115c`). Docs-sync фазы: обновлён `docs/03_Dev/admin-panel.md` (ресурс
`Fixtures` в таблице `Ai/` + абзац о рабочем месте обучалки).

**Эта сессия (`plan-close P5`):** реконсиляция таблиц (уже были синхронны с предыдущей сессии),
заполнение `## Phase Handoff` в `phases/P5.md` (агрегат Known Deviations всех 6 items, docs-sync
вердикт), docs-check через отдельного агента, перезапись этого handoff'а.

**Remaining:** 7 items ⬜ — P6 (3), P7 (4, B2 = P7.1+P7.2). Реконсилирующее закрытие фазы P4 всё
ещё не выполнено (наследуемый риск с прошлых сессий, не блокирует P6).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D136) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`
(§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P5.md` (Completion Notes P5.1–P5.6, Phase Handoff) ·
`roadmap.md` (P6.1 — solo, implementation/high) · `open-questions.md` (OQ-15).

**Open risks:**
- **ACL каталогов, создаваемых контейнером** — правка файлов, созданных `www-data`, требует
  `docker exec … chgrp 1000 && chmod g+w`. Наследуется с P5.4/P5.5.
- **`guard:catalog:validate --strict` системно красный** — `159 permissions in catalog, 9
  permissions in policies`, 150 ключей без Policy-метода. Проект на enum-RBAC, вне плана.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource`** — полная регенерация прав выплёвывает
  файл, которого нет в дереве; чинится переименованием enum'а вместе с ролями — отдельный item.
- **Систематический пробел `larastan` на свойствах моделей** — `Prompt::$content`,
  `Analysis::$result`/`$last_error`, `DatasetExample::$task/$version/$source`.
- **Кнопка прогона требует `AI_SCREENING_ENABLED`** (дефолт `false`) — с P5.2.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94).
- Две точки проверки формы профиля (витрина через анализатор + `FixtureManifestEntry` импорта) —
  принятый долг D132, адресат OQ-15.
- **Реконсилирующее закрытие фазы P4 не выполнено** — не блокирует P6.
- **Остаточная ошибка `plan-lint` вне Scope P5** (N−K может быть > 0): `phases/P4.md:481` — P4.5
  закрыт 🟢 без строки `write-site: pre_mutation: … · pre_final: …`. Чинится при `plan-close` P4.
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Посторонний дифф в дереве (не мой, не тронут):** массовое удаление файлов каталога `Old/`
  (легаси v1-приложение) — по-прежнему в `git status` как unstaged `D`; плюс неотслеживаемые
  `.swissknife/` и `estimates/gemini-analys.md`. Требует решения владельца ДО следующего коммита.
- Прочие наследуемые риски — без изменений, см. `git log -p -- handoff.md`.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app`; резолв
  `task.task_contract`/`task.route_evidence`/`plan-lint.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98); правка файлов, созданных
  контейнером, — через `docker exec … chgrp 1000 && chmod g+w`.
- deferred: сведение двух точек валидации профиля к одной (OQ-15); чистка
  `AiRoutePermission`↔`LlmRouteResource`; Policy-покрытие каталога прав под `--strict`;
  предсуществующий `plan-lint` ERROR на `phases/P4.md:481`; докблоки `@property` на
  `Analysis`/`DatasetExample`; реконсилирующее закрытие фазы P4.
- open_questions: без изменений — OQ-15 (`Decision pending`), вне плана OQ-14.
