# HANDOFF — 2026-08-01 — after P2.4

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/high

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Снять печать структуры контрактов из системной инструкции, решить OQ-2 (носитель синонимов `EstimateHeaderMap`: A/B/C) измерением трёх вариантов против прогона после P2.4, держать пол системного префикса 4096 токенов (D90). Открытое design-решение по измеренной дельте — effort high обязателен (§9 Routing), sonnet (session tier `implementation`) НИЖЕ предписанного `frontier` — `plan-run` для этого item'а недоступен структурно (Routing-гейт `plan-exec.md` п.2 остановил бы его тем же кодом) |

```
### P2.5 — Сокращение системной инструкции и судьба `PromptContextGenerator`

**Status:** ⬜ Not started
**Intent:** Снять дублирование каналов: перестать печатать структуру контрактов в системную
инструкцию и решить OQ-2 измерением — какой из трёх вариантов носителя словарей-синонимов
остаётся.
**Why:** Это шаг 3 порядка D8 и единственный шаг, ради которого делались первые два: пока
исходник контрактов печатается в промт, схема едет в модель двумя каналами вопреки рекомендации
вендора, а системный префикс несёт лишние токены на каждом вызове. Раньше снять его было нельзя
— описания жили только в докблоке; теперь они в схеме. Но `EstimateHeaderMap` в исходящую схему
уехать не может (он не часть ответа ни одного шага), поэтому вопрос «куда деваются синонимы»
решается замером, а не вкусом (OQ-2, D27). RAG:✅ 2026-07-31 (вендор: схему конфигурировать на
модели, системную инструкцию держать поведенческой — `findings/rag-2026-07-31.md` §3; ОГОВОРКА
D88: тезис «вендор запрещает дублирование» первичной проверкой НЕ подтверждён — второй канал
снимается по собственным основаниям фазы, а не по внешнему запрету).
**Ограничение снизу, которого в первой редакции ТЗ не было (D90):** implicit-кэш включается
только с **4096** входных токенов на `gemini-3.5-flash` и `gemini-3.1-pro-preview` — маршрутах
ВСЕХ трёх extraction-шагов и всех эскалаций. Сокращение системной части ниже порога выключит кэш
МОЛЧА: качество не изменится, а стоимость вырастет, и критерий непрегрессии D24 этого не увидит,
потому что он меряет качество, а не деньги. RAG:✅ 2026-08-01
(`findings/P2-vendor-structured-output.md` §5).
**Scope Included:** прекращение печати структуры контрактов ответа в системную инструкцию;
реализация и замер трёх вариантов носителя синонимов — **A** печать исходника ТОЛЬКО
классов-словарей (`EstimateStepDictionaries`), **B** синонимы текстом промта, **C** типизированный
рендер словаря по атрибутам через ридер P2.3 (без печати PHP-исходника); **замер длины системного
префикса КАЖДОГО шага до и после сокращения — в токенах, по маршруту шага** — и проверка его
против пола 4096 входных токенов (D90); выбор по измеренной
дельте с решающим правилом D27 и фиксацией новой D-записи; правка `PromptContextGenerator`/
`PromptComposer`/`EstimateStepDictionaries` под выбранный вариант; обновление их тестов;
фиксация изменения `rules_hash` и его последствий для кэша.
**Scope Excluded:** переписывание текстов правил промтов (P2.6 — здесь только УДАЛЯЕТСЯ блок
структуры, поведенческие тексты не трогаются); правка схемы и атрибутов (P2.3/P2.4); изменение
состава словарей шага (какие классы считаются словарями) — состав берётся как есть;
`CorrectionAwareComposer` и hints-контур.
**Inputs:** `plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md` (целевой вид
системной инструкции §7, разметка «структура / семантика / правило / словарь» §2, формы вариантов
OQ-2 §4) · `plans/2026.07.31-SYS-INTEGRITY/findings/P2-vendor-structured-output.md` (§5 — порог
implicit-кэша 4096 и отсутствие порога для Flash-Lite) ·
`plans/2026.07.31-SYS-INTEGRITY/findings/P2-eval-baseline.md` (измеренный шум M0↔M0′ — им
меряется ничья вариантов) · Deliverables P2.4
(описания реально доезжают схемой) · `plans/2026.07.31-SYS-INTEGRITY/open-questions.md` (OQ-2)
**Files:** `app/Services/Llm/Schema/PromptContextGenerator.php` ·
`app/Services/Procurement/Analyzers/Estimate/EstimateStepDictionaries.php` ·
`app/Services/Llm/PromptComposer.php` · `tests/Unit/Llm/PromptContextGeneratorTest.php` ·
`tests/Feature/Llm/PromptComposerTest.php` · `tests/Feature/Ai/PromptCompositionTest.php` ·
`plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md`
**Required Reads:** `plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md` (§4, §7, §8) ·
`plans/2026.07.31-SYS-INTEGRITY/findings/P2-vendor-structured-output.md` (§5) ·
`plans/2026.07.31-SYS-INTEGRITY/findings/P2-eval-baseline.md` ·
`plans/2026.07.31-SYS-INTEGRITY/open-questions.md` (OQ-2) ·
`app/Services/Llm/Schema/PromptContextGenerator.php` · `app/Services/Llm/PromptComposer.php` ·
`app/Services/Procurement/Analyzers/Estimate/EstimateStepDictionaries.php` ·
`app/Dto/Procurement/Analyzers/Estimate/EstimateHeaderMap.php` ·
`tests/Feature/Ai/PromptCompositionTest.php` · `tests/Unit/Llm/PromptContextGeneratorTest.php` ·
skill `prompt-engineering-agelto`
**Implementation Rules:** Решение OQ-2 принимается ПО ИЗМЕРЕННОЙ ДЕЛЬТЕ каждого варианта против
прогона после P2.4, а не по стилю; «шум» для ничьей — измеренный разброс M0↔M0′ из
`findings/P2-eval-baseline.md` (D91), а не пороги компаратора; ничья в пределах шума → выбирается
вариант с типизированным носителем (A или C), не B — требование брифа «любое общение идёт сквозь
DTO-объекты» (D27). **Пол системного префикса — жёсткое ограничение шага (D90):** после
сокращения длина системной части каждого extraction-шага и каждой эскалации на маршрутах
`gemini-3.5-flash`/`gemini-3.1-pro-preview` обязана оставаться ≥ 4096 входных токенов; замер
делается ДО и ПОСЛЕ, обоими числами, на собранной системной части шага (носитель числа —
`countTokens` провайдера; это не генеративный вызов и в бюджет корпуса не входит). Для
`gemini-3.1-flash-lite` (screening, discovery, prompt_critic) порог вендором не документирован
(`[UNVERIFIED]`) — число публикуется, но гейтом НЕ является. Выбор варианта делается по ПАРЕ
«качество + сохранность кэша»: вариант, дающий самый короткий промт, автоматически не выигрывает.
Если лучший по качеству вариант опускает префикс ниже порога — это не повод ни отбросить его
молча, ни «добить» префикс бессодержательным текстом: факт фиксируется числом и выносится
владельцу (цена кэша против качества), решение — новой D-записью.
Выбор фиксируется НОВОЙ D-записью со ссылкой на числа и снимает OQ-2 в `open-questions.md`.
Детерминированность системной части сохраняется байт-в-байт между запусками (база `rules_hash` и
префикса кэша): любой новый рендер сортирует детерминированно, как это делает сегодняшний
генератор. Инвариант безопасности НЕ ослабляется: шаги, читающие документ, обязаны сохранить
блок «недоверенный вход» в системной части (его проверяет `PromptCompositionTest`) — сокращение
режет структуру, а не правила безопасности. Смена `rules_hash` фиксируется явно: это разовая
инвалидация implicit-кэша, она называется в отчёте. Если вариант требует удаления
`PromptContextGenerator` целиком — удаляется вместе со своим тестом, покрытие переносится, а не
остаётся «на всякий случай» второй точкой печати.
**Code Guidance:** Никаких режимов-флагов «печатать/не печатать структуру» в проде: выбранный
вариант — единственный, ветвление под замер живёт в рамках item'а и в итоговый диф не попадает.
Тесты — Pest; обязательные кейсы: системная часть шага `extraction:rows` больше НЕ содержит
исходник контракта ответа (`grep`-ассерт по маркеру `// --- Source:` или его преемнику);
синонимы шапки присутствуют в том виде, который выбран вариантом; блок «недоверенный вход»
на месте у всех пяти читающих документ шагов; композиция каждого шага непуста. Запрещено:
решать OQ-2 без чисел; сокращать системную часть, не замерив префикс (D90) — «на глаз по байтам»
не годится: перевод символов в токены на кириллице неустойчив; ослаблять `PromptCompositionTest`
вместо переписывания под новую форму;
удалять словари шага заодно с печатью структуры (это разные факты); менять приоритеты и порядок
сборки правил в `PromptComposer` (порядок v1 — канон); совмещать в этом item'е правку текстов
промтов (D23 — одно изменение за замер).
**Validation:** `docker compose exec app php artisan test --filter=PromptComposition` зелёный и
содержит четыре обязательных кейса; `docker compose exec app php artisan test
--filter=PromptContextGenerator` зелёный или удалён вместе с классом; `docker compose exec app
php artisan test --filter=PromptComposer` зелёный (по одному `--filter` за прогон); дельты ВСЕХ
трёх вариантов (A/B/C) записаны в `research/03-prompt-contract.md` с id прогонов и сопоставлены с
измеренным шумом M0↔M0′, вердикт критерия `no-regression` для выбранного варианта = `accept`;
**длина системного префикса КАЖДОГО шага опубликована до и после (в токенах, командой замера и с
якорем на дерево), и для маршрутов `gemini-3.5-flash`/`gemini-3.1-pro-preview` число ПОСЛЕ ≥ 4096**
(для `gemini-3.1-flash-lite` число опубликовано без гейта); OQ-2 в `open-questions.md` имеет
статус `Resolved → D#`; pint чистый; `phpstan-baseline.neon` не вырос.
**Deliverables:** системная инструкция без структуры контрактов ответа; выбранный носитель
синонимов + D-запись, снимающая OQ-2 по числам; обновлённые тесты композиции; запись eval-дельт
трёх вариантов и таблицы «шаг → префикс до/после (токены) → порог» в
`research/03-prompt-contract.md`.
**Completion Notes:** —
**Pending Work:** —
**Known Deviations:** —
**Escalation Needed:** no
```

Либо: `/model opus` + `/effort high` + `/task:plan-run 2026.07.31-SYS-INTEGRITY P2.5` (D18) —
сессия сейчас несёт `sonnet/high` (`implementation`/high), Routing P2.5 предписывает `frontier/high`:
недобор класса модели, `plan-run` остановит сам себя `ROUTING-BLOCKED` тем же кодом, что и
`plan-exec`. Переключение модели обязательно ДО запуска.

**Done:** **P2.1 🟢** (`ced2a4f`) · **P2.8 🟠** (`b51ce3a`+`b05235f`) · **P2.2 🟢**
(`19f9808`+`684ddfb`) · **P2.3 🟠** (`bcd7ad4`) · **P2.9 🟠** (`b667b3e`) · **P2.10 🟢** (`ed202ac`) ·
**P2.4 🟠** (`4a4ce75`).

**Этим заходом (`plan-run`, sonnet/high) закрыт P2.4 🟠** — item-коммит `4a4ce75`.
`JsonSchemaGenerator` теперь читает `#[Description]` через `ContractAttributeReader` (P2.3) и
эмитит `description` на каждом свойстве схемы (объект/вложенный `$ref`/элемент массива/nullable
`anyOf` — все четыре обязательных кейса покрыты фикстурными Data-классами теста);
`ksort($properties)`/`sort($required)` сняты — оба массива идут в порядке
`ReflectionMethod::getParameters()` (D89); `ksort($defs)` НЕ трогается — вне предмета D89.
`GeminiResponseSchemaAdapter::resolveNode()` теперь снимает/восстанавливает `description` вокруг
единого разбора узла (`$ref`/`anyOf`/`array`/скаляр) — description проносится на всех уровнях, а
не отбрасывается вместе с прочими непонятыми ключами. `propertyOrdering` не эмитировался и не
эмитируется. Добавлен кейс когерентности в `PromptCompositionTest` — порядок свойств `EstimateRow`
в схеме совпадает с порядком в напечатанном исходнике шага `extraction:rows` (оба носителя читают
один и тот же файл, совпадение больше не случайность построения теста).

**Eval M2** (`estimate:eval:run --split=all` → **EvalRun #6**, 6/6 эталонов; `estimate:eval:compare
--base=4 --candidate=6 --criterion=no-regression`) — механический **`reject`**: компаратор
сработал на статичном пороге 0.050 по двум эталонам (`EST-XLS-KS2-001` −0.0651, `EST-XLSX-KS2-001`
−0.1538), но ОБА агрегата выросли (`tune.f1` +0.0299, `holdout.f1` +0.0135, `tune.row_coverage`
+0.1377, `holdout.row_coverage` +0.1439), и обе отрицательные per-fixture дельты — меньше по
модулю измеренного шума D100 ТЕХ ЖЕ эталонов (0.1013/0.6131) — неотличимы от уже
задокументированного флейка `EST-XLSX-KS2-001`/`bcsub()` (P2.10 Pending Work). Полный разбор по
подвыборкам и по эталонам — `research/03-prompt-contract.md` §8.8. Разовая цена (D89
Consequences): EvalRun #6 — холодный прогон по системной части (байты схемы/`rules_hash`
изменились), 37 вызовов, `cached_tokens`=201902 относится к document-level кэшу вложений
(Files API), не к системной части.

**Material-отклонение есть** (статус 🟠): (1) Validation-клауза `→ verdict: accept` не выполнена
буквально (факт `reject`, диагноз — noise-consistent, не регрессия); (2) item-коммит несёт дифф
вне `Files` item'а — `tests/Fixtures/Llm/step-json-schemas.json` (золотая фикстура, механически
пересобранная под новый вывод генератора, не самостоятельная правка). Оба — в Known Deviations
`phases/P2.md` P2.4.

**Lint:** `python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py
plans/2026.07.31-SYS-INTEGRITY --baseline HEAD` — итог прогона этого захода назван в финальном
сообщении сессии.

**Remaining:** 38 item из 55. Внутри фазы: P2.5 → P2.6 → P2.7. Дальше по очереди D63:
`P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.16.0; §3 Routing поitem'ный, строка P2.5 —
frontier/high/manual/full; §5 D1–D100; §4 Status Board — P2 3/10 🟢, счётчик не включает 🟠) ·
`phases/P2.md` (ТЗ items; Phase Context — очередь `P2.1 → P2.8 → P2.2 → P2.3 → P2.9 → P2.10 →
P2.4 → P2.5 → P2.6 → P2.7`) · **`research/03-prompt-contract.md`** §8.8 (разбор M2, таблицы по
подвыборкам/эталонам, сверка с шумом D100) · `findings/P2-eval-baseline.md` §7 (пара M0₂/M0₂′,
база сравнений) · `findings/P2-vendor-structured-output.md` (вход P2.4, снят) ·
`findings/P2-extraction-token-budget-2026-08-01.md` · `roadmap.md` · `index.md` ·
`open-questions.md` (OQ-2 — вход P2.5) · `journal.jsonl` · `brief/01-refinements.md`.

**Open risks:**

- **Известный флейк `bcsub()` на `EST-XLSX-KS2-001`** (P2.10 Pending Work) — доминирует и в M2;
  если P2.5/P2.6 продолжат видеть механический `reject` на ТЕХ ЖЕ эталонах с дельтой внутри D100
  — follow-up на штатный шаг сверки в `EvalRunComparator`, вне Scope P2.4 (владелец/`plan-design`).
- **Потолки вывода `gemini-3.1-flash-lite` и `gemini-3.1-pro-preview` `[UNVERIFIED]`** — RAG их не
  подтвердил, ключ `max_output_tokens` этим моделям НЕ поставлен (D99, осознанная граница).
- **Отдельная величина «дельта P2.3» утрачена навсегда** (D100 Consequences) — дифф P2.3
  структурно неотделим от фикса P2.9.
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (P2.8 Known Deviations).
- **Дешёвый size-gate слеп на легаси `.xls`** (P2.8 Scope Excluded).
- **DB частично гидрирована, НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=17,
  `prc_ai_eval_runs`=6 (M0, M0′, M1, M0₂, M0₂′, M2). **Не запускать `migrate:fresh` до закрытия
  фазы P2.**
- **`EST-PDF-SCAN-001` (id 7 в манифесте фикстур)** в шестёрку обязательных gold НЕ входит,
  baseline не блокирует.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — сопоставимо внутри P2, не с числами донора.
- **Деньги.** Дальше — по одному платному прогону на шаг (P2.5–P2.6), плюс три на варианты OQ-2
  в P2.5.
- **`rules_hash` инвалидирован P2.4** — следующий платный прогон (P2.5) тоже стартует на холодном
  системном префиксе; это ожидаемо и не регрессия качества.
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
  `$route->options` в P2.9, дормантен · новый дефект `bcsub()` на `EST-XLSX-KS2-001`
  (P2.10 Pending Work) — follow-up item, решение владельца/`plan-design` · **механический `reject`
  M2 на тех же двух эталонах** (P2.4 Pending Work) — если повторится на P2.5/P2.6, follow-up на
  штатный шаг сверки с шумом в `EvalRunComparator`.
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2** (судьба `PromptContextGenerator`) — снимает
  P2.5 · OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый
  контур, сверяется в P7.8).
