# HANDOFF — 2026-08-07 — after P2.6

**Next:** `/task:plan-close 2026.08.06-CRAWL-HARDEN P2`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — фаза-закрытие: сверка таблиц/Phase Handoff по готовым Completion Notes, не разработка |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть терминальную фазу P2 (6/6 items закрыты) — заполнить Phase Handoff, свериться со Status Board |
| Команда | `/task:plan-close 2026.08.06-CRAWL-HARDEN P2` |

```
/task:plan-close 2026.08.06-CRAWL-HARDEN P2
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
`synced_at`), исчезнувшие узлы НЕ удаляются. Новая секция `okpd2` в `config/crawler.php`.

**P2.6 закрыт 🟠 Done with deviations** (item-коммит `04314525`) — последний item фазы: мёртвая
ветка ОКПД2 листинга снесена целиком. Удалены `mapOkpd2()`/`OKPD2_COLUMN` (`EisCsvRowMapper`),
поля `okpd2Code`/`okpd2Codes` (`ProcurementSnapshotData`), обе точки записи
(`ProcurementStoreRepository`), `$fillable`/`casts()` (`Procurement`), `PostgresTextArrayCast`
(единственный потребитель, `git rm`), фабрика, обе `TextEntry` витрины (`ProcurementInfolist`),
флаг `scopeOkpd2Csv` из `CrawlerProfileSeeder::COLUMNS`. Новая drop-миграция
`2026_08_07_100079_drop_okpd2_columns_from_prc_procurements.php` (номер взят в момент исполнения —
план называл `100071`, D103) с обратимым `down()` (восстанавливает `okpd2_code`, `okpd2_codes`,
GIN-индекс по имени). Тест «ОКПД2 разбирается…» удалён; докблок `eisRealFixtureRows()` СОХРАНИЛ
факт «колонка пуста у всех 500 строк пробы». Доки `ocds-mapping.md`/`01-database.md` приведены
к факту со ссылкой на D16. Grep-гейт контура листинга — 0 совпадений. Статус 🟠: три строки
Validation (`pint --test`, `migrate:fresh --seed --force`, полный прогон теста) красны идентично
на baseline (`44a3df89`/`39c69618`, проверено `git stash` + повторным прогоном) — Δ = 0 везде,
item ничего не ухудшил. `pre_mutation`/`pre_final` — `allow`/`allow`.

**Remaining:** 40 item из 51. **Фаза P2 терминальна** (6/6: 2 🟢 + 4 🟠) — следующий шаг
`/task:plan-close 2026.08.06-CRAWL-HARDEN P2` (Phase Handoff, сверка Status Board), затем
`/task:plan-design 2026.08.06-CRAWL-HARDEN P3` не требуется — P3 уже детализирована до DoR
(items P3.1–P3.6 со всеми полями), первый исполняемый шаг после закрытия фазы — `/task:plan-exec
2026.08.06-CRAWL-HARDEN P3.1`.
Порядок жёсткий в трёх местах: P2 → P3 → P4 → P5 по coupling-поверхности (D98); P8 — после всех
схемных фаз P2–P6 и после терминальной P1.5 (D4/D83); P9 — после P8 (D4).

**Sources of truth:** `plan.md` (Meta, §3 Routing построчно, Status Board, D1–D110,
`## 6. Update Log`, `## 7. Contracts`, развилки № 35–№ 37) · `phases/P2.md` (P2.1–P2.6 закрыты,
все Completion Notes заполнены) · `phases/P3.md` (детализирована до DoR, готова к исполнению) ·
`app/Services/Procurement/Eis/EisCsvRowMapper.php` · `app/Repositories/Procurement/
ProcurementStoreRepository.php` · `database/migrations/2026_08_07_100079_drop_okpd2_columns_from_
prc_procurements.php` · `open-questions.md` (Q9/Q10/Q13/Q15/Q16 — ни один не блокирует P3).

**Open risks:**
- **Строка Validation «полный прогон — зелено» ложна ещё у 39 незакрытых items** (та же природа,
  что у P2.2–P2.6): baseline несёт 18 предсуществующих падений + 12 style issues `pint`, не
  адресованных этим планом. **Фикс-предложение** (не тронуто с прошлого handoff):
  `/task:plan-design 2026.08.06-CRAWL-HARDEN` одним проходом перевести приёмочные строки
  оставшихся items на форму D110 (дельта против именованного baseline).
- **`migrate:fresh --seed --force` красен на baseline** (не только сейчас — воспроизведено на
  `44a3df89` ДО P2.6): `Database\Seeders\Procurement\EisPlatformSeeder` падает на `null value in
  column "uuid" of relation "prc_registries"` при `updateOrCreate()` — `prc_registries.uuid`,
  судя по всему, NOT NULL без дефолта/генератора в сидере. Это НОВЫЙ класс дефекта, не встречавшийся
  в предыдущих handoff'ах P2 (там были загрязнение батч-прогона и падения окружения `Prepare/**`,
  но не падение самого сидирования с нуля) — кандидат в follow-up item, блокирует буквальное
  «migrate:fresh --seed зелёное» на ЛЮБОМ item'е плана, использующем эту команду как Validation,
  до починки.
- **Загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры в батче и зелёные в одиночном прогоне. Дефект существует НА BASELINE, ни
  один item плана его не адресует — кандидат в follow-up item вне P2.
- **~7 предсуществующих падений окружения контейнера `app`** (`storage/app/tmp_extract`, запрет
  `ini_set('memory_limit')`: `Prepare/**`, `Screening/**`, `ContourTest`) — вне диффа плана,
  подтверждено идентичным на baseline и на `04314525`.
- **Прогон `crawler:okpd2-sync` не сообщает исход резолверу** (`reportOutcome()` не вызывается) —
  health пула после пересборки карты не обновляется. Кандидат в follow-up (перенесено из P2.5).
- Перенесены из P1, не пересматривались: вежливостный бюджет P1.5 замерен неполно ·
  `Scope Included` P4.1 п. 7 противоречит D107 (синхронизация до старта P4) · два дефекта боевого
  кода из P1.3 (плоский retry-backoff без `Retry-After` → P4.1; `->lazy()` по мутируемой колонке в
  `DetailRefetchSelector::due()` → P4.5) · `ProcurementStatus::Cancelled` недостижим → P6.1 ·
  разбор карточки не различает «вкладки нет» и «данных ещё нет» → P6.2 · 615-ПП разбирается
  вхолостую → P6.2 · суточная гранулярность даты (P1.1 F4) не учтена при проектировании P3.3 ·
  ёмкость бюджета D45 и пороги P5 НЕ калиброваны (Q15) · выборка «действительно строительная»
  доказывается только активной пробой P5.4 (D20) · третьего `plan-audit design` не будет.
- **Посторонний дифф в дереве, не тронут**: удаление `plans/2026.07.26-EST-TRAIN/**` и untracked
  `plans/archive/2026.07.26-EST-TRAIN/` — архивация чужого плана, начатая вне этой сессии; ещё
  присутствует на дереве после P2.6.

**Workarounds/Deferred/Open questions:** Workarounds — `getAttribute()`-снимок вместо
`@property`-докблоков `Okpd2Node` (P2.1, снимается вместе с починкой модели).
Deferred: как в предыдущем handoff (деплой, ретеншн, `ref_okpd2` (Q13), ветка 223-ФЗ (Q10),
стабильность id НСИ, закрытость перечней P1.4/P1.5) + починка загрязнения батч-прогона +
выравнивание приёмочных строк остальных items + `reportOutcome()` в команде пересборки +
починка `EisPlatformSeeder`/`prc_registries.uuid` (новый, найден на P2.6).
Open questions: Q9, Q10, Q13, Q15 — `Exploration`, Q16 — `Decision pending`, ни один не блокирует
P3.
