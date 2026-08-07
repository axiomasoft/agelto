# HANDOFF — 2026-08-07 — after P2.5

**Next:** `/task:plan-exec 2026.08.06-CRAWL-HARDEN P2.6`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — Routing §3 предписывает implementation/medium |
| Context | continue (/clear) — ручной item |
| Суть | Снос мёртвой ветки ОКПД2 листинга: код, схема, витрина, тесты, доки (последний item P2) |
| Команда | `/task:plan-exec 2026.08.06-CRAWL-HARDEN P2.6` |

```
/task:plan-exec 2026.08.06-CRAWL-HARDEN P2.6
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
`okpd2Ids` (детерминированная сортировка по коду) + безусловный `okpd2IdsWithNested=on`;
нерезолвящийся код — `RuntimeException` с именем кода и путём починки (P2.5). Дифф вне `Files`
item'а (починка трёх фикстурных файлов) → статус 🟠.

**P2.4 закрыт 🟢 Done** (item-коммит `4a9238d7`) — `Select::make('filter.okpd2Codes')` доведён:
`->searchable()`, группировка опций по `parent_code`, `Placeholder` при пустой карте, helperText
о пустых классификаторных колонках; +2 теста Livewire. Приёмочная дельта: 18 failed / 1129 passed
/ 1 skipped на `4a9238d7` против 18 failed / 1127 passed / 1 skipped на `c4fbfbc4`, Δ failed = 0.

**P2.5 закрыт 🟠 Done with deviations** (item-коммит `39c69618`) — заведена команда
`crawler:okpd2-sync {--root=} {--dry-run} {--max-requests=}`: `Okpd2NodeSynchronizer` обходит
`children.html` вширь через ТЕ ЖЕ три сейма канала, что боевой `CsvSourceMethod` (одна лиза
`ProxyResolver::resolve(CrawlListing)` на прогон, `PoliteThrottle::wait()` перед каждым запросом,
`verify` из `SourceTlsTrust`), сверяет снятое с `prc_okpd2_nodes` по паре (код, `external_id`) и
отчитывается тремя классами расхождений; запись — `Okpd2NodeStoreRepository` (`updateOrCreate` +
`synced_at`), исчезнувшие узлы НЕ удаляются. Дрейф у кода АКТИВНОГО профиля даёт exit 1, у
неактивного — exit 0. Потолок запросов жёсткий: остаток очереди попадает в «не обойдено» отчёта, и
именно поэтому «исчезнувшим» считается только узел, чьего родителя раскрывали В ЭТОМ прогоне.
Новая секция `okpd2` в `config/crawler.php` (`root_external_id` = `8873866` — раздел `F`,
`max_requests` = 20 при 14 запросах до уровня групп). Тестов 6 при требовании ТЗ ≥4, фикстура
ответа СИНТЕТИЧЕСКАЯ (сырое тело разведка не сохраняла) — помечено в фикстуре и в докблоке теста.
Полный прогон: 18 failed / 1135 passed / 1 skipped против baseline 18 failed / 1129 passed / 1
skipped на `4a9238d7` — Δ failed = 0, +6 passed. Статус 🟠 — по букве status-rule: три строки
Validation этого item'а требуют «зелено» от команд, красных ИДЕНТИЧНО на baseline (см. Open
risks). Дифф — целиком внутри `Files`.

**Remaining:** 41 item из 51. Внутри P2 — только P2.6.
Порядок жёсткий в трёх местах: P2 → P3 → P4 → P5 по coupling-поверхности (D98); P8 — после всех
схемных фаз P2–P6 и после терминальной P1.5 (D4/D83); P9 — после P8 (D4).

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
`## 6. Update Log`, `## 7. Contracts`, развилки № 35–№ 37) · `phases/P2.md` (P2.1–P2.5 закрыты,
P2.6 — ТЗ готово) · `findings/P1-okpd2-map.md` §6 (пересборка = детект дрейфа) ·
`app/Services/Procurement/Eis/Okpd2NodeSynchronizer.php` · `app/Console/Commands/Procurement/
SyncOkpd2Nodes.php` · `config/crawler.php` секция `okpd2` · `open-questions.md` (Q9/Q10/Q13/Q15/Q16
— ни один не блокирует P2).

**Open risks:**
- **Строка Validation «полный прогон — зелено» ложна ещё у 40 незакрытых items**, и к ней
  добавились две того же класса, вскрытые P2.5: `pint --test` «зелено» (фактически 12
  предсуществующих style issues на 950 файлах) и `grep -rn "zakupki.gov.ru" tests/ | grep -v
  Fixtures` «пусто» (фактически 56 совпадений — URL-литералы в `Http::fake()`-тестах, намерение
  гейта при этом выполнено). Из-за них P2.5 закрыт 🟠, хотя item ничего не ухудшил.
  **Фикс-предложение:** одним проходом `plan-design` перевести приёмочные строки оставшихся items
  на форму D110 (дельта против именованного baseline) и заменить грубый grep по `zakupki.gov.ru`
  на проверку «`Http::fake()` есть в файле теста». Команда:
  `/task:plan-design 2026.08.06-CRAWL-HARDEN` (правка §Validation по items, без нового прогона).
- **Загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры в батче и зелёные в одиночном прогоне. Дефект существует НА BASELINE, ни
  один item плана его не адресует — кандидат в follow-up item вне P2.
- **~7 предсуществующих падений окружения контейнера `app`** (`storage/app/tmp_extract`, запрет
  `ini_set('memory_limit')`: `Prepare/**`, `Screening/**`, `ContourTest`) — вне диффа плана,
  подтверждено идентичным на baseline и на `39c69618`.
- **`Okpd2Node` без `@property`-докблоков** (долг P2.1): прямое чтение колонок даёт ошибку
  phpstan, P2.5 обошёл это `getAttribute()`-снимком внутри своих `Files`. Починка — 6 строк
  докблока, адресат P2.6 либо follow-up.
- **Прогон `crawler:okpd2-sync` не сообщает исход резолверу** (`reportOutcome()` не вызывается) —
  health пула после пересборки карты не обновляется. В Scope Included P2.5 сеймов названо три,
  отчёт исхода в них не входит; кандидат в follow-up.
- Перенесены из P1, не пересматривались: вежливостный бюджет P1.5 замерен неполно ·
  `Scope Included` P4.1 п. 7 противоречит D107 (синхронизация до старта P4) · два дефекта боевого
  кода из P1.3 (плоский retry-backoff без `Retry-After` → P4.1; `->lazy()` по мутируемой колонке в
  `DetailRefetchSelector::due()` → P4.5) · `ProcurementStatus::Cancelled` недостижим → P6.1 ·
  разбор карточки не различает «вкладки нет» и «данных ещё нет» → P6.2 · 615-ПП разбирается
  вхолостую → P6.2 · суточная гранулярность даты (P1.1 F4) не учтена при проектировании P3.3 ·
  ёмкость бюджета D45 и пороги P5 НЕ калиброваны (Q15) · выборка «действительно строительная»
  доказывается только активной пробой P5.4 (D20) · третьего `plan-audit design` не будет.
- **Посторонний дифф в дереве, не тронут**: удаление `plans/2026.07.26-EST-TRAIN/**` и untracked
  `plans/archive/2026.07.26-EST-TRAIN/` — архивация чужого плана, начатая вне этой сессии.

**Workarounds/Deferred/Open questions:** Workarounds — `getAttribute()`-снимок вместо
`@property`-докблоков `Okpd2Node` (живёт в коде P2.5, снимается вместе с починкой модели).
Deferred: как в предыдущем handoff (деплой, ретеншн, `ref_okpd2` (Q13), ветка 223-ФЗ (Q10),
стабильность id НСИ, закрытость перечней P1.4/P1.5) + починка загрязнения батч-прогона +
выравнивание приёмочных строк остальных items + `reportOutcome()` в команде пересборки.
Open questions: Q9, Q10, Q13, Q15 — `Exploration`, Q16 — `Decision pending`, ни один не блокирует
P2.6.
