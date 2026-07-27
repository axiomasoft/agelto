# HANDOFF — 2026-07-27 — after P3.9

**Next:** close-phase: task:plan-close 2026.07.26-EST-TRAIN P3

| Параметр | Значение |
|:--|:--|
| Model class | economy |
| Effort | low — plan-close сверяет 🟢/🟠 по git-фактам, пишет Phase Handoff и Update Log; открытых решений нет |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P3 (9/9 items терминальны: P3.1–P3.7 🟠, P3.8–P3.9 🟢) — Phase Handoff в `phases/P3.md` (перезаписать устаревший D110-блок), Status Board §4 `plan.md`, docs-sync (`docs/specs/v2/03-document-pipeline.md` §3.3 — уже правился P3.1, сверить факт после P3.9), Update Log |

```
/task:plan-close 2026.07.26-EST-TRAIN P3
```

**Done:** P1 — 🟢 4/4. P2 — терминальна (2/4 🟢, 2/4 🟠). P4 — терминальна (3/5 🟢, 2/5 🟠).
**P3 — ТЕРМИНАЛЬНА, ждёт `plan-close` (реопен D110 закрыт):** 9/9 items закрыты (P3.1–P3.7 🟠,
P3.8–P3.9 🟢). P5.1 остаётся `🔴 Blocked` до перезапуска ТЕМ ЖЕ ТЗ (эскалация снимается закрытием
P3.9, но перезапуск — отдельный заход после `plan-close P3`).

Этот заход — ИСПОЛНЕНИЕ P3.9 (`task:plan-run`, режим D18, модель/effort сессии = sonnet/high,
Routing item'а — implementation/high, совпадение без перебора/недобора). Сделано: `GsfxReader`
дочитывает пять оставшихся величин реопена (D110) — позиционные `Position/Koefficients/K` →
`GsfxPosition::$coefficients` (связь вложенностью, новый DTO `GsfxCoefficient`), документные
`Document/Koefficients/K` → `GsfxDocument::$coefficients` (отдельный список); `Mch/Tzm2022` →
`GsfxResource::$tzm2022` (новый DTO `GsfxTzm2022`) на ЛЮБОМ известном ресурсном теге; нормативы
`Vid_Rab@Nacl/@Plan/@NaclCurr/@PlanCurr` → `GsfxWorkType`, обход ВСЕХ блоков
`VidRab_Catalog/Vids_Rab` (не только первого), `@Type` блока → `GsfxWorkTypeGroup::$catalog_type`;
`Position@Vr2001` → `GsfxPosition::$work_type_id` как есть. Докблок `GsfxPosition` исправлен
(утверждение об отсутствии связи коэффициент↔позиция снято). Фикстура расширена (второй блок
`Vids_Rab`, `Nacl`/`NaclMask` на `Vid_Rab`, `Vr2001` на обеих позициях, позиционный и документный
`Koefficients/K`, `Tzm2022` внутри `Mch`) — семь новых Pest-кейсов + приведённый `unmapped`-кейс,
25/25 зелёных. Прогон на реальном эталоне (прямой вызов `GsfxReader::parse()`, не через БД) дал
ТОЧНОЕ совпадение с ожиданиями ТЗ: **3**/**2**/**81**/**15**/**7**/**110** (все шесть чисел), резолв
`work_type_id` — **110 из 110**; нужные ключи (`Koefficients`, `Tzm2022`, `Position@Vr2001`,
`Vid_Rab@Nacl/@Plan/@NaclCurr/@PlanCurr`, `Vids_Rab@Type`) ушли из `unmapped`, `Quantity@KUnit` (49),
`Vid_Rab@NaclMask` (17), `WorksList` (46), `ImplemActs` (1) остались. Grep подтвердил: смена типа
`GsfxPosition::$coefficients` безопасна (0 сторонних потребителей). phpstan (из контейнера) — `[OK]
No errors`; pint — один стилевой файл автопофикшен с хоста, повторный прогон зелёный. Known
Deviations: process-порядок (Task Contract pre-mutation прогнан после начала правок, статус `🟡 In
progress` не выставлялся отдельным шагом до работы) — material-отклонений нет, статус 🟢 Done.

**Ключевое, что было доделано в P3.9** (таблица предыдущего захода закрыта):

| Величина | Факт эталона | Носитель | Чинит |
|:--|:--|:--|:--|
| `Position/Koefficients/K` + документные `K` | 3 + 2, четыре рода значений (`Value_OZ/EM/ZM/MT`) | `GsfxPosition::$coefficients`/`GsfxDocument::$coefficients` (P5.1 → `RowModifier`) | **P3.9 — закрыт 🟢** |
| `Mch/Tzm2022` | 81, вложен ВНУТРЬ `Mch`, `@Quantity` ≡ `1` (ставка, не часы) | `GsfxResource::$tzm2022` (P5.1 → `RowResource` `labor`, SR-07) | **P3.9 — закрыт 🟢** |
| `Vid_Rab@Nacl/@Plan/@NaclCurr/@PlanCurr` | 15×4; блоков `Vids_Rab` два, оба читаются | `GsfxWorkType` 4 ставки (P5.1 → `EstimateWorkType`) | **P3.9 — закрыт 🟢** |
| `Position@Vr2001` | резолвится в `Vid_Rab@ID` 110/110 | `GsfxPosition::$work_type_id` (P5.1 → ссылка на вид работ) | **P3.9 — закрыт 🟢** |

**Remaining:** `task:plan-close 2026.07.26-EST-TRAIN P3` (закрыть фазу — 9/9 items терминальны) →
перезапуск P5.1 ТЕМ ЖЕ ТЗ (`Scope`/`Inputs` item'а не менялись; изменилась версия `GsfxDocument`,
на которую он опирается — реопен полностью закрыт) → P5.2–P9.6.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta v0.17.0, §3 Routing строка
P3.8–P3.9, Status Board P3 = 2/9 🟢 (терминальна, 7/9 🟠), D1–D112) ·
`plans/2026.07.26-EST-TRAIN/phases/P3.md` (P3.9 Completion Notes, item-коммит e97673a) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (ТЗ P5.1 + блок эскалации — не менялись) ·
`findings/P3-gsfx-reader-gaps-2026-07-27.md` (числа и команда воспроизведения, §5–§7) ·
`research/16-gsfx-reader-reopen.md` (границы «ридер несёт — маппер толкует», §1/§2/§4) ·
`research/06-schema-decision.md` §5.2 (D94) и §7 · `research/08-schema-change-impact.md` §2 ·
`app/Services/Procurement/Prepare/Gsfx/GsfxReader.php` (после P3.9: `parseCoefficients()`,
`parseTzm2022()`, `parseWorkTypeGroups()` по всем блокам) · `app/Dto/Procurement/Ai/Gsfx/**` (после
P3.9: `GsfxCoefficient`, `GsfxTzm2022`, `GsfxPosition::$work_type_id`, `GsfxWorkType` нормативы,
`GsfxWorkTypeGroup::$catalog_type`) · `estimates/files/Администрация культуры и спорта 3.gsfx`.

**Open risks:**
- **Форма `unmapped` дальше сузилась** (P3.9 добавил чтение `Koefficients`/`Tzm2022`/`Vid_Rab@Nacl…`/
  `Position@Vr2001`/`Vids_Rab@Type`) — потребители те же два, под контролем (`GsfxStrategy.php:138`,
  `GsfxStrategyTest.php`). `unmappedReport` маппера (P5.1) и сводный отчёт P5.3 читают эту форму.
- **Смена типа `GsfxPosition::$coefficients`** (`list<string>` → `list<GsfxCoefficient>`) подтверждена
  grep'ом (0 сторонних потребителей) и зелёным phpstan — безопасна, поле было пустым.
- **`Quantity@KUnit` остаётся незаполненным** (носителя в контракте gold нет, D104) — виден в
  `unmapped` числом **49** на реальном эталоне, молчаливым не является.
- **Толкование `Tzm2022` vs `Tzm` — открытый вопрос МАППЕРА, не ридера:** обе формы сосуществуют на
  39 позициях, наивная сумма даст двойной счёт `labor_hours_machinists`. P5.1 обязан решить явно.
- **`PassportResponse::$work_types` на боевом (не-gsfx) потоке ВСЕГДА пуст** — осознанное исключение
  из D33, не баг P3.8/P3.9.
- **`EstimateToContractMapper.php` НЕ маппит `work_type_name`/`work_types`** — вход P9.3.
- **Windowed-путь не обеспечивает §4а п.3** — кандидат отдельного fix-item вне P5–P9.
- **Пять колонок адреса сметы без писателя** до P6.3/P5.2 (D10) · **`prc_estimate_work_types.overhead_*`/
  `profit_*`/`parent_id` — только gsfx-веткой** · **`verified_at`/`verified_by` без писателя внутри
  плана** (D89 п. в) — все без изменений.
- **Дефект `plan-lint`: ложное срабатывание на отрицании** (унаследовано из P1/P2, без изменений).
- Все риски, унаследованные от P1–P4.5, без изменений (acceptance P3.1 Validation-5 недостижим по
  букве; P3.2 Validation-3 в буквальной форме недостижима; `phpstan-baseline.neon` правился вне
  `Files` девять раз подряд — P3.9 его не трогал вовсе, а хостовый прогон phpstan падает на его
  невалидных путях (запускать `phpstan analyse` из контейнера, как P3.8/P3.9); потолок OCR 50 % — на
  нём нельзя строить автоматический gold; `pdf_ocr` 132-МБ эталона весит 133 МБ и не влезает в лимит
  маршрута; ветка изображений OCR-ит только картинки с DPI в метаданных; `.gsfx` несёт слой
  `ImplemActs`, сознательно не моделируемый; RAR-путь `ArchiveStrategy` покрыт частично; `lsar`
  вызывается литералом; формат `text_grid` зафиксирован докблоком; 21 файл КС-3 вне extraction;
  OQ-3/OQ-4 открыты).
- Модуль `task.task_contract`/`task.route_evidence` в репозитории отсутствует, но ДОСТУПЕН из
  монорепо swissknifeman:
  `PYTHONPATH=/home/vostrikov/projects/packages/swissknifeman/packages/task/lib`.
- **`git add plans/<PLAN-ID>` затягивает эфемерные логи тулинга** — коммитить ПО ЯВНЫМ ПУТЯМ
  (`phases/P3.md`, `plan.md`, `handoff.md`, `journal.jsonl`), не `git add plans/<PLAN-ID>` целиком.
- **`plan.md` неделим между item-коммитом и бухгалтерией** (без изменений).
- Посторонний дифф в рабочем дереве (унаследован от P3, не трогался P3.9): `.gitignore` (M),
  `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md` (untracked),
  `.swissknife/`, `plans/2026.07.26-EST-TRAIN/.claude/`, `plans/2026.07.26-EST-TRAIN/.swissknife/`,
  `plans/2026.07.26-EST-TRAIN/phases/.claude/`.
- Контейнеры (`app`/`horizon`/`scheduler`/`redis`) — `docker compose --env-file .env --env-file
  .env.docker` (два env-файла обязательны); `REDIS_PORT=6391`; хостовый `pint --dirty` (автофикс) —
  прямо с хоста; `phpstan analyse` — ИЗ КОНТЕЙНЕРА (хостовый прогон падает на baseline).
- **`storage/framework/*` — именованный Docker-volume, НЕ bind-mount** — временные php-скрипты для
  прямого вызова `GsfxReader::parse()` — в bind-mounted путь (корень репо), удалять после
  использования, не входят в `Files` (тот же приём, что P3.8).

**Workarounds/Deferred/Open questions:**
- workarounds: без изменений от P3.8-захода (два env-файла docker compose; `REDIS_PORT=6391`;
  `docker compose … exec app php artisan …` напрямую; ACL для bind-mount при записи из контейнера;
  `git config --global --add safe.directory` в контейнере; `storage/app/private/estimates` не
  читается с хоста — эталон брать из `estimates/files/`; сигнатура
  `GsfxReader::parse($propertiesRaw, $dataXmlRaw)`; прогон эталона — ПРЯМЫМ вызовом
  `GsfxReader::parse()`, не через `GsfxStrategy::process()`; временный php-скрипт — в bind-mounted
  пути, удалён после использования; `phpstan analyse` — только из контейнера, не с хоста). При
  правке синтетической фикстуры `tests/Fixtures/gsfx/Data.xml` — редактировать UTF-8-копию и
  конвертировать `iconv -f utf-8 -t windows-1251`, прямая правка бинарным редактором испортит
  кириллицу.
- deferred: без изменений от P4.5/P3.8 (тест-двойники цепочки стратегий, `WordStrategyTest`,
  RAR-кейсы, `--image-dpi`, `@property`-аннотации моделей, `prc_estimate_totals.kind`
  доопределение, `ref_work_types`, писатель `verified_at`, писатель адреса сметы/переиндексация
  окон, `EstimateToContractMapper` маппинг видов работ) + `Quantity@KUnit`, маски `Vid_Rab@*Mask` и
  КФС-шифры — сознательно вне скоупа реопена (D112).
- open_questions: OQ-3, OQ-4 — без изменений. Новых открытых вопросов этот заход не породил.
