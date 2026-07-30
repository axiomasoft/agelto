# HANDOFF — 2026-07-30 — after P5.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — Routing §3 (predписано `implementation/medium`) |
| Context | continue (/clear) — ручной item |
| Суть | Relation-менеджер прогонов на карточке эталона: статус, режим, токены и цена в строке из слепка P4.1 |

```
/task:plan-exec 2026.07.28-EST-ARCH P5.3
```

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢), P4 (🟠, 4/6 🟢, терминальна) закрыты
как в предыдущем handoff'е. P5.1 закрыт 🟠 (`9f394eb`).

**Эта сессия (`plan-run P5.2`, sonnet/high; ТЗ уже было доведено до DoR прошлым заходом
`plan-design`):** item P5.2 исполнен и закрыт 🟠 `Done with deviations`, item-коммит `bc2e241`.

1. **`RunFixtureAnalysisAction`** (`app/Services/Procurement/Fixture/RunFixtureAnalysisAction.php`,
   новый) — действие «Прогнать»: гейт `config('ai.screening.enabled')` → `ScreeningDisabled` → гейт
   `WorkingCopyProvisioner::provision($fixture, dryRun: false)` (D41, единственный сейм) → гейт
   активного прогона версии → `AnalysisRunAlreadyActive` → в ОДНОЙ транзакции снятие активных
   анализов версии по обоим шагам (`superseded_at`) + безусловная запись `status = downloaded`
   (D136) → `PrepareDocumentJob::dispatch(..., RunMode::Manual, $initiatedBy)->afterCommit()`.
2. **`PrepareDocumentJob`** (D135) — два новых необязательных параметра конструктора
   (`RunMode $mode = RunMode::Auto`, `?int $initiatedBy = null`), пробрасываются в существующий
   вызов `AnalysisRunStoreRepository::start()`; поведение по умолчанию неизменно (доказано
   Validation 2 — тесты пайплайна `tests/Feature/Document/`/`tests/Feature/Procurement/` не
   тронуты).
3. **Два наследника `OperationFailed`** — `AnalysisRunAlreadyActive`, `ScreeningDisabled`
   (`app/Exceptions/Procurement/`).
4. **`ViewFixture`** (`app/Filament/Resources/Procurement/Ai/Fixtures/Pages/ViewFixture.php`) —
   действие «Прогнать» в `getHeaderActions()`: право `AnalysisPermission::Create` проверяется
   строкой ability через `hasPermission()` (не `->authorize()` — тот резолвился бы на
   `fixture.create`, D44), guard активного прогона стоит И в `visible()`, И внутри действия
   (Implementation Rule 7); `requiresConfirmation()`, уведомления «поставлено в очередь» / текст
   исключения.
5. **`OperatorRole`** — выдано `AnalysisPermission::Create`.
6. **Тесты** — `tests/Feature/Ai/FixtureRunTest.php` (5 тестов, сервисный слой) +
   `tests/Feature/Filament/FixtureRunScreenTest.php` (2 теста, экран) — все шесть обязательных
   кейсов Code Guidance покрыты.
7. **Отклонение (Known Deviations, детали — `phases/P5.md` P5.2):** item-коммит несёт правку ВНЕ
   `Files` — канарейка `tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php` (94→95, новый
   файл ядра `Fixture\RunFixtureAnalysisAction`), тот же класс отклонения, что у P4.2/P4.4/P5.1.
   Второе отклонение (не материальное, обходной приём в коде, не в baseline): систематический
   пробел `larastan` на `Document::$status` (баселинен в шести существующих файлах) обойдён
   безусловной записью статуса вместо чтения — baseline не тронут.

**Remaining:** 10 items ⬜ — фаза P5 (P5.3…P5.6), P6 (3), P7 (4, B2 = P7.1+P7.2). Фаза P4
терминальна, реконсилирующее закрытие (`plan-close`) не выполнено (наследуется).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D136) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`
(§3.2/§3.5/§3.7/§3.8), `root/document-analyzer-contract.md`, `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P5.md` P5.1/P5.2 (Completion Notes) ·
`findings/P5.2-run-launch-facts-2026-07-30.md` · `research/06-p5.2-run-launch-design.md` ·
`open-questions.md` (OQ-15).

**Open risks:**
- **Кнопка требует включённого `AI_SCREENING_ENABLED`** (дефолт `false`). Item заводит честный
  отказ (`ScreeningDisabled`), но эксплуатационно это значит: до включения гейта кнопка на любом
  стенде будет отказывать — не дефект item'а, а состояние окружения.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94); item закрыт на
  `Queue::fake()`/тестах, реальный LLM-вызов не выполнялся.
- P5.3 (следующий item) читает слепок расхода на прогоне (P4.1) и состав строки прогона — Deliverables
  P5.2 (сущность прогона с режимом/инициатором) уже готовы, блокеров для P5.3 нет.
- Две точки проверки формы профиля (витрина через анализатор + `FixtureManifestEntry` импорта) —
  принятый долг D132, адресат OQ-15.
- Расхождение `AiRoutePermission`↔`LlmRouteResource` — вне P5, всплывёт при следующей полной
  регенерации прав.
- `guard:catalog:validate --strict` системно красный (148/149 ключей без Policy) — вне P5.
- Фаза P4 не прошла реконсилирующее закрытие (`plan-close`) — наследуется, не блокирует P5.
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Систематический пробел `larastan` на `Document::$status`** (не введён P5.2, баселинен в шести
  файлах: `PrepareDocumentJob`, `DocumentPreparer`, `ScreeningJob`, `ScreeningOutcomeHandler`,
  `EstimateExtractionJob`, `GoldImportCommand`) — любой БУДУЩИЙ item, читающий это свойство внутри
  замыкания/новой конструкции, упрётся в тот же выбор «безусловная запись вместо чтения» либо
  баселин-запись, если рычаг не сработает; полноценный фикс — докблок `@property` на `Document`
  моделью, вне периметра P5.
- Прочие наследуемые риски — без изменений, см. `git log -p -- handoff.md`.

**Workarounds/Deferred/Open questions:**
- workarounds: резолв `task.task_contract`/`task.route_evidence`/`plan-lint.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (репо `agelto` не вендорит
  `packages/task/lib`, D98) — наследуется. `RunFixtureAnalysisAction` пишет `status=downloaded`
  безусловно (не читает `Document::$status`) — обход систематического пробела `larastan`, см. Open
  risks.
- deferred: сведение двух точек валидации профиля к одной (OQ-15); чистка
  `AiRoutePermission`↔`LlmRouteResource`; Policy-покрытие каталога прав под `--strict`;
  предсуществующий `plan-lint` ERROR на `phases/P4.md:481` (P4.5 без строки `write-site:`);
  докблок `@property DocumentStatus $status` на модели `Document` — остальное наследуется.
- open_questions: без изменений — OQ-15 (`Decision pending`), вне плана OQ-14.
