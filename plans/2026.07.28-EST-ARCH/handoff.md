# HANDOFF — 2026-07-30 — after P5.6

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — реконсиляция по git-фактам, без проектирования |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P5 (все 6 items терминальны): таблицы, Phase Handoff, lint — реконсилирующим `plan-close` |

Фаза P5 стала терминальной этим закрытием (1 🟢 / 5 🟠, ⬜ не осталось), поэтому следующий шаг —
не item, а закрытие фазы:

```
/task:plan-close 2026.07.28-EST-ARCH P5
```

Дальше по roadmap идёт **P6.1** — `Exec = manual` (§3 Routing, `implementation/high`): команда
`/task:plan-exec` пинит sonnet/medium и по гейту п. 2 обязана его отклонить. Запуск ВРУЧНУЮ:

```
/model opus
/effort high
/task:plan-run 2026.07.28-EST-ARCH P6.1
```

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢), P4 (🟠, 4/6 🟢, терминальна)
закрыты как в предыдущем handoff'е. P5.1 — 🟠 (`9f394eb`), P5.2 — 🟠 (`bc2e241`), P5.3 — 🟠
(`cdabf3d`), P5.4 — 🟢 (`bc2bf45`), P5.5 — 🟠 (`6b5509e`).

**Эта сессия (`plan-run P5.6`, opus/high):** item P5.6 исполнен и закрыт 🟠 `Done with
deviations`, item-коммит `de4115c` (14 файлов, +848/−7).

1. **`PromptSuggestionResource`** (`app/Filament/Resources/Procurement/Ai/PromptSuggestions/`,
   новый) — read-only очередь предложений критика (`index + view`, create/edit нет: записи
   создаёт `estimate:prompts:suggest`). Список: статус (дефолтный фильтр `pending`), форма
   (`kind`), ключ и базовая версия промта, обе оси предмета P1.8, происхождение одной строкой;
   фильтры по статусу, форме, типу документа (с сентинелом `any`) и стадии.
2. **Карточка** — обоснование критика, свидетельства, предложенный текст РЯДОМ с текущим телом
   промта из `PromptReadRepository::findVersion()` (носитель `Llm`, не файл на диске); блок
   «Одобрено, но не применено» с `copyable`-строкой `ai:prompts:apply <id>`. Тексты выводятся
   `TextEntry` без `->html()` — они пришли от критика по недоверенному содержимому.
3. **`PromptSuggestionDecider`** (`app/Services/Procurement/Tuning/`, новый) — `approve()`/
   `reject()`: статус + аудит (кто/когда/почему) одной записью через новый
   `PromptSuggestionStoreRepository::markDecided()`. Причина отказа обязательна ТИПОМ. Файла
   промта не касается ни одна ветка (D47).
4. **Права:** `PromptSuggestionPermission` (генератор enum-RBAC); `Operator`/`Analyst` получили
   `ViewAny`/`View`, `Update` (= апрув) остался за суперадмином.
5. **Тесты** — `tests/Feature/Filament/PromptSuggestionResourceTest.php` (12 тестов), включая
   «апрув не трогает ни одного файла канона» (снимок sha1 обоих корней D32 + проверка, что апрув
   реально отработал) и «оператор действий не видит, прямой вызов получает отказ».
6. **Известные отклонения (🟠):** дифф вне `Files` — четыре пути (`markDecided()` в репозитории;
   `PromptSuggestionAlreadyDecided`; `@property string $content` на `Prompt` под phpstan; удаление
   осиротевшей записи baseline). Плюс `guard:catalog:validate --strict` остался системно красным.
   Полные детали — `phases/P5.md` P5.6 Completion Notes и Known Deviations.

**Remaining:** 7 items ⬜ — P6 (3), P7 (4, B2 = P7.1+P7.2). Фазы P4 и P5 терминальны,
реконсилирующее закрытие (`plan-close`) не выполнено ни у одной.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D136) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`
(§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P5.md` P5.1–P5.6 (Completion Notes) ·
`open-questions.md` (OQ-15).

**Open risks:**
- **ACL каталогов, создаваемых контейнером** — `artisan make:*`/`guard:filament:generate` пишут от
  `www-data` (33) с маской `r-x` для хоста: правка с хоста требует `docker exec … chgrp 1000 &&
  chmod g+w`. Наследуется с P5.4/P5.5 и подтверждено этой сессией.
- **`guard:catalog:validate --strict` системно красный** — `159 permissions in catalog, 9
  permissions in policies`, 150 ключей без Policy-метода с `#[GateAbility]`. Проект стоит на
  enum-RBAC (`config/az-guard-filament.php: source => 'enum'`), политики не генерируются;
  P5.6 счётчик не ухудшил (проверено временным изъятием нового enum'а). Вне P5.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource` ВСПЛЫЛО:** полная регенерация прав
  (`guard:filament:generate --source=enum`) выплёвывает `LlmRoutePermission.php`, которого нет в
  дереве. Файл удалён до коммита; следующая регенерация выплюнет его снова. Чинится
  переименованием enum'а вместе с ролями — отдельным item'ом.
- **Систематический пробел `larastan` на свойствах моделей** — сужен ещё на один
  (`Prompt::$content`); `Analysis::$result`/`$last_error`, `DatasetExample::$task/$version/$source`
  остаются.
- **Кнопка прогона требует включённого `AI_SCREENING_ENABLED`** (дефолт `false`) — с P5.2.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94).
- Две точки проверки формы профиля (витрина через анализатор + `FixtureManifestEntry` импорта) —
  принятый долг D132, адресат OQ-15.
- Фазы P4 и P5 не прошли реконсилирующее закрытие (`plan-close`) — не блокирует P6.
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Посторонний дифф в дереве (не мой, не тронут):** массовое удаление файлов каталога `Old/`
  (легаси v1-приложение) — по-прежнему в `git status` как unstaged `D`; плюс неотслеживаемые
  `.swissknife/` и `estimates/gemini-analys.md`. Ни один не входит ни в item-коммит `de4115c`, ни
  в bookkeeping-коммит этого закрытия — оба собраны по явным путям. Требует решения владельца ДО
  следующего коммита в этом дереве.
- Прочие наследуемые риски — без изменений, см. `git log -p -- handoff.md`.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app` (переменные MinIO
  не интерполируются без явных `--env-file`); резолв `task.task_contract`/`task.route_evidence`/
  `plan-lint.py` через плагин-кэш `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98);
  правка файлов, созданных контейнером, — через `docker exec … chgrp 1000 && chmod g+w`.
- deferred: сведение двух точек валидации профиля к одной (OQ-15); чистка
  `AiRoutePermission`↔`LlmRouteResource`; Policy-покрытие каталога прав под `--strict`;
  предсуществующий `plan-lint` ERROR на `phases/P4.md:481` (P4.5 без строки `write-site:`);
  докблоки `@property` на `Analysis`/`DatasetExample`.
- open_questions: без изменений — OQ-15 (`Decision pending`), вне плана OQ-14.
