# HANDOFF — 2026-07-29 — after P1.3

**Next:** `/task:plan-design 2026.07.28-EST-ARCH P1.4` — **не** исполнение, а закрытие дыр в ТЗ.
Три контракта фазы заморожены (P1.1/P1.2/P1.3), но `Files` исполнительских items их не покрывают, а
после решений владельца D91/D92 ТЗ P1.6 прямо противоречит контракту. Стартовать P1.4 с неполными
`Files` значит уйти в дифф вне `Files` — материальное отклонение по построению.

| Параметр | Значение |
|:--|:--|
| Model class | frontier (`opus`) |
| Effort | high |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | Достроить `Files`/Scope/Validation items P1.4 и P1.6 под три замороженных контракта и под D91–D94; поправить Execution Rules §2 (crawling-контур) |

````
/task:plan-design 2026.07.28-EST-ARCH P1.4
````

После него — P1.4 (`Exec = manual`, `implementation/high`, solo): `/model sonnet` + `/effort high`
+ `/task:plan-run 2026.07.28-EST-ARCH P1.4` (D18). Гейта владельца у P1.4 нет.

**Правило исполнения гейтов (D89 + трактовка владельца 2026-07-29) — читать ДО первого коммита
ЛЮБОГО item'а.** Каждая строка `Validation`, являющаяся греп/`git`-командой, прогоняется на
`<base>` и печатается в Completion Notes рядом с выводом «после». Исходы: на `<base>` красный →
норма; на `<base>` уже зелёный → эскалация §10 до написания кода; зелёный недостижим в границах
`Scope` → эскалация §10. **Третий случай** (решение владельца, применён в P1.1–P1.3): если гейт
стоит над СОЗДАВАЕМЫМ артефактом (`root/**`), на `<base>` он не может дать красного вовсе — файла
нет, команда падает с exit 2; тогда фальсифицируемость доказывается мутацией (копия артефакта вне
репо + инъекция нарушения + красный вывод в Completion Notes). Касается ещё P2.1, P3.1, P4.2.
**Практика P1.2/P1.3:** отдельно убедиться, что гейт ловит РОВНО заявленное — в P1.3 шаблон карты
`^\| [1-8] \| \`` проверялся построчно, чтобы под него не попали ряды соседних таблиц.

**НОВОЕ правило исполнения (D94):** боевые LLM-прогоны запускает ВЛАДЕЛЕЦ. Агент пишет код и тесты
(LLM в тестах — только `FakeLlmProvider`); item, чья Validation требует боевого вызова, закрывается
с записью «прогон за владельцем» и неисполненным не считается. Прямо касается P5.2 и любых
`estimate:bench:*`/`estimate:eval:*` на живом провайдере.

**Done:** **P1.1 🟢** (`a17e1ab`) — `root/naming-canon.md`, 42 строки-данных, семь классов
расхождений. Ключевое: `AiCallStoreRepository` → `App\Repositories\Llm\LlmCallStoreRepository`;
`prc_ai_fixtures.doc_type` → `document_type`; `EstimateType::Estimate` → `LocalEstimate`; 7 команд
остаются `ai:*`, 11 уезжают в `estimate:*`; домен транспорта — `Provider`, `LlmRoute`, `Prompt`,
`LlmCall` в `App\Models\Llm\**` с таблицами `llm_*`; сущность прогона — `AnalysisRun` /
`prc_ai_analysis_runs`; трейты — `App\Models\Concerns`.

**P1.2 🟢** (`56d2a10`) — `root/document-type-axis.md`, 464 строки, замороженный контракт на ВОСЕМЬ
таблиц. Ключевое: носитель кросс-документного назначения — ненулевой сентинел `'any'`
(`App\Enums\Llm\DocumentTypeScope`); check-constraint по `AiDocumentType` на всех восьми колонках;
ключ промта `(document_type, key, version)`, ключ маршрута `(document_type, purpose, priority,
shadow)`; реестр эталонов несёт ДВЕ оси (`document_type` + `owner_document_type`); пять полей D18 →
`type_profile jsonb` без индексов; заводится `title text NOT NULL` (D90).

**P1.3 🟢** (`b1b7c68`) — `root/document-contour.md`, 534 строки. **Владелец отверг первую редакцию
по трём несущим решениям и переопределил их:**
- **D91** (`supersedes D24` в части носителя): ось контура — **ПЛОЩАДКА**, а не enum происхождения.
  `prc_documents.platform_id bigint NOT NULL` FK → `prc_platforms`; служебная площадка
  `code='training'`/`name='Обучалка'`/`is_active=false`/`is_public=false`; новая колонка
  `prc_platforms.is_public boolean NOT NULL DEFAULT true`, `base_url` → nullable. Новая служебная
  площадка впредь заводится СТРОКОЙ, без миграции (`PlatformOnboardingTest` это уже фиксирует).
- **D92** (`supersedes D25` в части объёма): **лимита прогона НЕТ** — «пропускать мы ничего не
  должны». Колонок `max_llm_calls`/`truncated_reason` не будет; риск переплаты закрывают partial
  unique `(document_version_id) WHERE finished_at IS NULL`, видимая цена запуска и D94.
- **D93**: вызовы LLM ссылаются на прогон НАПРЯМУЮ — `llm_calls.analysis_run_id NULL` + index.

Остальное контракта: связь `prc_ai_fixtures.document_id NULL UNIQUE` `ON DELETE SET NULL` (1 эталон
→ ≤1 документ → N версий, ссылку держит реестр); `prc_ai_analysis_runs` (`mode` auto/manual,
`status` 4 кейса, слепок расхода именами как в `prc_ai_llm_calls`, `initiated_by`);
`prc_ai_analyses.analysis_run_id NOT NULL` с привязкой внутри `AnalysisStoreRepository`
(исключение вместо nullable); правило показа — `is_public` + один именованный
`PublicPlatformScope` + `prc_estimates.platform_id NOT NULL`; **построчная карта восьми строк
грепа**: шесть остаются структурными, две уходят (`FormatBenchRunner:114` и его докблок `:45`) —
переводятся на связь с эталоном, а не на площадку. Инварианты `prc_ai_analyses` не тронуты.

**Remaining:** 34 items ⬜ Not started. Фаза P1 — 3/8. Порядок: P1.4–P1.8 (исполнение по трём
контрактам) → P2 (6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) → P7 (4). Батчи B1/B2 и оставшиеся три
гейта владельца (P2.1, P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` (схемы, статусы, DoR) ·
`plans/2026.07.28-EST-ARCH/plan.md` §2 (правила исполнения) и §5 (**D1–D94**) ·
**`root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md` — ЗАМОРОЖЕНЫ,
имена и схема берутся только оттуда** · `roadmap.md` · `phases/P1.md` — четыре блока аудита в конце
файла · `brief/{00-brief,01-refinements}.md` и `research/00-user-intent.md` ·
`findings/structure-review-2026-07-29.md`.

**Open risks:**
- **Дыры в `Files` последующих items — главный блокер, поэтому Next = `plan-design`.** P1.4 не
  несёт `estimates/manifest.json`, `FixtureManifestEntry`, `FixturesManifestCommand`,
  `ScreeningGoldBuilder`, девять файлов `resources/ai/prompts/*/*.md`, `PromptSeeder`,
  `PromptForm`/`AiRouteForm`. P1.5 несёт только старый путь Filament-ресурса маршрута и не несёт
  миграцию вызовов под `analysis_run_id` (D93). P1.6 не несёт: миграцию `prc_platforms` и модель
  `Platform`, сидер служебной площадки, миграцию таблицы вызовов, `DocumentIndexer`,
  `EvalDiffCommand`/`PromptsSuggestCommand`, `GoldExampleRecorder`, `PipelineStuckCheck`, шесть
  точек создания прогона и точки его терминации. P3.5 не несёт `AiRouteResolver` и его 22
  файла-потребителя.
- **ТЗ P1.6 прямо противоречит контракту после D91/D92:** говорит про «ось происхождения», «enum
  происхождения», «признак усечения» и требует теста «усечённый лимитом прогон помечен усечённым».
  Исполнение по нему как есть даст артефакт, которого контракт не заказывал.
- **Execution Rules §2 плана** объявляют crawling-контур (`Crawler`, `Platform`, `Eis`)
  неприкосновенным — D91 это переопределил в границах двух колонок `prc_platforms`. Текст правила
  надо привести в соответствие, иначе следующий исполнитель эскалирует на ровном месте.
- Implementation Rule 5 самого P1.2 описывает несуществующий сейм («репозитории промтов/маршрутов»)
  — факт зафиксирован в контракте P1.2 §5, текст правила стоит поправить тем же `plan-design`.
- Переход фазы в 🟡 зажёг 2 WARN `plan-lint` на тексте DoR-чеклиста — ложные срабатывания на
  формулировках, повторятся у P2–P7, лечатся переформулировкой DoR во всех семи фазах.
- Гейты C5–C10 и неизвестный хвост того же класса покраснеют на прогоне `<base>` — ожидаемо (D89).
- Три строки Pending Work про `CLAUDE.md` живут шесть фаз (D60/D64). Рычаг `@property` может не
  сработать (D63/D53). Три гейта владельца (P2.1, P3.1, P4.2) и боевой прогон P5.2 не закрыты.

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта мутацией вместо прогона на `<base>` — для
  items, создающих `root/**` (решение владельца 2026-07-29); применено в P1.1, P1.2 и P1.3
- deferred: ревизия формы оставшихся гейтов на этапе дизайна — снята D89 · BigQuery-адаптер
  биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым прогоном (D52) · остаточная
  дупликация `estimate:dataset:import` (D57) · перенос дрейфа в `CLAUDE.md` отложен в P7.4
  (D60/D64) · общий инвариант «во всём сервисном слое нет прямых обращений к `LlmCall`» в план НЕ
  вводится (D70/D79) · переезд экранов транспорта из `app/Filament/Resources/Procurement/Ai/**` —
  вне D22 · expression-индексы по `type_profile` — не заводятся до 10 000 строк реестра ·
  эксплуатационный отбор «какие документы пускать в дорогой разбор» — вне схемы (D92)
- open_questions: в границах плана — НЕТ. OQ-12 → D91, OQ-13 → D92, OQ-14 → D93 закрыты 2026-07-29
  на гейте P1.3. Вне плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга) —
  тратит реальные деньги, несёт два гейта владельца
