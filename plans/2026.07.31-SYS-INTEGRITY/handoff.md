# HANDOFF — 2026-08-01 — after P2.10

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Правка ровно двух классов (`JsonSchemaGenerator`/`GeminiResponseSchemaAdapter`): эмиссия `description` в исходящую JSON Schema и снятие `ksort`/`sort` в пользу порядка конструктора — снимает расхождение порядка свойств между схемой и печатаемым исходником (P2.1 дефект). Correctness-critical, effort high обязателен (§9 Routing) |

```
### P2.4 — Эмиссия описаний и порядка свойств в исходящую схему

**Status:** ⬜ Not started
**Intent:** Довести описания до модели ТЕМ каналом, которым уходит структура, и свести оба
носителя контракта к ОДНОМУ порядку свойств: `JsonSchemaGenerator` начинает эмитить
`description` и теряет `ksort` (свойства идут в порядке конструктора), а
`GeminiResponseSchemaAdapter` перестаёт терять новые ключи. `propertyOrdering` НЕ эмитируется
(D89).
**Why:** Это шаг 2 порядка D8 — без него дублирование каналов снять нельзя: описания есть в
атрибутах, но модель их не видит. Правка ровно двух классов, а не одного: адаптер пересобирает
объектный узел из `type`/`properties`/`required` и молча отбрасывает всё остальное, то есть
`description`, добавленный только в генераторе, до провайдера не доедет (D21). RAG:—
(repo-grounded: `app/Services/Llm/Schema/GeminiResponseSchemaAdapter.php:91-111` —
`resolveObject()` возвращает три ключа; `app/Services/Llm/Providers/PrismGeminiProvider.php:162`
— схема уходит `RawSchema` в `response_schema`).
Второе — здесь чинится найденный P2.1 дефект дерева: два носителя контракта расходятся по
порядку свойств (схема алфавитная из-за `ksort`, печатаемый исходник — в порядке конструктора;
для `EstimateRow` это два совершенно разных порядка), а вендор называет такое расхождение
источником «incorrect or malformed output». Пока носителя два (печать снимается только в P2.5),
согласование обязано быть явным правилом. `propertyOrdering` при этом НЕ нужен: для линейки 3.x
порядок ключей схемы сохраняется неявно, а неподдерживаемые ключи вендор игнорирует — эмиссия
была бы мёртвым кодом с видимостью работы (D89).
RAG:✅ 2026-08-01 (`findings/P2-vendor-structured-output.md` §1, §3).
**Scope Included:** чтение описаний и алиасов ридером P2.3 в `JsonSchemaGenerator` и эмиссия
`description` на свойствах; **снятие `ksort($properties)`/`sort($required)`** и эмиссия свойств в
порядке конструктора (`ReflectionMethod::getParameters()`), `required` — тем же порядком (D89);
сквозной пронос новых ключей через `GeminiResponseSchemaAdapter` (объектные узлы, узлы массивов,
`anyOf`-ветки nullable); обновление юнит-тестов генератора и адаптера; проверка когерентности
«что видит модель» между схемой и блоком схем промта, включая СОВПАДЕНИЕ порядка свойств двух
носителей; названная в отчёте разовая инвалидация `rules_hash` и живых explicit-кэшей.
**Scope Excluded:** сокращение системной инструкции (P2.5); тексты промтов (P2.6); смена формы
самой схемы (плоскость, `$defs`, глубина разворота `MAX_SELF_DEPTH`) — фаза не пересматривает
решения P3 плана `2026.07.26-EST-TRAIN`; правка провайдера и способа передачи схемы; состав
полей контрактов (P5); **эмиссия `propertyOrdering`** (снята D89 — не «отложена», а признана
ненужной для линейки 3.x); порядок КЛАССОВ в блоке схем промта (по короткому имени, `*Response`
последними) — вендорское требование про порядок СВОЙСТВ внутри объекта, порядок классов не
меняется.
**Inputs:** `plans/2026.07.31-SYS-INTEGRITY/findings/P2-vendor-structured-output.md` (что канал
`response_schema` поддерживает — вердикт по `description`/`propertyOrdering`) ·
`plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md` (правило порядка свойств) ·
Deliverables P2.3 (ридер и покрытые атрибутами контракты)
**Files:** `app/Services/Llm/Schema/JsonSchemaGenerator.php` ·
`app/Services/Llm/Schema/GeminiResponseSchemaAdapter.php` ·
`tests/Unit/Llm/JsonSchemaGeneratorTest.php` ·
`tests/Unit/Llm/GeminiResponseSchemaAdapterTest.php` ·
`tests/Feature/Ai/PromptCompositionTest.php`
**Required Reads:** `plans/2026.07.31-SYS-INTEGRITY/findings/P2-vendor-structured-output.md` ·
`plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md` ·
`app/Services/Llm/Schema/JsonSchemaGenerator.php` ·
`app/Services/Llm/Schema/GeminiResponseSchemaAdapter.php` ·
`app/Services/Llm/Schema/ContractAttributeReader.php` (Deliverable P2.3, D92) ·
`app/Services/Llm/Providers/PrismGeminiProvider.php` (строки 140–170) ·
`tests/Unit/Llm/JsonSchemaGeneratorTest.php` · `tests/Unit/Llm/GeminiResponseSchemaAdapterTest.php`
**Implementation Rules:** Эмитится только то, поддержку чего подтвердил `findings/P2-vendor-
structured-output.md` для канала `response_schema`: не подтверждено — не эмитится, а
фиксируется в Pending Work и в `research/03` (ключ, который канал не понимает, — источник 400 и
молчаливой деградации). Детерминированность выхода сохраняется байт-в-байт между запусками: это
база `rules_hash` и префикса implicit-кэша (`JsonSchemaGenerator` докблок) — но носителем
детерминизма становится ПОРЯДОК КОНСТРУКТОРА, а не алфавит: `ksort($properties)`/`sort($required)`
снимаются, порядок конструктора так же детерминирован и меняется ровно тогда, когда меняется
исходник контракта (D89). `propertyOrdering` НЕ эмитируется — не «на всякий случай» и не «пока
отложено»: для 3.x он не нужен, а неподдерживаемые ключи вендор игнорирует, то есть эмиссия дала
бы мёртвый код с видимостью работы. Смена порядка ключей меняет байты схемы и `rules_hash` — это
разовая инвалидация живых explicit-кэшей (`LlmCacheManager::validHandle()`) и обнуление
implicit-префикса: замер M2 идёт на ХОЛОДНОМ кэше, это ожидаемая разовая цена шага, она
называется в отчёте, а не скрывается и не выдаётся за регрессию качества. Адаптер обязан
проносить новые ключи на ВСЕХ уровнях (объект, элементы массива, ветки `anyOf`), а не только на
корне — ровно тот класс ошибки, который уже ловили на `additionalProperties` (докблок адаптера).
Ридер атрибутов не дублируется внутри генератора: используется класс P2.3.
**Code Guidance:** Правка узкая: генератор получает описание/порядок из ридера и кладёт ключи;
адаптер перестаёт терять ключи. Никакого попутного рефакторинга формы схемы. Тесты — Pest, Unit,
на фикстурных Data-классах теста; обязательные кейсы: `description` доезжает до КОРНЯ, до
ВЛОЖЕННОГО объекта, до элемента МАССИВА и до nullable-свойства (`anyOf`-ветка); порядок ключей
`properties` совпадает с порядком КОНСТРУКТОРА фикстуры и НЕ является алфавитным (фикстура
подбирается так, чтобы два порядка различались — иначе кейс зелёный по совпадению); `required`
собран тем же порядком; ключа `propertyOrdering` в выходе НЕТ; контракт без атрибутов даёт
прежнюю схему с точностью до порядка свойств (обратная совместимость по составу ключей).
Плюс кейс когерентности: множество классов блока схем шага ⊇ множество классов схемы того же
шага (инвариант `DataTypeGraph`) не сломано, и порядок свойств одного класса в двух носителях
совпадает. Запрещено: эмитить `description` дублем и в `properties`, и в отдельный блок; эмитить
`propertyOrdering` (снят D89); возвращать `ksort`/`sort` «ради стабильности» — стабильность даёт
конструктор; ослаблять существующие ассерты тестов генератора/адаптера
ради зелёного — тест переписывается под новую форму, а не удаляется; трогать `MAX_SELF_DEPTH`;
править провайдера.
**Validation:** `docker compose exec app php artisan test --filter=JsonSchemaGenerator` зелёный и
содержит четыре обязательных кейса доезда `description` плюс кейсы порядка (порядок = конструктор,
не алфавит; `required` тем же порядком; `propertyOrdering` отсутствует);
`docker compose exec app php artisan test --filter=GeminiResponseSchemaAdapter` зелёный;
`docker compose exec app php artisan test --filter=PromptComposition` зелёный (по одному
`--filter` за прогон); `grep -rn "ksort\|propertyOrdering" app/Services/Llm/Schema` — ни одного
вхождения в путях эмиссии схемы; `docker compose exec app php artisan estimate:eval:run --split=all` +
`estimate:eval:compare --base=<id-после-P2.3> --candidate=<id> --criterion=no-regression`
→ `verdict: accept`, дельта сопоставлена с измеренным шумом M0₂↔M0₂′ (P2.10, D100), числа в
`research/03-prompt-contract.md`; pint чистый;
`phpstan-baseline.neon` не вырос.
**Deliverables:** генератор и адаптер, доносящие `description` до провайдера и эмитящие свойства
в едином порядке конструктора (без `propertyOrdering`, без `ksort`);
обновлённые юнит-тесты с обязательными кейсами; запись eval-дельты шага 2 (M2) в
`research/03-prompt-contract.md` с вердиктом критерия и с названной ценой разовой инвалидации
`rules_hash`/explicit-кэшей (замер на холодном кэше).
**Completion Notes:** —
**Pending Work:** —
**Known Deviations:** —
**Escalation Needed:** no
```

Либо: `/model sonnet` + `/effort high` + `/task:plan-run 2026.07.31-SYS-INTEGRITY P2.4` (D18) —
сессия уже несёт этот тир (sonnet/high), доп. переключения не требуются.

**Done:** **P2.1 🟢** (`ced2a4f`) · **P2.8 🟠** (`b51ce3a`+`b05235f`) · **P2.2 🟢**
(`19f9808`+`684ddfb`) · **P2.3 🟠** (`bcd7ad4`) · **P2.9 🟠** (`b667b3e`) · **P2.10 🟢** (`ed202ac`).

**Этим заходом (`plan-run`, sonnet/high) закрыт P2.10 🟢** — пере-baseline контура на дереве после
фикса P2.9 (якорь `d91f86f`, item-коммит `ed202ac`). Два прогона `estimate:eval:run --split=all`
подряд, без единого изменения между ними: **M0₂ = EvalRun #4** — 6/6 эталонов обработано (было
4/6 на M0/M0′) — старый флейк по токен-лимиту Gemini закрыт P2.9 полностью; **M0₂′ = EvalRun #5**
— 5/6, `dataset_example #7` (`EST-XLSX-KS2-001`) упал НОВОЙ ошибкой `bcsub(): Argument #2 ($num2)
is not well-formed` (не токен-лимит — другой класс дефекта, ранее маскировавшийся старым флейком).
`estimate:eval:compare --base=4 --candidate=5` — **reject** обоими критериями (`--format=md` и
`--criterion=no-regression` дали идентичный вердикт): `holdout.overall.f1` -0.2886,
`holdout.row_coverage` -0.3807, `EST-XLS-KS2-001` -0.1013, `EST-XLSX-KS2-001` -0.6131. Новая
величина шума контура (D100, число отменяет D91, правило D91 остаётся в силе):
`tune.overall.f1`=0.0652, `holdout.overall.f1`=0.2886, `tune.row_coverage`=0.1377,
`holdout.row_coverage`=0.3807, per-fixture (худший)=0.6131 (`EST-XLSX-KS2-001` — доминирующий
флейк сменился со старого `EST-XLS-KS2-001`, чья дельта упала до -0.1013, что подтверждает: P2.9
закрыл ровно тот дефект, который был назван). База для M2 (P2.4) — **M0₂ (EvalRun #4)**, не
исходный M0 (D100 п.3). Полный дословный отчёт — `findings/P2-eval-baseline.md` §7; таблица
замеров `research/03-prompt-contract.md` §8/§8.7 обновлена.

**Material-отклонений нет** (статус 🟢): item без диффа кода выполнен строго по Scope Included,
все команды Validation прогнаны, оба вывода записаны дословно. Новый дефект `bcsub()` на
`EST-XLSX-KS2-001` — не отклонение исполнения этого item'а: Implementation Rules P2.10 прямо
предписывают именно «назвать поимённо и вынести в Pending Work», а не чинить/перемерять; это
и сделано.

**Lint:** `python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py
plans/2026.07.31-SYS-INTEGRITY --baseline HEAD` — итог прогона этого захода назван в финальном
сообщении сессии.

**Remaining:** 39 item из 55. Внутри фазы: P2.4 → P2.5 → P2.6 → P2.7. Дальше по очереди D63:
`P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.16.0; §3 Routing поitem'ный, строка P2.4 —
implementation/high/manual/full; §5 D1–D100; §4 Status Board — P2 3/10 🟢, счётчик не включает 🟠) ·
`phases/P2.md` (ТЗ items; Phase Context — очередь `P2.1 → P2.8 → P2.2 → P2.3 → P2.9 → P2.10 →
P2.4 → P2.5 → P2.6 → P2.7`) · **`findings/P2-eval-baseline.md`** §7 (пара M0₂/M0₂′, дословный
вывод, новая величина шума) · `research/03-prompt-contract.md` §8/§8.7 (лестница замеров, база
M2=M0₂) · `findings/P2-vendor-structured-output.md` (что канал `response_schema` поддерживает —
вход P2.4) · `findings/P2-extraction-token-budget-2026-08-01.md` · `roadmap.md` · `index.md` ·
`open-questions.md` · `journal.jsonl` · `brief/01-refinements.md`.

**Open risks:**

- **Новый дефект контура `bcsub()` на `EST-XLSX-KS2-001`** (P2.10 Pending Work, наблюдался на
  EvalRun #5/`d91f86f`) — вне Scope P2.10, требует отдельного follow-up item'а
  (`bcmath`/сравнение числовых полей получает нечисловую строку); не блокирует P2.4 (у P2.4
  свой платный прогон и своё сравнение M2↔M0₂, риск — ложный `reject` от ЭТОГО эталона, если
  дефект флейковый как старый; если детерминированный — эталон стабильно теряется, тоже
  наблюдаемо на M2).
- **Потолки вывода `gemini-3.1-flash-lite` и `gemini-3.1-pro-preview` `[UNVERIFIED]`** — RAG их не
  подтвердил, ключ `max_output_tokens` этим моделям НЕ поставлен (D99, осознанная граница).
- **Отдельная величина «дельта P2.3» утрачена навсегда** (D100 Consequences) — дифф P2.3
  структурно неотделим от фикса P2.9.
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (P2.8 Known Deviations).
- **Дешёвый size-gate слеп на легаси `.xls`** (P2.8 Scope Excluded).
- **DB частично гидрирована, НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=17,
  `prc_ai_eval_runs`≥5 (M0, M0′, M1, M0₂, M0₂′). **Не запускать `migrate:fresh` до закрытия фазы P2.**
- **`EST-PDF-SCAN-001` (id 7 в манифесте фикстур)** в шестёрку обязательных gold НЕ входит,
  baseline не блокирует.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — сопоставимо внутри P2, не с числами донора.
- **Деньги.** Дальше — по одному платному прогону на шаг (P2.4–P2.6), плюс три на варианты OQ-2
  в P2.5.
- **Расхождение порядка свойств между двумя носителями живёт в боевом коде СЕЙЧАС** — чинится P2.4.
- **Пол префикса может не сработать как защита** — байтовый якорь не переведён в токены,
  `[UNVERIFIED]` для `gemini-3.1-flash-lite`. Обязанность P2.5 (D90).
- **Канал проекта помечен вендором Legacy** — вход EOL-мониторинга, P2.7 фиксирует строкой риска.
- **Строка Update Log для `plan-audit P1` (заход 2) отсутствует** — вне скоупа.
- **A1 аудита P1 (Major) не закрыт**: потребление `ProxyRequirements->country` не покрыто
  дискриминирующим тестом — решение владельца.
- **Partition-drift — падения полного сюита вне предмета плана** (`llm_calls` — нет партиции под
  текущую дату; ~9 тестов) — фикс-предложение `phases/P1.md` P1.10 Pending Work. Два красных
  arch-теста `CoreKnowsNoDocumentTypeTest` исключены поимённо (D86).
- **`verify-citations.py` — находки вне диффа**; инструмента в `plans/` этого репозитория нет (D76).
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет фазы.
- **`(array) $provider->options` в `LlmRouteResolver::toResolvedRoute()` несёт ТОТ ЖЕ класс
  дефекта**, что был исправлен для `$route->options` в P2.9 — дормантно, PROMOTE-CANDIDATE.
- **`docker compose` на хосте**: `MINIO_ROOT_PASSWORD`-интерполяция падает — обходка
  `docker exec agelto_app` рабочая.
- **Незапланированные файлы в рабочем дереве**: `M .env.example`, untracked `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife/`,
  `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/.swissknife/` — чужой
  дифф из `plan.md` §2, не трогать.
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется перед стартом P5.
  **P4.7 — точка невозврата по схеме**: P5 и P6 обязаны быть терминальны до него.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* `docker exec agelto_app` вместо `docker compose exec app` в неинтерактивных
  оболочках агента.
- *Deferred:* строка Update Log для `plan-audit P1` захода 2 · A1/A2/A3/A6 аудита P1 · правка ТЗ
  P1.2/P1.3 (D20) · перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  положения (3)–(5) целевой модели хранения (D72) · переход транспорта ЕИС на XML/SOAP (D14) ·
  переименование дисков (D53) · судьба таблицы кэша (D75) · связь «выделенные узлы под конкретный
  профиль» (D80) · `ProxyForm::COUNTRIES` (D86) · починка двух pre-existing
  `CoreKnowsNoDocumentTypeTest` (D86) · разовый флейк `CsvBlockIngestorTest` · partition-drift
  (P1.10 Pending Work) · миграция на Interactions API вендора (мониторинг) · починка слепого
  size-gate на легаси `.xls` (P2.8 Scope Excluded) · статус `EST-PDF-SCAN-001` (вне шестёрки gold) ·
  решение о диспетче `PrepareDocumentJob` из `estimate:gold:import --prepare` (P2.8 Pending Work) ·
  деградация оконного пути (`WindowedChunkSource`) при MAX_TOKENS — колбэка нет осознанно (P2.9
  Scope Excluded) · потолки вывода `gemini-3.1-*` (`[UNVERIFIED]`, D99) ·
  `(array) $provider->options` в `LlmRouteResolver` — тот же класс дефекта, что чинен для
  `$route->options` в P2.9, дормантен · **новый дефект `bcsub()` на `EST-XLSX-KS2-001`**
  (P2.10 Pending Work) — follow-up item, решение владельца/`plan-design`.
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2** (судьба `PromptContextGenerator`) — снимает
  P2.5 · OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый
  контур, сверяется в P7.8).
