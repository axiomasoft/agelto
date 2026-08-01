# HANDOFF — 2026-08-01 — after P2.3

**Next:** design: /task:plan-design 2026.07.31-SYS-INTEGRITY P2

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | Завести/утвердить follow-up-фикс `maxOutputTokens`/thinking-бюджета маршрута `extraction` (`llm_routes`, Scope Excluded P2.3) как ПРЕДУСЛОВИЕ P2.4 — M1 (P2.3) вернул `reject`, причина в диффе найдена (research/03-prompt-contract.md §8.5), но фикс вне Scope P2.3; без него P2.4 рискует тем же ложным `reject` на ещё более длинном промте (описания + эмиссия) |

```
/task:plan-design 2026.07.31-SYS-INTEGRITY P2

РЕЖИМ: design — амендмент ТЗ фазы P2 ПЕРЕД стартом P2.4.
ВХОД: `plans/2026.07.31-SYS-INTEGRITY/phases/P2.md` P2.3 Completion Notes/Pending Work/Known
  Deviations (M1 = `reject`, EvalRun #3) + `plans/2026.07.31-SYS-INTEGRITY/research/
  03-prompt-contract.md` §8.5 (полный диагноз: печатный блок extraction:rows вырос +6.7%
  символов — атрибутный синтаксис #[Description] длиннее bullet-докблока — компаундирует с уже
  известной P2.2 фрагильностью токен-бюджета маршрута extraction, пик ~90К токенов ДО P2.3 на
  EST-XLS-KS2-001) + `findings/P2-eval-baseline.md` §3 (исходный флейк).
ЗАДАЧА: решить и оформить D#-записью — поднять `maxOutputTokens`/thinking-бюджет маршрута
  `extraction` (`config('ai.capabilities.<model>')`/`llm_routes`) НАСКОЛЬКО и КАК проверить
  (новый дешёвый прогон? синтетическая проверка бюджета без платного eval?) — и завести
  follow-up item (свободный номер по конвенции P2.8, "номер — адрес, а не очередь") ПЕРЕД P2.4
  в очереди items фазы. Альтернатива, которую тоже нужно явно взвесить и отвергнуть или принять:
  владелец решает принять M1 `reject` как есть (Known Deviations P2.3 уже фиксирует объяснённое
  отклонение) и разрешает P2.4 стартовать без фикса — тогда амендмент только документирует это
  решение, item не заводится.
НЕ ТРОГАТЬ: `App\Attributes\Ai\Description`/`ContractAttributeReader`/контракты пяти шагов
  (P2.3 закрыт, дифф корректен и покрыт тестами — причина red НЕ в нём); JsonSchemaGenerator/
  PromptContextGenerator (P2.4/P2.5); повторный платный eval (бюджет P2.3 — один прогон, D8 —
  исчерпан; новый прогон — решение владельца по цене, как D26/D91 ранее).
```

**Done:** **P2.1 закрыт 🟢** (item-коммит `ced2a4f`). **P2.8 закрыт 🟠** (item-коммиты `b51ce3a`+
`b05235f`). **P2.2 закрыт 🟢** (item-коммиты `19f9808`+`684ddfb`) — гидрация корпуса,
режим `no-regression`, парный baseline M0/M0′ (D91).

**Этим заходом (`plan-run`, sonnet/high) закрыт P2.3 🟠 Done with deviations** — item-коммит
`bcd7ad4`. `App\Attributes\Ai\Description` (property/parameter/class constant) +
`App\Services\Llm\Schema\ContractAttributeReader` (`descriptions()`/`aliases()`/`caseAliases()`,
без кэша, D92) введены; описания перенесены (докблок-bullet удалён, не продублирован) в
`#[Description]` на всех 93 свойствах контрактов пяти шагов (`ScreeningResult`,
`DiscoveryResponse`+`DiscoveryMarkerLine`, `PassportResponse`+`EstimatePassport`+
`EstimateTotalsBlock`+`TotalLine`+`EstimateWorkType`, `RowsChunkResponse`+`EstimateRow`+
`RowResource`+`RowAttributes`+`RowModifier`+`IncompleteRow`, `TotalsResponse`) + словаря
`EstimateHeaderMap`/`HeaderColumn`; `#[Alias]` расширен на 11 полях, где синоним назван
источником. Арх-гейт покрытия (`ContractDescriptionCoverageTest`) — полное покрытие, список
исключений пуст. Тесты: `ContractAttributeReader --no-tia` 4 passed/7 assertions;
`ContractDescriptionCoverage --no-tia` 2 passed/2 assertions; `PromptComposition --no-tia`
8 passed/57 assertions (фикстура `screening-result.prompt.txt` пересобрана байт-в-байт
генератором); `grep -rn "getAttributes(" app/Services/Llm/Schema` — 3 вхождения. Pint по `Files`
item'а — чисто; `phpstan analyse` на всём дереве — `[OK] No errors`, baseline не вырос.

**Material-находка (Validation red, НЕ откачено):** `estimate:eval:run --split=all` +
`estimate:eval:compare --base=1 --candidate=3 --criterion=no-regression` → **`reject`** (M1,
EvalRun #3, на `bcd7ad4`). Только 2/6 эталонов обработано (было 4/6 на M0/M0′): известный флейк
токен-лимита `EST-XLS-KS2-001`/`EST-XLSX-KS2-001` сработал ожидаемо, но ВПЕРВЫЕ упали ещё два
ранее стабильных эталона — `EST-XLSX-LSR-001` (токен-лимит, новый) и `EST-XLSX-VOR-001`
(структурный отказ `RowsChunkResponse::structuralError()` — дубликат `row_index`); `EST-GSFX-
LSR-001` обработан, но f1=0 (было ненулевым). Причина найдена в диффе шага (D23): печатный блок
схем `extraction:rows` вырос с 18823 до 20086 символов (+6.7%, измерено `PromptContextGenerator::
generate()` до/после item-коммита) — атрибутный синтаксис `#[Description]` на каждое свойство
длиннее одной bullet-строки докблока, структурное следствие уже утверждённого дизайна D92/P2.1,
не дефект переноса (арх-гейт/юнит-тесты/`PromptComposition` зелёные). Компаундирует с уже
известной ДО P2.3 фрагильностью токен-бюджета маршрута `extraction` (P2.2 Pending Work, пик
~90К токенов на `EST-XLS-KS2-001`). Диф НЕ откатывается — детали и полное обоснование
`research/03-prompt-contract.md` §8.5. **P2.4 блокирован** до фикса
`maxOutputTokens`/thinking-бюджета маршрута `extraction` (вне Scope Excluded P2.3, решение
владельца/`plan-design`) — `phases/P2.md` P2.3 Pending Work. Платный бюджет P2.3 (один прогон,
D8) исчерпан этим замером.

**Lint:** `python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py
plans/2026.07.31-SYS-INTEGRITY --baseline HEAD` — см. итоговое число ниже этого захода (гейт
закрытия item'а прогнан на дереве ПОСЛЕ перезаписи этого файла, шаг 4 `plan-exec.md`).

**Remaining:** 39 item из 53 (было 40 — P2.3 закрыт). Внутри фазы очередь: P2.1 → P2.8 → P2.2 →
P2.3 (закрыт) → **[блокер: фикс бюджета `extraction`]** → P2.4 → P2.5 → P2.6 → P2.7. Дальше по
очереди D63: `P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.15.0; §2 Execution Rules; §3 Routing поitem'ный, строка
P2.3: implementation/high/manual/full — исполнено сессией sonnet/high, точное совпадение, не
перебор; §5 D1–D97; §4 Status Board — P2 2/8 🟢, счётчик не включает 🟠) · `phases/P2.md` (ТЗ
items; P2.3 Completion Notes — числа M1, диагноз, Pending Work-блокер) ·
`research/03-prompt-contract.md` §8.5 (полный диагноз red-замера M1) ·
`findings/P2-eval-baseline.md` (гидрация + M0/M0′ + шум D91 + исходный флейк §3) ·
`findings/P2-textgrid-oom-2026-08-01.md` · `findings/P2-corpus-state-2026-08-01.md` ·
`findings/P2-vendor-structured-output.md` · `roadmap.md` · `index.md` · `open-questions.md` ·
`journal.jsonl` · `brief/01-refinements.md`.

**Open risks:**

- **P2.4 заблокирован фиксом токен-бюджета маршрута `extraction`** (НОВОЕ, P2.3 Pending Work) —
  без него P2.4 (схема тоже растёт — временно ДВА носителя, эмиссия `description`) рискует тем же
  или худшим `reject`; фикс — вне Scope Excluded P2.3, требует владельца/`plan-design`.
- **Флейк токен-лимита Gemini расширился с 2/6 до потенциально большего числа extraction
  gold-эталонов** (P2.2 Pending Work материализовался и усилился P2.3) — `EST-XLSX-LSR-001` и
  `EST-XLSX-VOR-001` впервые упали в M1; вероятная причина — рост печатного промта, не свойство
  самих эталонов.
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (P2.8 Known Deviations).
- **Дешёвый size-gate слеп на легаси `.xls`** (P2.8 Scope Excluded).
- **DB частично гидрирована, НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=17,
  `prc_ai_eval_runs`≥3 (M0, M0′, M1).
- **`EST-PDF-SCAN-001` (id 7)** в шестёрку обязательных gold НЕ входит, baseline не блокирует.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — сопоставимо внутри P2, не с числами донора.
- **Деньги.** Бюджет P2.3 (один платный прогон, D8) израсходован этим заходом (M1, EvalRun #3).
  Дальше — по одному на шаг (P2.4–P2.6), плюс три на варианты OQ-2 в P2.5, ЕСЛИ владелец не
  решит потратить дополнительный прогон на верификацию фикса токен-бюджета раньше.
- **Расхождение порядка свойств между двумя носителями живёт в боевом коде СЕЙЧАС** — чинится P2.4.
- **Пол префикса может не сработать как защита** — байтовый якорь не переведён в токены,
  `[UNVERIFIED]` для `gemini-3.1-flash-lite`. Обязанность P2.5 (D90).
- **Канал проекта помечен вендором Legacy** — вход EOL-мониторинга, P2.7 фиксирует строкой риска.
- **Строка Update Log для `plan-audit P1` (заход 2) отсутствует** — вне скоупа.
- **A1 аудита P1 (Major) не закрыт**: потребление `ProxyRequirements->country` не покрыто
  дискриминирующим тестом — решение владельца.
- **Partition-drift — падения полного сюита вне предмета плана** (`llm_calls` — нет партиции под
  текущую дату; наблюдалось повторно этим заходом на `LlmCacheManagerTest`/`CostReportTest`/
  `UsageSourceTest`/`PulseCardsTest`, ~9 тестов) — фикс-предложение `phases/P1.md` P1.10 Pending
  Work. Два красных arch-теста `CoreKnowsNoDocumentTypeTest` исключены поимённо (D86).
- **`verify-citations.py` — находки вне диффа**; инструмента в `plans/` этого репозитория нет (D76).
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет фазы.
- **`docker compose` на хосте**: `MINIO_ROOT_PASSWORD`-интерполяция падает — обходка
  `docker exec agelto_app` рабочая, использовалась и этим заходом.
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
  (P1.10 Pending Work) · миграция на Interactions API вендора (мониторинг) · починка
  слепого size-gate на легаси `.xls` (P2.8 Scope Excluded) · статус `EST-PDF-SCAN-001` (вне
  шестёрки gold) · решение о диспетче `PrepareDocumentJob` из `estimate:gold:import --prepare`
  (P2.8 Pending Work) · **фикс лимита токенов Gemini на маршруте `extraction`** (P2.2 Pending
  Work, ТЕПЕРЬ БЛОКЕР P2.4 — P2.3 Pending Work).
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2** (судьба `PromptContextGenerator`) — снимает
  P2.5 · OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый
  контур, сверяется в P7.8).
