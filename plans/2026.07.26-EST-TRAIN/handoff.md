# HANDOFF — 2026-07-27 — after P3.8

**Next:** run-items: task:plan-run 2026.07.26-EST-TRAIN P3.9 — второй (последний) item реопененной
фазы P3.

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high — та же граница «ридер несёт, маппер толкует», четыре оставшиеся величины (коэффициенты, `Tzm2022`, нормативы НР/СП, `Vr2001`), цена ошибки та же — «эталон» F1 (§3 Routing, строка P3.8–P3.9) |
| Capabilities | — |
| Context | same-session — item |
| Суть | `GsfxReader` дочитывает `Position/Koefficients/K` + `Document/Koefficients/K` (`GsfxCoefficient`), `Mch/Tzm2022` (`GsfxTzm2022` на `GsfxResource`), нормативы `Vid_Rab@Nacl/@Plan/@NaclCurr/@PlanCurr` по ОБОИМ блокам `Vids_Rab`, и `Position@Vr2001` → `GsfxPosition::$work_type_id`. Холодный вход: `plan.md` (§3 Routing, D2/D110/D111/D112) → `phases/P3.md` P3.9 (все 16 полей) → `findings/P3-gsfx-reader-gaps-2026-07-27.md` §5–§7 → `research/16-gsfx-reader-reopen.md` §1/§2/§4 |

```
run-items: task:plan-run 2026.07.26-EST-TRAIN P3.9

РЕЖИМ: исполнение НОВОГО item'а реопененной фазы P3 (D110), последнего в реопене — не реопен, не редизайн
ВХОД: plans/2026.07.26-EST-TRAIN/phases/P3.md → item «P3.9 — Остальные носители gsfx-ветки: коэффициенты, Tzm2022, нормативы НР/СП, Vr2001» (все 16 полей); фактура — findings/P3-gsfx-reader-gaps-2026-07-27.md §5–§7; границы — research/16-gsfx-reader-reopen.md §1/§2/§4; Inputs P3.9 ссылается на Deliverables P3.8 (атрибутный талль — новые прочитанные атрибуты обязаны исчезнуть из unmapped)
СКОУП: 1) DTO GsfxCoefficient (8 полей source-именами) + GsfxPosition::$coefficients (list<string>→list<GsfxCoefficient>) + GsfxDocument::$coefficients (документные K отдельным списком, без раскладки по позициям); 2) DTO GsfxTzm2022 (work_class/quantity/price) + GsfxResource::$tzm2022 на ЛЮБОМ известном ресурсном теге; 3) GsfxWorkType += nacl/nacl_curr/plan/plan_curr; обход ВСЕХ блоков VidRab_Catalog/Vids_Rab (не только первого), GsfxWorkTypeGroup::$catalog_type из @Type блока; 4) GsfxPosition::$work_type_id из Position@Vr2001 как есть; 5) исправление докблока GsfxPosition (снять «связи коэффициента с позицией в разметке нет»); 6) расширение синтетической фикстуры + Pest-кейсы (минимум девять — Code Guidance перечисляет по одному на величину); 7) подтверждение grep'ом, что смена типа $coefficients безопасна (поле всегда было пустым) — в Completion Notes
НЕ ТРОГАТЬ: раскладка Value_OZ/EM/ZM/MT по девяти полям RowModifier, решение Tzm vs Tzm2022 → labor_hours_machinists, разбор строки Options в структуру, резолв work_type_id в дерево EstimateWorkType (всё это — маппер P5.1, D94) · Quantity@KUnit (носителя нет, видим числом после P3.8) · маски Vid_Rab@NaclMask/@PlanMask и шифры NrKfsCode/NKB/PNB/… (носителя в EstimateWorkType нет, D33) · слой актов ImplemActs, WorksList/Work · закрытый код P3.1–P3.8 · схему prc_estimate*, DTO Estimate*, спеку и код P5.1
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P4 — терминальна (3/5 🟢, 2/5 🟠).
**P3 — РЕОПЕНЕНА (D110), больше не терминальна:** 8/9 items закрыты 🟠/🟢 (P3.1–P3.8, их код и
коммиты не трогаются), 1/9 открыт ⬜ (P3.9). P5.1 — `🔴 Blocked` без единой строки кода
(эскалация найдена на этапе Required Reads, снимется закрытием P3.9).

Этот заход — ИСПОЛНЕНИЕ P3.8 (`task:plan-run`, режим D18, модель/effort сессии = sonnet/high,
Routing item'а — implementation/high, совпадение без перебора/недобора). Сделано: `GsfxReader`
считает непрочитанные АТРИБУТЫ открываемых узлов (`tallyUnreadAttributes()`, ключ `Тег@Атрибут`,
D111); `GsfxPosition::$index_code` из `Position@IndexCode`; `GsfxIndex`/`GsfxIndexCatalog` +
`GsfxDocument::$index_catalog` из ПРЯМОГО потомка `Document/Indexes` — две раздельные карты
`positions`/`resources`; докблоки `GsfxPosition`/`GsfxDocument` исправлены; фикстура
`tests/Fixtures/gsfx/Data.xml` расширена блоком `<Indexes>` (обе карты, пересекающийся `@Code`,
запись без `MT`/`EM`, атрибут `IndexCode` на позиции); семь новых Pest-кейсов + приведённый к новой
форме кейс `unmapped` — 18/18 зелёных. Прогон на реальном эталоне (прямой вызов
`GsfxReader::parse()`, не через БД) дал ТОЧНОЕ совпадение с ожиданиями ТЗ: 64 позиции с
`index_code`, каталог 47/79 записей, три контрольных разыменования сошлись дословно (включая
`Code="312"` → `mt=null && em=null`); `unmapped['Indexes']` стал `null` (элемент разобран),
`unmapped['Quantity@KUnit']` — `49` (долг стал видимым, как предсказано). phpstan — без новых
ошибок, pint — зелёный. Расхождений с item'ом нет: `Known Deviations` — «—», статус 🟢 Done (не
🟠 — material-отклонений не было).

**Ключевое, что осталось после P3.8** (из таблицы предыдущего захода, актуализировано):

| Величина | Факт эталона | Носитель | Чинит |
|:--|:--|:--|:--|
| `Position@IndexCode` + каталог `<Indexes>` | 64/110; дереференс сходится 64/64 (позиции) и 239/239 (ресурсы) | `EstimateRow`/`RowResource` `index_code`/`index_value` (D94) | **P3.8 — закрыт 🟢** |
| `unmapped` не видит атрибутов | видимость атрибутов включена | — (механизм) | **P3.8 — закрыт 🟢, D111** |
| `Position/Koefficients/K` + документные `K` | 3 + 2, четыре рода значений (`Value_OZ/EM/ZM/MT`) | `RowModifier` (9 полей) | P3.9 |
| `Mch/Tzm2022` | 81, вложен ВНУТРЬ `Mch`, `@Quantity` ≡ `1` (ставка, не часы) | `RowResource` `labor` (SR-07) | P3.9 |
| `Vid_Rab@Nacl/@Plan/@NaclCurr/@PlanCurr` | 15×4; блоков `Vids_Rab` два, читается первый | `EstimateWorkType` 4 ставки | P3.9 |
| `Position@Vr2001` | резолвится в `Vid_Rab@ID` 110/110 | ссылка позиции на вид работ | P3.9 |

**Remaining:** P3.9 (строго последний — правит тот же `parsePosition()` и тот же тест-файл, что и
P3.8; `Inputs` ссылается на Deliverables P3.8) → закрытие фазы P3 (`close: task:plan-close
2026.07.26-EST-TRAIN P3`) → перезапуск P5.1 ТЕМ ЖЕ ТЗ (`Scope`/`Inputs` item'а не менялись;
изменилась только версия `GsfxDocument`, на которую он опирается) → P5.2–P9.6. P5.2 формально
исполним раньше (не зависит от P5.1 по данным), но phase-first правило всё равно держит фазу P5 за
реопененной P3 — решение начинать ли параллельную ветку принадлежит владельцу.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta v0.17.0, §3 Routing строка
P3.8–P3.9, Status Board P3 = 1/9, D1–D112) · `plans/2026.07.26-EST-TRAIN/phases/P3.md` (P3.8
Completion Notes, ТЗ P3.9 не менялось) · `plans/2026.07.26-EST-TRAIN/phases/P5.md` (ТЗ P5.1 + блок
эскалации — не менялись) · `findings/P3-gsfx-reader-gaps-2026-07-27.md` (числа и команда
воспроизведения, §5–§7 — вход P3.9) · `research/16-gsfx-reader-reopen.md` (границы «ридер несёт —
маппер толкует», §1/§2/§4 — вход P3.9) · `research/06-schema-decision.md` §5.2 (D94) и §7 ·
`research/08-schema-change-impact.md` §2 (что обязан заполнить P5.1, включая величины P3.9) ·
`app/Services/Procurement/Prepare/Gsfx/GsfxReader.php` (после P3.8: `tallyUnreadAttributes()`,
`parseIndexCatalog()`) · `app/Dto/Procurement/Ai/Gsfx/**` (после P3.8: `GsfxIndex`,
`GsfxIndexCatalog`, `GsfxPosition::$index_code`, `GsfxDocument::$index_catalog`) ·
`estimates/files/Администрация культуры и спорта 3.gsfx`.

**Open risks:**
- **Форма `unmapped` расширилась атрибутными ключами (D111, реализовано P3.8)** — потребителей
  два и оба под контролем: `GsfxStrategy.php:138` (пишет как есть) и приведённый к новой форме
  кейс `GsfxStrategyTest.php` (`unmapped заполняется элементами и атрибутами…`). `unmappedReport`
  маппера (P5.1) и сводный отчёт P5.3 ещё не написаны — их ТЗ читают новую форму. P3.9 добавит ещё
  атрибутные ключи (`Vid_Rab@Nacl` и т.п. должны ИСЧЕЗНУТЬ из `unmapped`, как только их прочитают)
  — уже учтено в ТЗ P3.9 Implementation Rules.
- **Смена типа `GsfxPosition::$coefficients`** (`list<string>` → `list<GsfxCoefficient>`, P3.9)
  безопасна ровно потому, что поле всегда было пустым; исполнителю предписано подтвердить это
  grep'ом по потребителям и зелёным phpstan, а не рассуждением.
- **`Quantity@KUnit` остаётся незаполненным** (носителя в контракте gold нет; `unit_multiplier`
  D104 отдал бэкенду, выводящему его из строки `unit`) — виден в `unmapped` числом **49** на
  реальном эталоне (подтверждено прогоном P3.8), молчаливым не является.
- **Толкование `Tzm2022` vs `Tzm` — открытый вопрос МАППЕРА, не ридера:** `Tzm2022@Quantity` ≡ `1`
  (ставка по разряду), прямой `Tzm` — часы, обе формы сосуществуют на 39 позициях. Наивная сумма
  даст двойной счёт `labor_hours_machinists`. P5.1 обязан решить это явно (и, если утверждённое
  распределение не даёт ответа, эскалировать в P4 — не додумывать).
- **`PassportResponse::$work_types` на боевом (не-gsfx) потоке ВСЕГДА пуст** — осознанное
  исключение из D33 (research/06 §5.5/§6 п.3), не баг P3.8/P3.9.
- **`EstimateToContractMapper.php` НЕ маппит `work_type_name`/`work_types`** — вход P9.3.
- **Windowed-путь не обеспечивает §4а п.3** — кандидат отдельного fix-item вне P5–P9.
- **Пять колонок адреса сметы без писателя** до P6.3/P5.2 (D10) · **`prc_estimate_work_types.overhead_*`/
  `profit_*`/`parent_id` — только gsfx-веткой** · **`verified_at`/`verified_by` без писателя внутри
  плана** (D89 п. в) — все без изменений.
- **Дефект `plan-lint`: ложное срабатывание на отрицании** (унаследовано из P1/P2, без изменений).
- Все риски, унаследованные от P1–P4.5, без изменений (acceptance P3.1 Validation-5 недостижим по
  букве; P3.2 Validation-3 в буквальной форме недостижима; `phpstan-baseline.neon` правился вне
  `Files` девять раз подряд — P3.8 его не трогал вовсе; потолок OCR 50 % — на нём нельзя строить
  автоматический gold; `pdf_ocr` 132-МБ эталона весит 133 МБ и не влезает в лимит маршрута; ветка
  изображений OCR-ит только картинки с DPI в метаданных; `.gsfx` несёт слой `ImplemActs`,
  сознательно не моделируемый; RAR-путь `ArchiveStrategy` покрыт частично; `lsar` вызывается
  литералом; формат `text_grid` зафиксирован докблоком; 21 файл КС-3 вне extraction; OQ-3/OQ-4
  открыты).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman:
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib`.
- **`git add plans/<PLAN-ID>` затягивает эфемерные логи тулинга** — коммитить ПО ЯВНЫМ ПУТЯМ
  (`phases/P3.md`, `plan.md`, `roadmap.md`, `handoff.md`, `journal.jsonl`, `findings/**`,
  `research/**`, `brief/**`), не `git add plans/<PLAN-ID>` целиком.
- **`plan.md` неделим между item-коммитом и бухгалтерией** (без изменений).
- Посторонний дифф в рабочем дереве (унаследован от P3, не трогался P3.8): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны); `REDIS_PORT=6391`; хостовый `phpstan`/`pint` —
  `DB_HOST=127.0.0.1 DB_PORT=<порт из docker compose port postgres 5432>` (сегодня `5455`).
- **`storage/framework/*` — именованный Docker-volume, НЕ bind-mount** (в отличие от корня репо) —
  скрипты для `tinker --execute="require ..."` писать в bind-mounted путь (корень репо или
  `tests/Fixtures/**`), не в `storage/**`; временный скрипт P3.8-прогона удалён из корня репо после
  использования, не входил в `Files` (новое наблюдение этого захода).

**Workarounds/Deferred/Open questions:**
- workarounds: без изменений от P4.5/P3.8-захода (два env-файла docker compose; `REDIS_PORT=6391`;
  `docker compose … exec app php artisan …` напрямую; ACL для bind-mount при записи из
  контейнера; `git config --global --add safe.directory` в контейнере; `storage/app/private/
  estimates` не читается с хоста — эталон брать из `estimates/files/`; сигнатура
  `GsfxReader::parse($propertiesRaw, $dataXmlRaw)`; не использовать `tinker` для проверок,
  создающих модели верхнего уровня — фабрики пишут в DEV-БД, поэтому прогон эталона в P3.8/P3.9
  идёт ПРЯМЫМ вызовом `GsfxReader::parse()`, а не `GsfxStrategy::process()`; временный php-скрипт
  для `tinker --execute="require ..."` — в bind-mounted пути, не в `storage/**`). При разборе
  `Data.xml` сторонними инструментами (python/`ElementTree`) после `iconv` подменять декларацию
  `encoding="windows-1251"` → `"utf-8"`, иначе парсер падает на `not well-formed` — артефакт метода,
  а не порча файла (`findings/…` §0); при ПРАВКЕ синтетической фикстуры `tests/Fixtures/gsfx/
  Data.xml` — редактировать UTF-8-копию и конвертировать `iconv -f utf-8 -t windows-1251`, прямая
  правка бинарным редактором испортит кириллицу.
- deferred: без изменений от P4.5 (тест-двойники цепочки стратегий, `WordStrategyTest`, RAR-кейсы,
  `--image-dpi`, `@property`-аннотации моделей, `prc_estimate_totals.kind` доопределение,
  `ref_work_types`, писатель `verified_at`, писатель адреса сметы/переиндексация окон,
  `EstimateToContractMapper` маппинг видов работ) + `Quantity@KUnit`, маски `Vid_Rab@*Mask` и
  КФС-шифры — сознательно вне скоупа реопена (D112), видимы тальём после P3.8.
- open_questions: OQ-3, OQ-4 — без изменений. OQ-5/OQ-6 закрыты D89/D90. Новых открытых вопросов
  этот заход не породил.
