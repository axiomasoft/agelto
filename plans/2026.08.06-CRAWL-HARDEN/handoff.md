# HANDOFF — 2026-08-07 — after P2.4

**Next:** `/task:plan-exec 2026.08.06-CRAWL-HARDEN P2.5`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — Routing §3 предписывает implementation/medium |
| Context | continue (/clear) — ручной item |
| Суть | Команда пересборки карты ОКПД2 с ЕИС + детект дрейфа id (`crawler:okpd2-sync`) |
| Команда | `/task:plan-exec 2026.08.06-CRAWL-HARDEN P2.5` |

```
/task:plan-exec 2026.08.06-CRAWL-HARDEN P2.5
```

**Done:** P2.1 закрыт 🟠 Done with deviations (item-коммит `2e36ad56`) — таблица `prc_okpd2_nodes`,
модель `Okpd2Node`, фабрика, read-репозиторий `Okpd2NodeReadRepository`, датафайл
(34 узла), идемпотентный `Okpd2NodeSeeder`.

**P2.2 закрыт 🟢 Done** (item-коммит `f172a43d`) — гейт скоупа ОКПД2 структурно обязателен:
поле `okpd2Codes` в `EisSearchFilter` (`required|array|min:1` + форма кода), дефолты фабрик
`CrawlerFactory`/`RegistryFactory` (D108), минимальный обязательный мультиселект
`filter.okpd2Codes` в `CrawlerForm` (D109). Приёмочная дельта — 15 failed / 594 passed / 1
skipped на `f172a43d` против baseline 15 failed / 588 passed / 1 skipped на `618a2ce6`, Δ = 0.

**P2.3 закрыт 🟠 Done with deviations** (item-коммит `ac76e424`) — `EisCsvUrlBuilder` получил
конструкторную зависимость от `Okpd2NodeReadRepository`: резолвит `okpd2Codes` профиля в
`okpd2Ids` (детерминированная сортировка по коду) + безусловный `okpd2IdsWithNested=on`
(единственный рабочий режим фильтра, `findings/P1-okpd2-probe.md`); нерезолвящийся код —
`RuntimeException` с именем кода и путём починки (P2.5). Новая DI-зависимость требует
резолвящейся карты для ЛЮБОГО профиля с `okpd2Codes` — обнаружилось полным прогоном, что три
предсуществующих фикстурных файла её не несли: `CrawlerWithRegistry` (добавлен параметр
`platformId`), `CsvSourceMethodTest` (11 сайтов → хелпер `registryWithOkpd2Scope()`),
`EisSeedersTest` (один тест довесил `$this->seed(Okpd2NodeSeeder::class)`) — без этой правки
K было бы 12, с ней K = 0 (полный прогон 18 failed / 1127 passed / 1 skipped на `ac76e424`
против 18 failed / 1123 passed / 1 skipped на `bcc681f8`, список падающих классов идентичен).
Дифф вне `Files` item'а → статус 🟠 по status-rule, независимо от корректности диффа.

**P2.4 закрыт 🟢 Done** (item-коммит `4a9238d7`) — `Select::make('filter.okpd2Codes')` доведён
внутри `okpd2CodeField()`/`okpd2CodeOptions()` `CrawlerForm.php`: `->searchable()` +
группировка опций по `parent_code` (узлы без родителя — группа «Разделы»); карта площадки пуста
(сид не прогнан) → вместо пустого обязательного селекта — `Placeholder` с причиной и путём
починки; `helperText` `CheckboxList` колонок CSV дополнен наблюдением о пустых классификаторных
колонках. +2 теста Livewire сверх заведённых P2.2 (группировка/поиск через
`assertFormFieldExists`+`Select::getOptions()`, превью URL через `assertFormComponentExists`+
`Placeholder::getContent()`). Внутри `Files` этого item'а доведён и заведённый P2.2 тест «форма
отклоняет профиль fz44 без выбранного узла ОКПД2» — он сидировал регистр без `Okpd2Node`, что до
P2.4 совпадало со сценарием «узел не выбран» (карта пустая, но `Select` рендерился), а после
P2.4 стало сценарием «карта пуста» (рендерится `Placeholder`, поля `filter.okpd2Codes` в схеме
нет); добавлен `Okpd2Node::factory()->create(...)`, чтобы тест снова проверял исходный сценарий.
Приёмочная дельта: 18 failed / 1129 passed / 1 skipped на `4a9238d7` против 18 failed / 1127
passed / 1 skipped на baseline `c4fbfbc4` (собственное дерево до item'а, парный прогон снят
`git stash`) — Δ failed = 0. Дифф — целиком внутри `Files` item'а. Детали —
`phases/P2.md` P2.4 Completion Notes.

**Remaining:** 42 items из 51. Внутри P2: P2.5–P2.6.
Порядок жёсткий в трёх местах: P2 → P3 → P4 → P5 по coupling-поверхности (D98); P8 — после всех
схемных фаз P2–P6 и после терминальной P1.5 (D4/D83); P9 — после P8 (D4).

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
`## 6. Update Log`, `## 7. Contracts`, развилки № 35–№ 37) · `phases/P2.md` (P2.1–P2.4 закрыты,
P2.5–P2.6 — ТЗ готово) · `research/03-p2-okpd2-design.md` §3.3 (команда пересборки, развилка № 9) ·
`app/Repositories/Procurement/Platform/Okpd2NodeReadRepository.php` · `app/Services/Procurement/
Eis/EisCsvUrlBuilder.php` (резолв P2.3, ЕИС-специфика) · `open-questions.md` (Q9/Q10/Q13/Q15/Q16
— ни один не блокирует P2).

**Open risks:**
- **Строка Validation «полный прогон — зелено» ложна ещё у 41 незакрытых item'а** — D110 поправил
  её у P2.2 и P2.4 (по имени), P2.3 подтвердил K=0 отдельным явным прогоном. Долг назван в самой
  D-записи; правит его тот, кто эти items исполняет, либо следующий `plan-design`.
- **Загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры в батче и зелёные в одиночном прогоне. Дефект существует НА BASELINE
  (подтверждено ещё раз прогонами P2.2/P2.3/P2.4), ни один item плана его не адресует — кандидат
  в follow-up item вне P2.
- **~7 предсуществующих падений окружения контейнера `app`** (`storage/app/tmp_extract`, запрет
  `ini_set('memory_limit')`: `Prepare/**`, `Screening/**`, `ContourTest`) — вне диффа плана,
  подтверждено идентичным на baseline и на `4a9238d7`.
- Перенесены из P1, не пересматривались: вежливостный бюджет P1.5 замерен неполно (ужесточить
  `eis-recon` перед P2.5/P5.4) · `Scope Included` P4.1 п. 7 противоречит D107 (синхронизация до
  старта P4) · два дефекта боевого кода из P1.3 (плоский retry-backoff без `Retry-After` → P4.1;
  `->lazy()` по мутируемой колонке в `DetailRefetchSelector::due()` → P4.5) ·
  `ProcurementStatus::Cancelled` недостижим → P6.1 · разбор карточки не различает «вкладки нет» и
  «данных ещё нет» → P6.2 · 615-ПП разбирается вхолостую → P6.2 · суточная гранулярность даты (P1.1
  F4) не учтена при проектировании P3.3 · ёмкость бюджета D45 и пороги P5 НЕ калиброваны (Q15) ·
  выборка «действительно строительная» доказывается только активной пробой P5.4 (D20) ·
  третьего `plan-audit design` не будет.
- **Посторонний дифф в дереве, не тронут**: удаление `plans/2026.07.26-EST-TRAIN/**` и untracked
  `plans/archive/2026.07.26-EST-TRAIN/` — архивация чужого плана, начатая вне этой сессии.

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: как в предыдущем handoff
(деплой, ретеншн, `ref_okpd2` (Q13), ветка 223-ФЗ (Q10), стабильность id НСИ, закрытость перечней
P1.4/P1.5) + починка загрязнения батч-прогона + выравнивание строки «полный прогон — зелено» у
остальных items. Open questions: Q9, Q10, Q13, Q15 — `Exploration`, Q16 — `Decision pending`, ни
один не блокирует P2.
