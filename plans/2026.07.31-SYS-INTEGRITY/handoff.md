# HANDOFF — 2026-08-04 — after P5.3

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — инварианты порогов приёмки, платный необратимый прогон (§3 Routing P5.4) |
| Context | continue (/clear) — ручной item |
| Суть | P5.4 — baseline платного цикла eval ДО правок схемы + критерий непрегрессии числами; гейт владельца по СУММЕ прогона |
| Команда | — |

Сессия сейчас на `opus/high`; Routing P5.4 предписывает `implementation/high` — перебор безвреден,
но `plan-exec` этот item не берёт (`Exec = manual`). Код-блок ниже несёт полную D18-форму:

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P5.4
```

**Сделано этим заходом (`plan-run`, opus/high, 2026-08-04).** P5.3 закрыт 🟢 Done, item-коммит
`2abf097`; Deliverable — `research/06-estimate-completeness.md` (14 разделов, кода нет):

- **GAP-2 своего носителя НЕ получает.** Тест (в) снят прямым чтением корпуса: обе доминирующие
  формы (ресурсно-индексный ЛСР 421/пр и базисно-индексная СН-2012) печатают ОТ/ЭМ/ЗПМ/МАТ
  СТРОКАМИ внутри позиции, а не колонками — носитель `prc_estimate_resources.resource_type` +
  `quantity`/`price_*` уже заведён донором и заполняется. Четыре колонки на `prc_estimate_items`
  стали бы вторым носителем одного факта.
- **GAP-8 → колонка `prc_estimates.regulatory_base` + ключи `passport.regulatory_amendments` и
  `passport.index_letter`** (`text nullable`, цитата документа). «Дата ценового среза» отдельного
  носителя не получает — уже выражена `prc_estimates.price_level`.
- **Строки не-`Item` класса не материализуются** (решение владельца): три ключа КС-3
  `attributes.amount_*`, печатные агрегаты позиции и итог раздела живут в контракте и gold,
  в реляционную модель не идут — потребителя витрины нет, арифметика проверяется
  `EstimateValidator` по контракту до персиста.
- **Класс B: приняты два писателя (оба — item P5.5).** Нормализатор `unit` →
  `unit_base`/`unit_multiplier` на позиции и ресурсе (тотальный: неразбираемый вход → цитата и
  множитель `1`, исключений не бросает) и запись `prc_estimate_work_types` + резолв `work_type_id`
  через `findOrCreate(estimate_id, title)`. Новый факт: у таблицы видов работ НЕТ писателя вообще
  (`grep -rn "WorkType" app/Repositories/ app/Services/Procurement/Analyzers/` = 0 на `2abf097`).
- **Новых индексов — 0**, все семь кандидатов перечня P5.1 §4 разобраны с причиной отказа
  (внутрисметные сценарии отбираются по уже проиндексированному `estimate_id`; предикат по цитате
  редакции — `LIKE`, который btree не ускоряет).
- **Новых enum'ов — 0**: тип нормы читается из уже хранимого `code` (потребитель не назван),
  источник цены ресурса — 1 файл < 9, признак НДС — носитель уже есть (`TotalKind::Vat`).
- **Разрешена коллизия носителей:** `EstimatePassport::$reference` сужается до основания
  составления сметы (проектная/техническая документация), редакция нормативов — только
  `regulatory_base`. Правка докблока — P5.5.
- **Итог для P5.5:** одна миграция (`add_regulatory_base_to_prc_estimates_table`), три новых поля
  контракта паспорта, два писателя класса B, ноль enum'ов, ноль индексов, `RowAttributes::ALLOWED_KEYS`
  не меняется. **Итог для P5.6:** машинно-заполняемых новых полей ноль — все три получают вердикт
  D46 «F1 не меряется», `*_FIELD_MAP` не расширяются.

**Status:** 🟢 Done. Known Deviations — нет; Pending Work — нет.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5.1** 🟠, **P5.2** 🟠, **P5.3** 🟢 — фаза P5 **не терминальна** (5 items впереди).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Внутри P5 порядок items совпадает с
  нумерацией: следующий — **P5.4** (baseline платного цикла, `implementation/high manual`).
- **Гейт владельца по деньгам** стоит В P5.4: сумма полного цикла `--split=all` называется и
  апрувится ДО прогона (D45, Implementation Rules P5.4 п.3). Baseline обязан быть снят ДО первой
  правки контракта P5.5 — исходящая схема собирается рефлексией и подхватит три новых поля
  автоматически.
- **`## Phase Handoff` фазы P2 не собран** («—») — висящий bookkeeping-хвост:
  `/task:plan-close 2026.07.31-SYS-INTEGRITY P2`, продолжение P5 не блокирует.
- **Follow-up вне плана:** десять pre-existing красных тестов (`open-questions.md` OQ-8 п.3) — этим
  item'ом не затронуты (кода нет).
- **Pending Work P5.2:** `RerunRangeAction` не вызывает `replaceResources()` вовсе — находка P5.1,
  вне Scope P5.2; владелец не назначен, фикс — НОВЫЙ item при заведении.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/06-estimate-completeness.md` — утверждённое
  распределение: таблицы A/B/C, индексы, отложенные, вердикты D46, точный вход P5.5 и P5.6.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-corpus-coverage.md` — инвентарь записи и
  дельта-сверка (вход P5.3).
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/persistence_inventory.php` — воспроизводимый зонд
  записи (реюз P5.2; понадобится P5.5 для контрольной сверки новых полей).
- `phases/P5.md` — Phase Status, Completion Notes P5.1/P5.2/P5.3.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **Три новых поля паспорта не измеряются eval'ом** (§10 `research/06-estimate-completeness.md`):
  `GsfxReader` не читает `DocDates`/`Overhd_Info` (долг донора §7, вне Scope P5), `SheetGoldMapper`
  паспорт не строит. Качество их извлечения будет видно только на боевых прогонах, не на F1.
- **Пять колонок `prc_estimates.source_*` остаются без писателя** — донор назначил его item'ам,
  которые закрылись без реализации; в этом плане владельца нет (P6 — про диски файлов). Кандидат
  отдельного fix-item; после боевого прогона P4.7 колонки останутся пустыми.
- **Implementation Rules п.4 P5.1 неполно выполнено** на 4+3 колонках/ключах (Known Deviations
  P5.1) — без изменений.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений; состав снимать
  `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …`; для tinker-прогонов на `agelto_test` нужны явные
  `-e DB_DATABASE=agelto_test -e DB_USERNAME=agelto -e DB_PASSWORD=agelto_dev_local`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff (см. Open risks).
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close`); писатель `verified_at`/`verified_by`
  (долг вне плана, D89 донора); писатель пяти колонок `source_*` (кандидат отдельного fix-item);
  материализация не-`Item` строк (решение владельца «не материализовать», признак дрейфа — строка
  C-11 карты следствий); тип нормы аксессором из `code` (потребитель не назван); типизированный
  носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации (OQ-7);
  партиционирование `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`.
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113. Гейт P5.3 по двум кандидатам (GAP-2, GAP-8) — **закрыт** апрувом владельца
  2026-08-04.
