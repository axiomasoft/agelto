# HANDOFF — 2026-07-30 — after P2.5

**Next:** «ЗАПУСК ВРУЧНУЮ: implementation/high» — P2.6 несёт `Exec = manual` в §3 Routing
(item обязан уметь ПРОВАЛИТЬ приёмку фазы, дешёвому тиру такое решение не отдаётся) —
`/task:plan-exec` его отклонит на предусловии (пин sonnet/medium ниже предписанного).
Запуск: `/model opus` + `/effort high` + `/task:plan-run 2026.07.28-EST-ARCH P2.6` (D18) —
ИЛИ голый промпт ниже под ручным переключением модели/effort.

| Параметр | Значение |
|:--|:--|
| Model | opus (или fable) |
| Thinking | high — Routing `implementation/high`, `Exec = manual` |
| Context | continue (/clear) — ручной item |
| Суть | Замыкает фазу P2: arch-тест по восьми неймспейсам ядра §6.2 контракта + `AiDocumentType::TechSpec` в тестовом окружении, от вердикта скрининга до сводки результата — единственный item, обязанный уметь провалить приёмку |

```
2026.07.28-EST-ARCH P2.6

РЕЖИМ: исполнение item'а (статус ⬜, кода нет)
ВХОД: plans/2026.07.28-EST-ARCH/phases/P2.md → P2.6 (все 16 полей) · его Required Reads (7 пунктов:
      root/document-analyzer-contract.md, plan.md §5 D14/D26/D27, tests/Pest.php,
      ScreeningBranchingTest.php, vendor/pestphp/pest-plugin-arch/src/Expectations/,
      FakeLlmProvider.php, скилл testing-rules) · plan.md §2 (правила исполнения, D89-гейт)
СКОУП: arch-тест «ядро не знает про тип документа» по списку неймспейсов контракта P2.1 · фиктивный
      анализатор второго типа (AiDocumentType::TechSpec) ИСКЛЮЧИТЕЛЬНО в tests/**, регистрируемый
      ОДНОЙ строкой ТОЛЬКО в тестовом окружении · интеграционный тест «диспатч job'а ЕГО
      анализатора, не сметного» · подтверждение фальсифицируемости ОБЕИХ проверок мутацией ·
      дифф-доказательство D14 в Completion Notes
НЕ ТРОГАТЬ: любой продуктовый код вне tests/ (Scope Excluded п.1 — потребовалась правка = НАХОДКА,
      остановиться и эскалировать, не чинить молча) · AiDocumentType (кейс TechSpec уже есть) ·
      arch-правила сверх названного свойства · строковые значения промтов
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢, детали — `phases/P1.md` → `## Phase Handoff`). **P2.1 🟢**
(`fcc0259`): контракт `DocumentAnalyzer` заморожен гейтом владельца. **P2.2 🟢** (`c485dab`):
маршрутизация типизирована на `AiDocumentType|DocumentTypeScope`, литералы `'estimate'`/`'any'` сняты.
**P2.3 🟢** (`2deb5c1`): `DocumentAnalyzer`, `DocumentAnalyzerRegistry`, DTO сводки, `EstimateAnalyzer`;
ветвление скрининга и re-run — через реестр. **P2.4 🟠** (`e06434c`): 16 сметных единиц +
`EstimateExtractionJob` в `**\Analyzers\Estimate\**`, в ядре пять единиц машинерии.

**P2.5 🟠 (`936911f`, эта сессия — `plan-run`, sonnet/high):** `PromptStep`/`LlmPurpose` расщеплены
на интерфейсы `App\Enums\Llm\{PromptStep,LlmPurpose}` + ядерные `Core*` (4+4 кросс-документных
кейса) + типовые `App\Enums\Procurement\Analyzers\Estimate\{EstimatePromptStep,EstimateLlmPurpose}`
(5+4 кейса извлечения); старые плоские носители снесены. `PromptComposer`/`PromptReadRepository`/
`AiRouteResolver`/`PromptCritic`/`ShadowAnalysisJob` переведены на сейм (цепочка композиции —
у шага, ось `document_type` в выборке промтов, `defaultTemperature()` вместо константы, три
match-лесенки критика сняты через `documentTypeScope()`). Реестр `DocumentAnalyzerRegistry` несёт
четыре `*For()`-метода (`promptStepsFor`/`promptStepFor`/`purposesFor`/`purposeFor`) и инвариант
сборки §5.4 п. 2 (значения анализатора не пересекаются с ядерными — доказано мутацией и тестом).
Валидация `step`/`purpose` поставлена на четыре пути записи: `PromptSeeder`/`AiRoutingSeeder`
(через реестр) и обе Filament-формы (`PromptForm`/`AiRouteForm` — зависимый `Select`, D103).
34 правимых файла кода + 6 новых enum-единиц + 2 снесённых + 23 теста + 7 документов; `artisan test`
764 passed/1 skipped/0 failed, `phpstan` 0 errors, `pint --dirty --test` зелёный. Три Known
Deviations (гейт Validation 5 без `\b` — реопен-кандидат `plan-design`; точечная правка
`phpstan-baseline.neon`, минус одна осиротевшая запись; тестовая фикстура `PromptSuggestTest`) —
детали `phases/P2.md` P2.5 Completion Notes/Known Deviations.

**Remaining:** 24 items ⬜ (P2.6 → P3 ×6 → P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4). Батчи B1/B2 и два
оставшихся гейта владельца (P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения) и §5 (**D1–D104**) ·
**ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью со `supersedes` ·
`research/03-step-purpose-seam-callsites.md` (карта периметра P2.5, слой 2) ·
`findings/filament-dependant-select-2026-07-30.md` · `roadmap.md` · `phases/P1.md` (`## Phase
Handoff`) · `phases/P2.md` (P2.1–P2.5 Completion Notes, ТЗ P2.6).

**Lint:** заполняется на bookkeeping-коммите этого захода (шаг 4 топологии закрытия) — см. отчёт
сессии.

**Open risks:**
- **Validation 5 item'а P2.5 несёт грепу без границы слова** (`grep "PromptStep::cases\|
  LlmPurpose::cases"`) — ловит легитимные `Core/EstimatePromptStep::cases()`, введённые самим
  item'ом (Rule 14 ТЗ, инвариант реестра §5.4 п. 2); зелёный исход недостижим в границах Scope
  Included этого же item'а (D69-класс, обнаружен на закрытии). Реопен-кандидат `plan-design`:
  форма `\b(PromptStep|LlmPurpose)::cases` (подтверждена в Completion Notes P2.5 — 0 строк).
- **Периметр `Files` у items этого плана систематически уже фактического** — P2.5 был четвёртым
  случаем подряд (P1.6, P2.4, сам P2.5 при эскалации) и починен пересбором грепом по FQN. Перед
  запуском P3.2–P3.5 и P4.1 сверять `Files` тем же способом.
- **Тот же класс, что вызвал эскалацию P2.5, живёт у P3.2, P3.3, P3.5** — их Validation грепает
  файлы, переезжающие внутри того же item'а. Гейт D89 (прогон на `<base>` до первого коммита) их
  поймает, но дешевле поймать `plan-design`'ом заранее.
- **`PromptCritic` остаётся вне списка ядра §6.2** (контур тюнинга снимает P3), поэтому инвариант
  «шаг анализатора = стадия извлечения» (D102) arch-тестом P2.6 НЕ защищён — только докблоком и тестом.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре** (`AnalysisStoreRepository::
  firstOrCreate()` `document_type` не пишет) — задевает точку (г) D101/фолбэк на `DocumentTypeScope::
  Any` в `ai:prompts:suggest`; долг остаётся, кандидат за P3.5 (наблюдалось конкретно в тесте
  `PromptSuggestTest` --dry-run, фикстура поправлена, код — нет).
- **Два файла документации несут устаревшие пути перемещённых классов** —
  `docs/03_Dev/ai/feature-card-estimate-extraction.md:13` и `docs/03_Dev/ai/training-loop.md:21`.
  Кандидат в scope P7.3/P7.4 либо fix-item.
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода в этом проекте**
  (кэш конфига замораживает dev-БД → `TestEnvironmentGuard` штатно прерывает прогон).
- **`--env=testing` НЕ переключает `DB_DATABASE` в этой раскладке** — `migrate:fresh --seed`
  вне `artisan test`/Pest бьёт по dev-БД `agelto`, не по `agelto_test`; допустимо по D13 (данных
  нет), но команду Validation 12 впредь стоит явно префиксовать `-e DB_DATABASE=agelto_test`, не
  полагаться на `--env`.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`) — наследуется из P2.1, не переоткрывалось.
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено. Кандидат в scope P5.5 либо item P5.7 — по требованию владельца.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · `config/ai.php` несёт докблочные упоминания старых имён таблиц · четыре+одна
  строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем
  (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом и для сторожей инварианта (D100); применено в P1.1–P1.6, P2.1,
  P2.3, P2.5 (Validation 3, 11) · `docker exec agelto_app` вместо `docker compose exec app` ·
  env-префикс на шаге `config:cache` · `pint --dirty` автофикс — точечными Edit-правками ·
  `phpstan-baseline.neon` правится адресным удалением осиротевшей записи, а не полной регенерацией ·
  гейт «дифф перемещённых файлов» требует `git diff -M` со старыми и новыми путями вместе ·
  `$row->getAttribute('purpose')` вместо магического доступа к свойству — устраняет
  `argument.unresolvableType` без `@property`-докблока (запрещён Rule 15 P2.5)
- deferred: BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым
  прогоном (D52) · остаточная дупликация `estimate:dataset:import` (D57) · перенос дрейфа в
  `CLAUDE.md` — P7.4 (D60/D64, пять строк Pending Work) · общий инвариант «во всём сервисном слое
  нет прямых обращений к `LlmCall`» — в план НЕ вводится (D70/D79) · переезд экранов транспорта из
  `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) · expression-индексы по `type_profile`
  — не заводятся до 10 000 строк реестра · `resources/ai/prompts/README.md` пример front-matter без
  `document_type` · `config/ai.php` докблочные упоминания старых имён таблиц · раскладка DTO сметы
  под сегмент `Analyzers\Estimate` — решает P3.5 · подкаталоги внутри `Analyzers/Estimate` (раскладка
  плоская) · `AnalysisSummary::$detailRoute` = `null` до появления предметного экрана сметы (P5) ·
  `docs/specs/v2/07-carryover-from-v1.md:107` и `CLAUDE.md:60` про состав носителя — P7.3/P7.4 (D104) ·
  реопен-кандидат Validation 5 P2.5 (`\b`-форма) — `plan-design`
- open_questions: незакрытых вопросов в границах плана нет. Остаются два гейта владельца впереди
  (P3.1, P4.2). Вне плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
