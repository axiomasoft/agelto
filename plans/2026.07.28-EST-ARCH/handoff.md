# HANDOFF — 2026-07-30 — after P5.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — Routing §3 (предписано `implementation/high` + `Exec = manual`) |
| Context | continue (/clear) — ручной item |
| Суть | Модалка результата прогона: сводка от анализатора типа и эталон рядом (P5.4) — единственное место, где витрина может вернуть в проект знание о сметах (D27), поэтому `Exec = manual` |

Следующий item **P5.4** предписан `Exec = manual` (§3 Routing) — команда `/task:plan-exec` пинит
sonnet/medium и по гейту п.2 обязана его отклонить. Запуск вручную:

```
/model opus
/effort high
/task:plan-run 2026.07.28-EST-ARCH P5.4
```

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢), P4 (🟠, 4/6 🟢, терминальна) закрыты
как в предыдущем handoff'е. P5.1 закрыт 🟠 (`9f394eb`), P5.2 закрыт 🟠 (`bc2e241`).

**Эта сессия (`plan-exec P5.3`, sonnet/medium):** item P5.3 исполнен и закрыт 🟠
`Done with deviations`, item-коммит `cdabf3d`.

1. **`RunsRelationManager`** (`app/Filament/Resources/Procurement/Ai/Fixtures/RelationManagers/RunsRelationManager.php`,
   новый) — история прогонов на карточке эталона: `status`/`mode` через `->badge()` БЕЗ
   переформулировки (канон `AnalysesTable` — enum-значения показываются как есть); `started_at`/
   `finished_at`/computed `duration`; токены (`prompt`/`cached`/`completion`/`total`) и `cost_micros`
   читаются НАПРЯМУЮ из колонок слепка прогона, каждый с `placeholder('нет данных')`
   (Implementation Rule 1 — никакой агрегации/джойна к вызовам); `->poll()` замыканием, интервал
   `5s` только пока есть незавершённый прогон, иначе `null` (Implementation Rule 4); row-action
   «Анализ» на последний связанный `Analysis` данного прогона, с eager-load против N+1.
2. **`FixtureResource::getRelations()`** — зарегистрирован `RunsRelationManager`.
3. **`Fixture::runs()`** (`HasManyThrough` на `AnalysisRun` через `DocumentVersion`, сопоставление
   по `document_id` напрямую) — read-путь для витрины, добавлен ВНЕ `Files` item'а: ни `Fixture`,
   ни `Document`/`DocumentVersion` не несли связи, годной для построения relation manager'а (обе
   модели навигацию «вниз» не заводят, раскладка идёт репозиторием). Прецедент того же класса —
   `DocumentVersion::document()`, чей докблок сам называет себя read-путём для Filament.
4. **Тесты** — `tests/Feature/Filament/FixtureRunHistoryTest.php` (5 тестов): слепок совпадает
   построчно, прогон без слепка → честное «нет данных» (не ноль), режим виден, статус различает
   `running`/`succeeded`, доступ без права `fixture.view` закрыт (403 по прямой ссылке).
5. **Отклонение (Known Deviations, детали — `phases/P5.md` P5.3):** item-коммит несёт правку ВНЕ
   `Files` — `app/Models/Procurement/Ai/Fixture.php` (новый метод `runs()`), причина и прецедент — в
   Completion Notes item'а. Материальных отклонений нет: Scope Included выполнен полностью,
   Validation вся зелёная, `phpstan-baseline.neon` не тронут.

**Remaining:** 9 items ⬜ — фаза P5 (P5.4…P5.6), P6 (3), P7 (4, B2 = P7.1+P7.2). Фаза P4
терминальна, реконсилирующее закрытие (`plan-close`) не выполнено (наследуется).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D136) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`
(§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P5.md` P5.1/P5.2/P5.3 (Completion Notes) ·
`open-questions.md` (OQ-15).

**Open risks:**
- **`Fixture::runs()` — read-путь мимо репозиторного канона, узко.** Прецедент (`DocumentVersion::document()`)
  уже существовал, но если следующий item добавит ЕЩЁ одну такую связь без соответствующего
  прецедента — стоит проверить, не пора ли завести общий репозиторий чтения для витрины обучалки
  вместо накопления relation-методов на моделях; сейчас это не блокер (одна связь, документированная).
- Row-action «Анализ» ведёт на ПОСЛЕДНИЙ связанный `Analysis` прогона (extraction, если есть, иначе
  screening) — не на список ОБОИХ шагов; Validation P5.3 это не проверяла, риск низкий (P5.4
  модалка результата — более полное решение той же потребности).
- **Кнопка требует включённого `AI_SCREENING_ENABLED`** (дефолт `false`) — наследуется с P5.2, не
  относится к P5.3.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94), не относится к P5.3.
- Две точки проверки формы профиля (витрина через анализатор + `FixtureManifestEntry` импорта) —
  принятый долг D132, адресат OQ-15.
- Расхождение `AiRoutePermission`↔`LlmRouteResource` — вне P5, всплывёт при следующей полной
  регенерации прав.
- `guard:catalog:validate --strict` системно красный (148/149 ключей без Policy) — вне P5.
- Фаза P4 не прошла реконсилирующее закрытие (`plan-close`) — наследуется, не блокирует P5.
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Систематический пробел `larastan` на `Document::$status`** (наследуется с P5.2, не задет P5.3) —
  любой БУДУЩИЙ item, читающий это свойство внутри замыкания/новой конструкции, упрётся в тот же
  выбор «безусловная запись вместо чтения» либо баселин-запись, если рычаг не сработает.
- **Посторонний дифф в дереве, замеченный этой сессией (не мой, не тронут):** массовое удаление
  файлов каталога `Old/` (легаси v1-приложение, коммит `d5226fb`) присутствует в рабочем дереве как
  unstaged `D`-статус по `git status`, но НЕ входит ни в item-коммит `cdabf3d`, ни в
  bookkeeping-коммит этого закрытия — оба собраны по явным путям (`git add <файл>`, не `-A`).
  Происхождение неизвестно (не действие этой сессии — ни один вызов инструмента не трогал `Old/`);
  требует внимания владельца ДО следующего коммита в этом дереве, иначе следующая сессия рискует
  унести это удаление в свой коммит незамеченным.
- Прочие наследуемые риски — без изменений, см. `git log -p -- handoff.md`.

**Workarounds/Deferred/Open questions:**
- workarounds: резолв `task.task_contract`/`task.route_evidence`/`plan-lint.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (репо `agelto` не вендорит
  `packages/task/lib`, D98) — наследуется.
- deferred: сведение двух точек валидации профиля к одной (OQ-15); чистка
  `AiRoutePermission`↔`LlmRouteResource`; Policy-покрытие каталога прав под `--strict`;
  предсуществующий `plan-lint` ERROR на `phases/P4.md:481` (P4.5 без строки `write-site:`);
  докблок `@property DocumentStatus $status` на модели `Document` — остальное наследуется.
- open_questions: без изменений — OQ-15 (`Decision pending`), вне плана OQ-14.
