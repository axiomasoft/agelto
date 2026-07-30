# HANDOFF — 2026-07-30 — after P5.1

**Next:** run-items: task:plan-run 2026.07.28-EST-ARCH P5.2

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — Routing §3 предписывает `implementation/high` (P5.2), совпадает с сессией P5.1 |
| Context | continue (/clear) — ручной item |
| Суть | Действие «Прогнать» на карточке эталона: сервис запуска через ЕДИНСТВЕННЫЙ сейм рабочей копии P3.3 + штатный вход пайплайна |

````
/task:plan-run 2026.07.28-EST-ARCH P5.2

РЕЖИМ: исполнение item'а по ТЗ (item в `⬜`; модель ставится СЕССИЕЙ — `Exec = manual`, маршрут
`inherited-plan` §9, фактическую пару «класс/effort» напечатать в отчёте).
ВХОД: plans/2026.07.28-EST-ARCH/phases/P5.md → item P5.2 (Required Reads 1–9, читать в порядке
списка).
СКОУП: 1) сервис запуска прогона (неймспейс по `root/service-layer-map.md`) — вызов ЕДИНСТВЕННОГО
сейма рабочей копии эталона (вынесен P3.3 из `GoldImportCommand`), проставление оси происхождения
и связи документа с эталоном (`root/document-contour.md`), создание строки прогона (режим
`manual`, лимит объёма из конфига с переопределением в форме), диспатч штатного входа пайплайна
`->afterCommit()`, единая форма отказа при активном прогоне;
2) действие «Прогнать» на карточке эталона (`authorize()`, `requiresConfirmation()`, уведомление);
3) правка `OperatorRole` (`AnalysisPermission::Create`) + `guard:sync-roles`;
4) тесты на `Queue::fake()`/`FakeLlmProvider`, включая «повторный клик не диспатчит второй job».
НЕ ТРОГАТЬ: порядок стадий пайплайна и прямой вызов оркестратора/анализатора/процессора скрининга
(запрещено); ВТОРОЙ путь создания рабочей копии (D41 — переиспользуется сервис P3.3, второй путь —
эскалация); `PipelineJob`/`ScreeningJob`/job извлечения/обработчик исхода (чужие сеймы — только
вызов); история прогонов и показ расхода (P5.3); модалка результата (P5.4); миграции и схему (P1).
````

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢, проаудирована ATTENTION),
P4 (🟠, 4/6 🟢, терминальна) закрыты, как в предыдущем handoff'е. **P5.1 закрыт 🟠 (`9f394eb`)** —
этот заход.

- **Этот заход (`plan-run P5.1`, 2026-07-30) — исполнение по ТЗ, item закрыт.** Session: sonnet/high
  (точное совпадение с Routing §3, ни недобора, ни перебора).
- Заведён capability-интерфейс `App\Services\Procurement\Contracts\SupportsTypeProfileValidation`
  (§1.6 контракта P2.1, D132) РОВНО по сигнатуре/шести инвариантам; первая реализация —
  `EstimateAnalyzer::validateTypeProfile()` (пять полей §6.2 `root/document-type-axis.md`, полная
  проверка, закрытый состав по ключам).
- `FixtureResource` (index/create/view/edit, форма из девяти полей, бейдж манифеста ЧТЕНИЕМ
  `FixtureManifestReader` один раз на рендер, генерический фильтр по полю профиля) поверх
  `FixtureUploader` (потоковый sha256-дедуп → `FixtureAlreadyUploaded`) и `Fixture::nextCodeFor()`
  (выражения `type_profile->class`/`type_profile->form`, без индексов — §6.4).
- `FixturePermission` сгенерирован (`guard:filament:generate --source=enum`); `OperatorRole`
  (ViewAny/View/Create/Update, без Delete) и `AnalystRole` (ViewAny/View) обновлены,
  `guard:sync-roles` подтверждён.
- **Находка при регенерации прав (не тронута, вне Scope):** `guard:filament:generate` завёл бы
  ВТОРОЙ файл `LlmRoutePermission.php` — резолвер именует по классу ресурса (`LlmRouteResource`),
  а живой класс до сих пор `AiRoutePermission` (расхождение возникло ДО этого item'а). Стёрт как
  посторонний артефакт генерации (Implementation Rule 9 — «любой непустой [диф сверх нового файла]
  — стоп и разбор, а не коммит»), в коммит не попал. Адресат — не P5.1: реестр разошёлся с именем
  ресурса, чинить чужим item'ом нельзя (нет Files на это).
- **Три тест-файла зелёные** (`FixtureResourceTest` 7, `FixtureUploadTest` 3, `TypeProfileValidationTest`
  4 — 14 тестов/61 assertion); СТОРОЖ Validation 8 по ходу заставил переписать фильтр профиля
  таблицы с типизированного `SelectFilter` (гонявшего `FixtureClass`/`GoldSource`) на генерический
  `Filter::make('profile_field')` с параметризованным `whereRaw` (двух-текстовое поле «ключ +
  значение», без конкатенации ключа в SQL — иначе операторский ввод открывал бы SQL-инъекцию).
- Полный сюит — 839 passed / 1 skipped (наследуемый пропуск, не этого item'а); phpstan — 0 новых
  ошибок, baseline не тронут; pint — 20 файлов чисто (один автофикс `self_static_accessor` в
  `Fixture::nextCodeFor()` до коммита).
- **🟠 Done with deviations, два Known Deviations:** (1) канарейка периметра ядра
  `CoreKnowsNoDocumentTypeTest` сдвинута 92→94 (два новых файла ядра — `Contracts\
  SupportsTypeProfileValidation`, `Fixture\FixtureUploader` — сами попадают в периметр §6.2), дифф
  вне `Files`, тот же класс, что у P4.2/P4.4; (2) `guard:catalog:validate --strict` остаётся
  красным по СИСТЕМНОЙ причине вне Scope — 148 из 149 permission-ключей каталога не покрыты
  Policy-классом (`#[GateAbility]`), в репозитории всего ОДНА AzGuard-политика на весь каталог;
  без `--strict` та же команда зелёная. Ни один из красных ключей не относится к `FixturePermission`.

**Remaining:** 11 items ⬜ — фаза P5 (5, следующий P5.2), P6 (3), P7 (4, B2 = P7.1+P7.2). Фаза P4
терминальна, реконсилирующее закрытие (`plan-close`) не выполнено (наследуется).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules), §3 Routing, §5
(D1–D133) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md` (§1.6 — D132), `root/service-layer-map.md`,
`root/usage-source-contract.md` · `phases/P5.md` P5.1 (Completion Notes) и P5.2 (следующее ТЗ) ·
`open-questions.md` (OQ-15, не адресована этим item'ом).

**Open risks:**
- **Две точки проверки формы профиля** (витрина через анализатор + `FixtureManifestEntry` импорта)
  — принятый долг D132, адресат OQ-15; наследуется P5.2+.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource`** (см. находку выше) — не чинится этим планом,
  всплывёт при следующей полной регенерации прав кем угодно; стоит завести отдельный D#/item, если
  владелец решит чинить (вне P5).
- `guard:catalog:validate --strict` системно красный (148/149 ключей без Policy) — вне P5.1, вне
  этого плана в чистом виде; remediation — Completion Notes P5.1 Known Deviations.
- Фаза P4 не прошла реконсилирующее закрытие (`plan-close`) — наследуется, не блокирует P5.
- Право виджета расхода не выдаётся ролям в P4.3 — наследуется, предмет P5 (не единственно P5.1).
- Счётчик периметра ядра — **94** (обновлён этим item'ом, было 92).
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround тот же (резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib`, D98).
- **`plan-lint` на дереве этого захода:** см. bookkeeping-коммит (Completion Notes P5.1 Validation 13).
- Прочие наследуемые риски — без изменений, см. `git log -p -- handoff.md`.

**Workarounds/Deferred/Open questions:**
- workarounds: резолв `task.task_contract`/`task.route_evidence`/`plan-lint.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (репо `agelto` не вендорит `packages/task/lib`,
  D98) — наследуется.
- deferred: сведение двух точек валидации профиля к одной (OQ-15); чистка `AiRoutePermission`↔
  `LlmRouteResource` (новая находка, вне плана до отдельного D#); Policy-покрытие каталога прав под
  `--strict` (новая находка, решение владельца) — остальное наследуется.
- open_questions: OQ-15 (без изменений, `Decision pending`); вне плана — OQ-14 (без изменений).
