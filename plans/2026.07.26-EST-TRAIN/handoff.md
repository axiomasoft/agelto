# HANDOFF — 2026-07-27 — after P4.2

**Next:** P4.2 закрыт 🟢 под апрувом владельца — фаза P4 идёт 2/5. Следующий шаг — **P4.3**:
вторая группа решений фазы (границы смет в многосметном документе, семантика `ordinal` при
re-run/shadow, применимость правила §4а к реальным многосметным файлам). Тот же **гейт владельца**,
что у P4.2 — сессия останавливается на апруве; если решение добавляет колонки, они дописываются
разделом «Дополнение P4.3» в `research/06-schema-decision.md`. Solo, `Exec = manual`,
`frontier/high` — запускать по D18 через `plan-run` под opus/high.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — необратимый выбор носителя якоря, гейт владельца |
| Context | continue (/clear) — ручной item |
| Суть | Носитель границ сметы, семантика `ordinal`, вердикт по якорению `row_index`, апрув владельца |

```
/model opus
/effort high
/task:plan-run 2026.07.26-EST-TRAIN P4.3
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3 — терминальна (0/7 🟢, 7/7 🟠).
P4.1 — 🟢 (`a72fa22` + фикс `6c74e92`): `research/05-schema-audit.md`, двусторонняя матрица схемы.
**P4.2 — 🟢 Done** (item-коммит `381afa4`): произведён `research/06-schema-decision.md` (675 строк).
§1 — критерий с порогом **`N = 9` файлов** (10 % от 89 сметных; при доле 9/89 вероятность не
попасть ни в один из 20–30 эталонов первой волны ≈ 12 %) и три теста: (а) подтверждение корпусом
ЛИБО структурным эталоном `.gsfx`, (б) участие величины как САМОСТОЯТЕЛЬНОГО ОПЕРАНДА (арифметика /
поле F1 / предикат витрины), (в) форма носителя по кардинальности (0..1 → колонка; 0..N или одна
миноритарная форма → ключ с фиксированной схемой; собственная иерархия → таблица). §3 —
распределение: 17 строк `SR-01…SR-17` + 121 строка по 134 носителям обратной сверки. §4 —
фиксированная схема ключей (`items.attributes` 4 + элемент `modifiers[]` 9 + `passport` 7). §5 —
восемь обязательных подпунктов и вердикты по шести конфликтам аудита. §6 — исключения. §7 — долги
вне схемы. §8 — сводка гейта. §10 — апрув. В `plan.md` §5 — **14 D-записей (D92–D105)**, каждая с
«Альтернативы» и «Цена». Все шесть команд Validation зелёные. **Апрув владельца получен
2026-07-27: «Утверждаю целиком»**, правок к списку не названо.

**Утверждённый состав правки схемы (вход P4.4/P4.5): +25 колонок · 3 переименования · 3 смены
типа · 2 удаления · новая таблица `prc_estimate_work_types` (13 колонок) · 5 новых ключей jsonb
верхнего уровня + 9 в элементе `modifiers[]`.** Несущие решения:

- **D93** — двухуровневая цена: `unit_price`→`unit_price_curr`, `total_price`→`total_price_curr`,
  `prc_estimate_resources.price`→`price_curr`, рядом парные `*_base` (все `decimal(18,2)`).
- **D94** — индексы: `index_code`+`index_value` на позиции и ресурсе; каталог `<Indexes>` не
  хранится (разыменовывает маппер P5.1).
- **D95** — коэффициенты остаются в `attributes->'modifiers'`, но с 9 фикс-ключами (`kind` вместо
  свободного `description`, четыре составляющих `value_*`, `level`); `RowModifier::$description`
  удаляется.
- **D96** — трудозатраты: `labor_hours_workers`/`labor_hours_machinists` на `prc_estimates`;
  разряд работы не хранится.
- **D97** — виды работ: ссылка с **позиции** (`prc_estimate_items.work_type_id`), нормативы НР/СП —
  колонки `prc_estimate_work_types`; базы начисления не хранятся; документ без вида работ — законное
  состояние.
- **D98** — `prc_estimates.procurement_id` → `nullable` И **снятие guard `EstimatePersister.php:85-94`**
  (единственная правка существующего кода, прямо требуемая распределением, — P4.4 имеет на неё право).
- **D99** — ось `verified_at`/`verified_by` (`timestampTz` + FK `users` `nullOnDelete`), пишет
  ОПЕРАТОР; строка-исключение из D33.
- **D100** — удаляются `prc_estimates.title` (производная) и `prc_estimate_sections.section_number`
  (мёртвая).
- **D101** — концевик: `amount` → `nullable`, +`chapter_number`, +`rate`, `kind` → PHP enum `TotalKind`.
- **D102** — акт: колонки `document_number`/`document_date`/`period_start`/`period_end`; договор —
  ключи `passport.contract_number`/`contract_date`.
- **D103** — КС-3: три разреза — плоские ключи `attributes`; `RowType` += `summary_row`;
  +`source_position_number` на позиции.
- **D104** — множитель единицы: `unit` остаётся цитатой, `unit_base`+`unit_multiplier` заполняет
  БЭКЕНД (исключение из D33).
- **D105** — `calc_method` + PHP enum `CalcMethod` (`base_index`, `resource`).

**Remaining:** P4.3 (гейт владельца) → P4.4 (миграции; единственный item плана с легальным
`migrate:fresh --seed`) → P4.5 (контракты ответа LLM, спека §1/§3/§8.2, карта следствий).
Фазы P5–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta, §3 Routing, D1–D105) ·
`plans/2026.07.26-EST-TRAIN/phases/P4.md` (ТЗ P4.1–P4.5 + Completion Notes P4.1/P4.2) ·
**`plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` (утверждённый список — единственный
легальный вход P4.4/P4.5)** · `plans/2026.07.26-EST-TRAIN/research/05-schema-audit.md` (матрица,
обратная сверка, конфликты) · `plans/2026.07.26-EST-TRAIN/research/03-schema-requirements.md`
(`SR-01…SR-17`) · `plans/2026.07.26-EST-TRAIN/roadmap.md` (батчи, гейты владельца) ·
`docs/specs/v2/08-estimate-extraction.md` §1/§3/§4а/§8.2 · миграции `2026_07_04_1000{33..37}` ·
`estimates/manifest.json` (24 записи).

**Open risks:**
- **P4.4 обязан реализовать список БУКВАЛЬНО.** Лишняя колонка = отклонение от гейта владельца,
  недостающая = такое же отклонение. Две правки существующего кода, названные списком явно и
  потому легальные для P4.4: снятие guard `EstimatePersister.php:85-94` (D98) и перенос сборки
  подписи сметы на чтение после удаления `title` (D100). Всё остальное в `EstimatePersister` —
  Scope Excluded.
- **P4.5 несёт четыре следствия, которые обязаны попасть в карту следствий**, иначе они всплывут в
  чужой фазе: (1) поле F1 `price` переадресуется на `total_price_curr` — до правки `EvalMetrics`
  (P7.3) метрика считает по несуществующему полю; (2) словарь нормализации единиц (D104) — новый
  код с собственным владельцем, до него `unit_base`/`unit_multiplier` пусты; (3) `summary_row`
  требует правки промта P7.2 и словаря §3.5; (4) правило «какие поля обязательны при каком
  `calc_method`» — код валидатора, план его не пишет.
- **Схема §4.2 (`modifiers[]`) по gsfx-ветке не заполняется без правки P3.3**: `GsfxPosition::$coefficients`
  пуст по построению — связь коэффициента с позицией выражена ВЛОЖЕННОСТЬЮ, а ищется атрибутом.
  Там же: `knownTypes` не знает `Tzm2022` (81 запись), `Quantity@KUnit` (91 из 158) не переносится,
  докблок про `Position@IndexCode` неверен (заполнен у 64 из 110). Все четыре — вход P5.1, не схемы.
- **`prc_estimate_work_types` полноценно заполняется только gsfx-веткой**: у 61 книги корпуса
  `overhead_rate`/`profit_rate`/`parent_id` останутся пустыми (осознанное исключение из D33).
- **`verified_at`/`verified_by` заводятся БЕЗ писателя внутри плана** (D89/D99) — риск «пустая
  колонка» принят владельцем сознательно; экран боевого просмотра и промоушен в датасет — долг вне
  плана.
- **Дефект `plan-lint`: ложное срабатывание на отрицании.** `ORCHESTRATION_MARKER_RE`
  (`plan_lint.py:223`) и `NEEDS_CLARIFICATION_RE` (`:163`) матчат подстроку и не отличают
  утверждение от его отрицания — DoR-пункты «оркестрация НЕ заявляется» и «маркеры сняты» давали
  два WARN при переходе фазы в 🟡. В P4 формулировки переписаны (Known Deviations P4.2), в P1 тот
  же ложноположительный WARN остаётся — P1 не трогался. Системная правка принадлежит владельцу
  `plan-lint` (swissknifeman), не этому плану.
- Все риски, унаследованные от P1–P3, без изменений: acceptance P3.1 Validation-5 недостижим по
  букве; P3.2 Validation-3 в буквальной форме недостижима; `phpstan-baseline.neon` правился вне
  `Files` семь раз подряд (кандидат на item `@property`-аннотаций); потолок OCR 50 % — на нём нельзя
  строить автоматический gold; `pdf_ocr` 132-МБ эталона весит 133 МБ и не влезает в лимит маршрута;
  ветка изображений OCR-ит только картинки с DPI в метаданных; `.gsfx` несёт слой `ImplemActs`,
  сознательно не моделируемый; RAR-путь `ArchiveStrategy` покрыт частично; `lsar` вызывается
  литералом; формат `text_grid` зафиксирован докблоком; 21 файл КС-3 вне extraction; OQ-3/OQ-4
  открыты.
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: гейты P4.2 прогнаны реально —
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib` →
  `pre_mutation: allow · pre_final: allow` (оба). Следующим шагам использовать тот же PYTHONPATH.
- **`git add plans/<PLAN-ID>` затягивает эфемерные логи тулинга.** В каталоге плана живут
  `.claude/` и `.swissknife/` (feature-log, `audit`/`events` ndjson, ephemeral-снимки сессий), и
  буквальный шаг 6 топологии закрытия версионирует их вместе с бухгалтерией: у P4.2 это шесть
  файлов в `77029b4`, убраны `820215e`. **Повторится у каждого следующего item'а**, пока в
  корневой `.gitignore` не добавлены `plans/**/.claude/` и `plans/**/.swissknife/` — правка
  принадлежит владельцу (файл правится им параллельно). До этого исполнителю следует проверять
  `git status --porcelain` перед bookkeeping-коммитом.
- **Бухгалтерия P4.1 была не закоммичена** и уехала в item-коммит P4.2 `381afa4` вместе с `plan.md`
  (Known Deviations P4.2 п.1). Для чистоты истории: закрывающий item ОБЯЗАН делать
  bookkeeping-коммит, иначе следующий исполнитель публикует чужую бухгалтерию своим коммитом.
- Посторонний дифф в рабочем дереве (не в коммитах P4.2, унаследован от P3): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`. Исполнитель P4.2 его не трогал.
- Ручные прогоны P3.4/P3.5/P3.7 создали реальные `Document`/`DocumentVersion` в DEV-БД (не
  `agelto_test`) через `tinker` — не убраны; в контейнере остались файлы `/tmp/ocr-bench/**`.
  **Это прямо касается P4.4:** его гейт непустоты проверяет шесть таблиц, и `prc_documents`/
  `prc_document_versions` заведомо НЕ пусты — решение о судьбе этих записей принимает владелец,
  а не исполнитель (D10 в этой части уже не верен). P4.2 в БД не писал.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны, иначе падает на `MINIO_ROOT_PASSWORD`); `REDIS_PORT=6391`
  (`6379` занят `sova_redis`).

**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose` требует двух env-файлов; `REDIS_PORT=6391`; `make artisan`/`make ps`
  конфликтуют с файлом `artisan` в корне — звать `docker compose … exec app php artisan …` напрямую;
  запись в bind-mounted пути из контейнера требует ACL (`phpstan --generate-baseline`/`pint --dirty`
  — с ХОСТА); git-диффы внутри контейнера требуют `git config --global --add safe.directory`;
  каталог `storage/app/private/estimates` не читается с хоста (Permission denied) — файл эталона
  берётся из `estimates/files/`; сигнатура `GsfxReader::parse($propertiesRaw, $dataXmlRaw)`, не
  `read($path)`.
- deferred: тест-двойники цепочки стратегий живут в `RepresentationContractTest.php`; отдельного
  `WordStrategyTest.php` нет; RAR-кейсы со сборкой архива — до свободного энкодера; `--image-dpi`
  для изображений без DPI — до отдельного D#; `@property`-аннотации моделям `Document`/
  `DocumentVersion` — до отдельного item'а; правка докблоков, `knownTypes` и связи `Koefficients`
  в `GsfxReader` — P5.1; детерминированное доопределение `prc_estimate_totals.kind` по `label` —
  долг (D101); общий справочник видов работ (`ref_work_types`) — долг D34/D90; писатель оси
  `verified_at` и промоушен боевой сметы в датасет — долг вне плана (D89 п. в).
- open_questions: OQ-3, OQ-4 — без изменений. OQ-5/OQ-6 закрыты D89/D90 и не переоткрываются.
