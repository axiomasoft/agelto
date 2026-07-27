# HANDOFF — 2026-07-27 — after P5.1 (escalated)

**Next:** design-item: `/task:plan-design 2026.07.26-EST-TRAIN P5.1` — реопен P3.3
(`GsfxReader`/`GsfxDocument`), не правка P5.1 самой.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — открытых решений нет по сути («что должно быть на носителе» уже утверждено D94), но решение КАК расширять закрытый item P3.3 (реопен формы XML-разбора без порчи 110/110 сошедшегося разбора) — design-уровня |
| Context | NEW SESSION — шаг-не-item |
| Суть | P5.1 заблокирован: `GsfxReader`/`GsfxDocument` (P3.3, уже закрыт 🟠) молча теряют `Position@IndexCode` (64 из 110 позиций реального эталона — расценки прямым счётом по каталогу, без `<Resources>`) и не разбирают верхнеуровневый каталог `<Indexes>` вовсе (`<IndexesPos>`/`<IndexesRes>`, `@Code → @MT/@EM`) — `index_value` физически невосстановим ни для одной позиции/ресурса документа, а `index_code` — для 64/110 позиций. Утверждённое распределение (`research/06-schema-decision.md` §5.2/D94) уже дало носитель обеим величинам на `prc_estimate_items`/`prc_estimate_resources` — блокер не в схеме, а в том, что модель P3.3 не долистывает исходный XML до конца. Это НЕ новая находка: `handoff.md` после P4.5, `research/06-schema-decision.md` §7 и `research/08-schema-change-impact.md` §6 уже называли ровно эти два дефекта и предписывали ОДИН путь — «реопен P3.3 через эскалацию, когда P5.1 их реально встретит (не раньше)». Первое фактическое столкновение — эта сессия. Холодный вход: `plan.md` → `phases/P5.md` P5.1 (блок эскалации + Pending Work) → `handoff.md` |

```
/task:plan-design 2026.07.26-EST-TRAIN P5.1
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P3 — терминальна (0/7 🟢, 7/7 🟠).
**P4 — терминальна (3/5 🟢, 2/5 🟠).** P5.1 — код НЕ написан (эскалация найдена на этапе Required
Reads, до первой строки `GsfxGoldMapper`/`GoldValidator`); Required Reads item'а пройдены целиком
(§8.2/§3.1/§3.2/§4а спеки, `research/06`, `research/07`, `research/08`, DTO `GsfxDocument`/
`Estimate*` после P4.5, приём `DatasetImportCommand.php:110-174`). Находка подтверждена прямым
разбором реального эталона `EST-GSFX-LSR-001` (`estimates/files/Администрация культуры и спорта
3.gsfx`, `Data.xml` через `unzip -p ... | iconv -f cp1251 -t utf8`): 110 `<Position>` всего, 64 (58 %)
несут собственный `@IndexCode` БЕЗ дочернего `<Resources>` (напр. `Code="ФСБЦ-04.3.01.09-0054"
IndexCode="5"`, только прямые `PriceBase`/`PriceCurr`), которого `GsfxReader::parsePosition()` не
читает ни в одном виде (не входит в список читаемых атрибутов позиции, не попадает и в `$unmapped`
— тот считает только имена непрочитанных ДОЧЕРНИХ ЭЛЕМЕНТОВ, не пропущенные атрибуты); каталог
`<Indexes>` — единственный верхнеуровневый элемент документа, дающий связь `@Code → значение`, тальён
`GsfxReader::tallyUnmappedTopLevel()` в `unmapped['Indexes']` целиком (видно, что «есть непокрытое»),
но ни во что не разбирается — маппер не может разыменовать код в значение НИ ДЛЯ ОДНОЙ позиции.

**Remaining:** Фаза P5 (6 items) заблокирована на первом item'е. P5.1 требует реопена P3.3
(`/task:plan-design 2026.07.26-EST-TRAIN P5.1`), затем повторного запуска `/task:plan-run
2026.07.26-EST-TRAIN P5.1` тем же ТЗ (Scope/Inputs не меняются, кроме версии `GsfxDocument`
после реопена). P5.2–P9.6 не начаты; P5.2 (табличная книга → gold) не зависит от P5.1 напрямую
(§3 Routing DoR: «независимы между собой только P5.1 и P5.2»), но `Exec = manual` — под ту же
модель/effort, что и P5.1 (`implementation/high`), и Phase-first правило `plan-exec`/`plan-run`
всё равно не пускает вперёд items следующей фазы, а не item той же фазы — P5.2 формально
исполним раньше реопена P3.3, но решение начинать ли параллельную ветку — за владельцем (не
следует молча из отсутствия блокирующей связи).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta, §3 Routing, D1–D109) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (ТЗ P5.1 + блок эскалации + Pending Work) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (P3.3 Completion Notes — что уже сделано и на чём
остановилось) · `research/06-schema-decision.md` §5.2 (D94, носитель `index_code`/`index_value`)
и §7 (долг назван явно ДО этой сессии) · `research/08-schema-change-impact.md` §2 (строка 34 —
ожидание от маппера) и §6 (строка `Position@IndexCode`/`Quantity@KUnit` — путь реопена) ·
`app/Services/Procurement/Prepare/Gsfx/GsfxReader.php` (`parsePosition()` L143-175,
`parseResources()` L193-224, `tallyUnmappedTopLevel()` L295-308) ·
`app/Dto/Procurement/Ai/Gsfx/GsfxPosition.php`/`GsfxDocument.php` · `estimates/files/Администрация
культуры и спорта 3.gsfx` (реальный эталон, единственный `.gsfx` корпуса) · `estimates/manifest.json`
(код `EST-GSFX-LSR-001`).

**Open risks:**
- **`PassportResponse::$work_types` на боевом (не-gsfx) потоке ВСЕГДА пуст** — осознанное
  исключение из D33 (research/06 §5.5/§6 п.3), не баг P5.1/P5.2.
- **`EstimateToContractMapper.php` НЕ маппит `work_type_name`/`work_types`** — вход P9.3 (без
  изменений от P4.5).
- **Windowed-путь не обеспечивает §4а п.3** — кандидат отдельного fix-item вне P5–P9 (без
  изменений).
- **Пять колонок адреса сметы без писателя** до P6.3/P5.2 (D10, без изменений).
- **`prc_estimate_work_types.overhead_*`/`profit_*`/`parent_id` — только gsfx-веткой** (без
  изменений).
- **`verified_at`/`verified_by` без писателя внутри плана** (D89 п. в, без изменений).
- **НОВОЕ (эта сессия): `Position@IndexCode`/`<Indexes>` — реопен P3.3 требуется** (см. блок
  эскалации выше и `phases/P5.md` P5.1). Ранее в этом же списке долг числился как «Долги
  GsfxReader (Tzm2022, coefficients-по-вложенности, IndexCode/KUnit) — не чинятся схемой,
  адресуются P3.3 РЕОПЕНОМ через эскалацию, когда P5.1 их реально встретит (не раньше)» —
  условие наступило, статус общего долга по IndexCode переходит из «не раньше» в «сейчас».
  Оставшиеся долги того же списка (Tzm2022 — тальится в `unmapped`, видим; `coefficients` —
  тальится в `unmapped['Koefficients']`, видим; `Quantity@KUnit` — НЕ блокирует P5.1: `quantity`
  берётся из `Quantity/@Result`, уже финального decimal, `KUnit` — внутренний множитель формулы,
  не отдельное поле контракта) НЕ требуют реопена сейчас — они видимы через `unmappedReport` или
  не входят в состав gold-контракта §8.2.
- **Дефект `plan-lint`: ложное срабатывание на отрицании** (унаследовано из P1/P2, без изменений).
- Все риски, унаследованные от P1–P4.5, без изменений (acceptance P3.1 Validation-5 недостижим по
  букве; P3.2 Validation-3 в буквальной форме недостижима; `phpstan-baseline.neon` правился вне
  `Files` девять раз подряд; потолок OCR 50 % — на нём нельзя строить автоматический gold;
  `pdf_ocr` 132-МБ эталона весит 133 МБ и не влезает в лимит маршрута; ветка изображений OCR-ит
  только картинки с DPI в метаданных; `.gsfx` несёт слой `ImplemActs`, сознательно не
  моделируемый; RAR-путь `ArchiveStrategy` покрыт частично; `lsar` вызывается литералом; формат
  `text_grid` зафиксирован докблоком; 21 файл КС-3 вне extraction; OQ-3/OQ-4 открыты).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman: `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib`
  → `pre_mutation: allow` прогнан реально для P5.1 в этой сессии (никакой мутации кода после
  этого не произошло — эскалация найдена ДО первой строки `Files`). Следующим шагам использовать
  тот же PYTHONPATH.
- **`git add plans/<PLAN-ID>` затягивает эфемерные логи тулинга** — коммитить ПО ЯВНЫМ ПУТЯМ
  (`phases/P5.md`, `plan.md`, `handoff.md`, `journal.jsonl`), не `git add plans/<PLAN-ID>` целиком
  (без изменений от P4.5).
- **`plan.md` неделим между item-коммитом и бухгалтерией** (без изменений).
- Посторонний дифф в рабочем дереве (унаследован от P3, не трогался P4.4/P4.5/P5.1): `.gitignore`
  (M), `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны); `REDIS_PORT=6391`; хостовый `phpstan`/`pint` —
  `DB_HOST=127.0.0.1 DB_PORT=<порт из docker compose port postgres 5432>` (сегодня `5455`).

**Workarounds/Deferred/Open questions:**
- workarounds: без изменений от P4.5 (два env-файла docker compose; `REDIS_PORT=6391`;
  `docker compose … exec app php artisan …` напрямую; ACL для bind-mount при записи из
  контейнера; `git config --global --add safe.directory` в контейнере; `storage/app/private/
  estimates` не читается с хоста — эталон брать из `estimates/files/`; сигнатура
  `GsfxReader::parse($propertiesRaw, $dataXmlRaw)`; не использовать `tinker` для проверок,
  создающих модели верхнего уровня — фабрики пишут в DEV-БД).
- deferred: без изменений от P4.5 (тест-двойники цепочки стратегий, `WordStrategyTest`, RAR-кейсы,
  `--image-dpi`, `@property`-аннотации моделей, `prc_estimate_totals.kind` доопределение,
  `ref_work_types`, писатель `verified_at`, писатель адреса сметы/переиндексация окон,
  `EstimateToContractMapper` маппинг видов работ) + **НОВОЕ**: расширение `GsfxReader`/
  `GsfxDocument` под `Position@IndexCode` и каталог `<Indexes>` — вход `/task:plan-design
  2026.07.26-EST-TRAIN P5.1`.
- open_questions: OQ-3, OQ-4 — без изменений. OQ-5/OQ-6 закрыты D89/D90.
