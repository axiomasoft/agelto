# HANDOFF — 2026-08-01 — after P2.8

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P2.2

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P2.2 (уже 🟡 In progress, предусловие D97 снято P2.8): дописать тесты `no-regression`, гидрировать корпус, снять парный baseline M0/M0′ |

```
/task:plan-run 2026.07.31-SYS-INTEGRITY P2.2

РЕЖИМ: exec — ПРОДОЛЖЕНИЕ существующего item'а P2.2 (уже 🟡 In progress), не реопен, не редизайн.
  Предусловие D97 (P2.8 терминален) СНЯТО — P2.8 закрыт 🟠 2026-08-01, item-коммит `b51ce3a`+
  `b05235f`; `estimate:gold:import --prepare` больше не падает на `EST-XLS-KS2-001`.
ВХОД: `plans/2026.07.31-SYS-INTEGRITY/phases/P2.md` item P2.2 (все 16 полей, включая
  Completion Notes предыдущего захода и блок эскалации выше статуса) +
  `plans/2026.07.31-SYS-INTEGRITY/findings/P2-corpus-state-2026-08-01.md` +
  `plans/2026.07.31-SYS-INTEGRITY/findings/P2-textgrid-oom-2026-08-01.md` §8 (числа фикса P2.8)
СОСТОЯНИЕ ДЕРЕВА: незакоммиченный дифф `EvalRunComparator.php`/`EvalCompareCommand.php` (режим
  `no-regression` + `--criterion=`, Scope Included P2.2, готов, ТЕСТОВ НЕТ) лежит в рабочем
  дереве СВЕРХ item-коммитов P2.8 — это ЧУЖОЙ для P2.8 дифф (не трогать, не переписывать, не
  откатывать; P2.8 его не коммитил и не проверял). Дифф P2.8 живёт в ДРУГИХ файлах
  (`TextGridRenderer.php`, `GoldImportCommand.php`, `config/ai.php`,
  `TextGridRendererTest.php`, `findings/P2-textgrid-oom-2026-08-01.md`) и уже закоммичен.
СКОУП ПРОДОЛЖЕНИЯ: 1) дописать три обязательных теста режима `no-regression` в
  `EvalCompareTest.php` (нулевая дельта: `patch`→reject/`no-regression`→accept; падение `holdout`
  сверх порога → reject в обоих режимах; дефолт без `--criterion=` — как раньше) 2) гидрация
  корпуса по D93 (`estimate:fixtures:import` → `estimate:gold:import --prepare` →
  `estimate:fixtures:verify`, числа в `findings/P2-eval-baseline.md`) 3) ПАРНЫЙ baseline
  `estimate:eval:run --split=all` ×2 (M0, M0′) на ОДНОМ дереве/корпусе, разброс M0↔M0′ как
  определение шума фазы (D91) 4) `findings/P2-eval-baseline.md` целиком.
НЕ ТРОГАТЬ: величины порогов `TUNE_F1_MIN_GAIN`/`HOLDOUT_F1_MAX_DROP`/`ROW_COVERAGE_MAX_DROP`/
  `PER_FIXTURE_F1_MAX_DROP` · `estimates/**`, `estimates/manifest.json`, `estimates/gold/**`,
  `estimates/eval-splits.json` (SSOT заморожен, гидрация ТОЛЬКО читает) · `--write-splits` ·
  `EvalMetrics`/`EvalSplitPlanner` · `.env`/`.env.example` (чужой дифф) · `TextGridRenderer.php`/
  `GoldImportCommand.php`/`config/ai.php` (Files P2.8, уже закрыт и закоммичен) · известное
  отклонение P2.8 (gold-`--prepare` не переводит документ в `prepared` — не блокирует гидрацию,
  `estimate:fixtures:verify` о статусе `prepared` не спрашивает; см. `phases/P2.md` P2.8 Known
  Deviations, если гидрация вдруг на это наткнётся — не чинить в P2.2, эскалировать).
```

**Done:** **P2.1 закрыт 🟢** (item-коммит `ced2a4f`). Заходом `plan-design P2` 2026-08-01 ТЗ фазы
приведено к вердикту P2.1 (D88–D92). Заходом `plan-design P2.2` того же дня снята эскалация №1
(D93/D94 — гидрация из целого git-SSOT) и эскалация №2 (D95–D97 — фикс OOM вынесен в P2.8).

**Этим заходом (`plan-run`, sonnet/high) закрыт P2.8 🟠 Done with deviations** — item-коммиты
`b51ce3a` (код: `TextGridRenderer`/`GoldImportCommand`/`config/ai.php`/тесты) и `b05235f`
(findings §8). Корень OOM (`Worksheet::getCell()` создаёт отсутствующую ячейку, плотный обход
`highestColumn × highestRow` материализовал 2 487 503 объекта на `EST-XLS-KS2-001` против 29 464
реально существующих) устранён разреженным обходом (карта по существующим координатам → merge
поверх карты → вывод); формат `text_grid` не изменился ни в одном знаке — семь исходных кейсов
`TextGridRendererTest` прошли БЕЗ правок, три новых добавлены (10 passed, 21 assertions).
Регрессионный кейс подтверждённо КРАСНЕЛ на дереве до фикса (`git stash` + изолированный
`--filter`, exit 255 — немой OOM: `display_errors=Off` в CLI SAPI контейнера). Боевое репро
`estimate:gold:import --code=EST-XLS-KS2-001 --prepare` прошло без fatal error, `text_grid`
0→1 (88 строк, 11 165 байт — совпадает день-в-день с прототипом диагноза `findings/
P2-textgrid-oom-2026-08-01.md` §4). Сверка эквивалентности вакуальна — на момент item'а в БД
было 0 представлений `kind=text_grid` (крах §1 останавливал `--prepare` до первого успешного
сохранения на xlsx/xls корпусе). Два новых ключа настроек: `ai.prepare.xlsx_max_cells` (дефолт
2 000 000) и `ai.prepare.gold_import_memory_limit` (дефолт `1024M`, вынос литерала). Pint/PHPStan
по `Files` item'а — чисто; единственная ошибка полного прогона PHPStan (`EvalRunComparator.php:56`)
принадлежит чужому незакоммиченному диффу P2.2, не входит в `Files` P2.8, не в baseline.

**Известное отклонение P2.8 (Known Deviations, НЕ дефект диффа):** `estimate:gold:import
--prepare` не переводит документ из `downloaded` в `prepared` — код-грепом подтверждено:
`GoldImportCommand` вызывает `DocumentPreparer::prepare()` НАПРЯМУЮ, минуя
`App\Jobs\Procurement\PrepareDocumentJob` (единственное место, ставящее
`DocumentStatus::Prepared`, `PrepareDocumentJob.php:83`); `DocumentPreparer::prepare()` сам меняет
статус только на терминальной ветке `Unprocessable`. Предсуществующее поведение команды, диффом
P2.8 не создано и не устранимо в его `Scope Included` (статус/гидрация — P2.2). Follow-up —
`phases/P2.md` P2.8 Pending Work.

**Lint:** не прогонялся отдельно этим заходом (нет переменной/скрипта в окружении сессии —
`plan-lint.py` живёт вне репозитория по пути из `plan.md` §2; следующая сессия при необходимости
прогоняет `python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/
plan-lint.py plans/2026.07.31-SYS-INTEGRITY`).

**Remaining:** 41 item из 53 (было 42 — P2.8 закрыт). Внутри фазы очередь: **P2.2** (продолжение,
предусловие снято) → P2.3 → P2.4 → P2.5 → P2.6 → P2.7. Дальше по очереди D63: `P3 → P5 → P6 → P4
→ P7`.

**Sources of truth:** `plan.md` (Meta v0.15.0; §2 Execution Rules; §3 Routing поitem'ный, строка
P2.8; §5 D1–D97; §4 Status Board — P2 1/8, P2.8 терминален 🟠 сверх фракции 🟢) · `phases/P2.md`
(ТЗ items; P2.2 несёт ТРИ блока-провенанса: `✅ ЭСКАЛАЦИЯ СНЯТА D93`, `✅ ЭСКАЛАЦИЯ СНЯТА D95–D97`
и исторический `🔴 ESCALATION P2.2`; P2.8 Completion Notes — числа фикса) ·
`findings/P2-textgrid-oom-2026-08-01.md` (диагноз + §8 результат фикса) ·
`findings/P2-corpus-state-2026-08-01.md` · `research/03-prompt-contract.md` ·
`findings/P2-vendor-structured-output.md` · `roadmap.md` · `index.md` · `open-questions.md` ·
`journal.jsonl` · `brief/01-refinements.md`.

**Open risks:**

- **Незакоммиченный код P2.2 в рабочем дереве**: `EvalRunComparator.php` (режим `no-regression`)
  + `EvalCompareCommand.php` (`--criterion=`) — готов, тестов нет. P2.8 его не трогал и в свои
  item-коммиты не включал (`git add` — только поимённые пути из `Files`, D77).
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (НОВОЕ, P2.8 Known
  Deviations) — архитектурный факт вызывающего пути (минует `PrepareDocumentJob`), не блокирует
  гидрацию P2.2 (`estimate:fixtures:verify` о статусе `prepared` не спрашивает), но стоит держать
  в уме при написании Validation следующих items, ссылающихся на статус документа после
  gold-импорта.
- **Дешёвый size-gate слеп на легаси `.xls`**: `listWorksheetInfo()` отдаёт `rows=100` при
  фактических `9679 × 257` — порог `xlsx_max_rows` (20 000) проходится с запасом в 200×. P2.8
  снял ПОСЛЕДСТВИЕ (расход перестал зависеть от границ used-range), сам гейт не чинил (Scope
  Excluded). Класс риска остаётся: любой предохранитель, стоящий на числе строк из метаданных, на
  этом формате неинформативен.
- **`buildMergeMap()`-класс риска закрыт бюджетом ячеек P2.8** (`ai.prepare.xlsx_max_cells`,
  дефолт 2 000 000) — патологический merge (лист целиком) теперь ловится бюджетом ДО сбора карты,
  отдельного item'а не заводится.
- **DB частично гидрирована и НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=1,
  документ id 8 (`EST-XLS-KS2-001`) теперь несёт `text_grid`-представление (было 0, стало 1),
  статус остался `downloaded` (см. выше); документ id 7 (`EST-PDF-SCAN-001`) — `prepared`
  (получен другим путём, не через `estimate:gold:import`, вне предмета P2.8).
- **`EST-PDF-SCAN-001` (id 7)** в шестёрку обязательных gold НЕ входит (`gold_path: null`),
  baseline не блокирует.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — версия LibreOffice может отличаться от
  донорской (P6.2 EST-TRAIN). Внутри P2 сопоставимо (одно дерево, один корпус), с числами донора —
  нет, и P2 этого не заявляет.
- **Деньги.** P2.2 — ДВА полных платных прогона (D26 + D91), дальше по одному на шаг D8 плюс три
  на варианты OQ-2 в P2.5. P2.8 платных вызовов не нёс.
- **Расхождение порядка свойств между двумя носителями живёт в боевом коде СЕЙЧАС** (`ksort` в
  схеме против порядка конструктора в промте). Чинится P2.4.
- **Пол префикса может не сработать как защита** — байтовый якорь не переведён в токены, порог для
  `gemini-3.1-flash-lite` `[UNVERIFIED]`. Обязанность P2.5 (D90).
- **Канал проекта помечен вендором Legacy** (`findings/P2-vendor-structured-output.md` §9) — вход
  EOL-мониторинга; P2.7 фиксирует строкой риска.
- **Строка Update Log для `plan-audit P1` (заход 2) отсутствует** — бухгалтерия P1, вне скоупа.
- **A1 аудита P1 (Major) не закрыт:** потребление `ProxyRequirements->country` не покрыто
  дискриминирующим тестом — решение владельца. A6 (Nit) копится до касания файла.
- **Partition-drift — 11 падений полного сюита вне предмета плана** (`pg_partman` premake только
  вперёд); фикс-предложение — `phases/P1.md` P1.10 Pending Work. **Два красных arch-теста**
  `CoreKnowsNoDocumentTypeTest` (pre-existing) исключены поимённо (D86).
- **`verify-citations.py` — находки вне диффа**; инструмента в `plans/` этого репозитория нет
  (D76) — follow-up вне scope.
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет фазы.
- **`docker compose` на хосте**: `MINIO_ROOT_PASSWORD`-интерполяция в неинтерактивных оболочках
  агента падает — обходка `docker exec agelto_app` рабочая, использовалась и этим заходом.
- **Незапланированные файлы в рабочем дереве**: `M .env.example`, untracked `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife/`,
  `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/.swissknife/` — чужой
  дифф из `plan.md` §2 (carve-out) плюс скилл-каталоги; не трогать, `git add -A` запрещён.
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется перед стартом P5 (D41,
  D42). **P4.7 — точка невозврата по схеме**: P5 и P6 обязаны быть терминальны до него (D12, D47).

**Workarounds/Deferred/Open questions:**

- *Workarounds:* `docker exec agelto_app` вместо `docker compose exec app` в неинтерактивных
  оболочках агента (см. Open risks).
- *Deferred:* строка Update Log для `plan-audit P1` захода 2 · A1/A2/A3/A6 аудита P1 · правка ТЗ
  P1.2/P1.3 (D20), отложенная владельцем · перенос слоёв класса B (D28) · сплошное покрытие
  тестами команд и job'ов (D59) · положения (3)–(5) целевой модели хранения (D72) · переход
  транспорта ЕИС на XML/SOAP (D14) · переименование дисков (D53) · судьба таблицы кэша (D75) ·
  связь «выделенные узлы под конкретный профиль» (D80) · `ProxyForm::COUNTRIES` (D86, Pending Work
  P1.7) · починка двух pre-existing `CoreKnowsNoDocumentTypeTest` (D86) · разовый флейк
  `CsvBlockIngestorTest` · partition-drift 11 падений (P1.10 Pending Work) · миграция на
  Interactions API вендора (вне плана, запись мониторинга) · починка слепого size-gate на легаси
  `.xls` (P2.8 Scope Excluded) · статус `EST-PDF-SCAN-001` (вне шестёрки gold) · **решение о
  диспетче `PrepareDocumentJob` из `estimate:gold:import --prepare`** (НОВОЕ, P2.8 Pending Work —
  либо дописать код, либо поправить формулировку Validation в командах, ссылающихся на статус
  `prepared` после gold-импорта).
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2** (судьба `PromptContextGenerator`) —
  `Decision pending`, снимает P2.5 по числам трёх вариантов (D27) · OQ-3 (источник истины ЕИС,
  снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый контур, сверяется в P7.8).
