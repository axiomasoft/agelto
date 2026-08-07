# HANDOFF — 2026-08-07 — after P3.7

**Next:** manual: implementation/high — P3.8 (§3 Routing: `implementation/high`, `Exec = manual`;
новый контракт фильтра и смена смысла уже существующих полей DTO (D113) на coupling-поверхности с
P2/`EisSearchFilter` и P4/сидер профилей — ошибка молча запускает многодневный обход НЕ ТОГО окна)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high — предписано Routing (`manual`); `plan-exec` пинит implementation/medium и обязан отклонить этот item по гейту undershoot |
| Capabilities | — |
| Context | same-session — item |
| Суть | Исполнить P3.8: контракт `HasArchiveWindow` + `EisSearchFilter implements` + резолв окна в `StartCrawlerRunAction` (start/restart/resume) + отказ `ArchiveWindowMissing` + сид архивного профиля с окном, чтобы сидовый архивный профиль проходил из админки все пять действий |
| Команда | `—` |

```
Прочитай plans/2026.08.06-CRAWL-HARDEN/plan.md (Context, Execution Rules, §3 Routing, Status Board,
D111–D114), plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P3.md
(Phase Context — включая блок «Реопен 2026-08-07 (D111)» — и P3.8 целиком), затем Required Reads
item'а P3.8. Исполни P3.8 строго по его Scope Included/Excluded, Implementation Rules, Code
Guidance. Закрой item по полному протоколу plan-protocol §8 (два коммита: item-коммит по путям
Files + bookkeeping плана), прогони Validation целиком, обнови phases/P3.md (Status/Completion
Notes/Phase Status), plan.md (Status Board/Update Log/Meta), handoff.md, journal.jsonl.
```

**Done:** дизайн закрыт (2/2 прогона + два захода фиксов аудита). Фаза P3 реопенена 2026-08-07 по
вердикту `RED` аудита (D111): доска была **P3 4/9**, теперь **5/9** после закрытия P3.7. Сдано и не
переисполняется: сортировка выдачи в фильтре профиля (P3.1), прогон на очереди Horizon —
`CrawlRunJob`, стоп-гейт, checkpoint-requeue, подъём зависших (P3.2), политика «только актуальные
данные» (P3.3), пять операторских действий и отказ второму запуску (P3.4), два сидовых профиля
(P3.5), витрина истории прогонов (P3.6), **кооперативный стоп доведён до обёрток-дневных-циклов
(P3.7)** — `CrawlerRunManager::complete()` переводит прогон в `Completed` только из `Running`,
`ArchiveRunProcessor`/`DeltaScanProcessor`/`BacklogRescanProcessor::drainWindow()` перечитывают
статус в начале дневной итерации и выходят, если он уже не `Running`; прерванный backlog-рескан не
помечает fresh-прогон rescanned. 129 целевых теста зелены на `48eaf6ef` (122 из P3.1–P3.6 + 7
новых P3.7); полный прогон — 18 failed / 1190 passed на `48eaf6ef`, Δ=0 против baseline `8728723e`
(D112).

**Remaining:** 36 items из 54. Ближайшие два — реопен фазы P3: **P3.8** (админ-старт архивного
профиля: окно дат на профиле через контракт `HasArchiveWindow`, внятный отказ вместо мёртвого
job'а — теперь РЕАЛЬНО останавливается кооперативным стопом P3.7), **P3.9** (регрессионный гейт
консольного отказа `CrawlerRunAlreadyActive`). Порядок **P3.7 → P3.8 выполнен**: P3.8 своей
приёмкой («сидовый архивный профиль проходит из админки все пять действий», включая
«Остановить») теперь опирается на реально работающий кооперативный стоп. P3.9 по данным
независим, но по `Model/effort`+`Exec` в батч с ними не сливается. Дальше — фаза **P4** (носитель
настроек обхода, привязка прокси, авто-дизейбл, общий бюджет, подача пачками, журналы), первый
item — P4.1, `Exec = manual`. Жёсткий порядок фаз прежний: P2 → P3 → P4 → P5 (D98); P8 — после
P2–P6 (D4/D83); P9 — последняя.

**Sources of truth:** `plan.md` (Meta v0.16.0, §3 Routing построчно — включая строки P3.7–P3.9,
Status Board, D1–D114, §2 Execution Rules — мера приёмки полного прогона, §7 Contracts) ·
`roadmap.md` (порядок запуска, обновлён под реопен) · `phases/P3.md` (Phase Context с блоком
реопена, items P3.1–P3.9, `## Phase Status` 9 строк, `## Audit P3 — 2026-08-07` — первоисточник
находок, `## Phase Handoff` помечен устаревшим) · `phases/P4.md`–`P9.md` ·
`research/04-p3-runs-design.md` (дизайн контура прогонов) · `research/05-p4-reliability-design.md`
(дизайн P4) · `docs/03_Dev/crawling-pipeline.md`, `docs/04_Operators/how-to/restart-failed-run.md`,
`docs/04_Operators/reference/statuses.md` (актуализированы items P3.2/P3.4/P3.6).

**Open risks:**
- **P3.8 меняет смысл двух полей DTO** (`EisSearchFilter::$publishedFrom/$publishedTo` становятся
  окном прогона для archive-профиля, D113) — поверхность общая с P2 (`EisSearchFilter`) и P4
  (сидер профилей, `config/crawler.php`); докблоки и `helperText` обязаны быть переписаны честно,
  иначе следующий читатель получит ложное правило;
- **Pending Work P3.2 (2)**: checkpoint-requeue для archive/delta/backlog не заведён —
  у обёрток-дневных-циклов нет чистой точки выхода по бюджету МЕЖДУ днями; P3.7 это СОЗНАТЕЛЬНО
  не закрывал (Scope Excluded), риск остаётся открытым до включения delta/backlog по расписанию
  (P4);
- **Pending Work P3.6**: агрегаты `withCount`/`withSum` по `crawler_run_id` проверены только на
  тестовом масштабе (1–52 строки `prc_fetches`) — на боевом объёме возможен полный скан партиций;
- **`only_fresh_margin_minutes = 60` не калиброван боевым прогоном** (P3.3): занижение —
  пропущенные обновления, завышение — лишние блоки;
- **предсуществующий красный baseline**: полный прогон — 18 failed на `8728723e` (`Prepare/Archive`,
  `Prepare/Gsfx`, `TextGridRenderer`, `Ai/PromptApply`, `Ai/PromptComposition`, `Ai/PromptRoot`,
  `Llm/GeminiProxyRouting`, `Architecture/CoreKnowsNoDocumentType`, `Procurement/Contour`);
  `pint --test` — 12 style issues вне путей плана; `migrate:fresh --seed --force` красен
  (`EisPlatformSeeder` / `prc_registries.uuid` not-null). D112 задаёт меру приёмки (Δ), но НЕ
  узаконивает baseline как норму — он остаётся кандидатом в follow-up item вне фаз P2/P3;
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest` видят
  чужие реестры при батч-прогоне, зелёные изолированно (D110) — тот же follow-up;
- находка **F7** аудита (карта «коммит → item» не выражена ни в Update Log, ни здесь) не
  адресована этим реопеном — дефект системный для плана, не регрессия P3; хеши item-коммитов
  живут в Completion Notes;
- остаток F5 аудита P1: три строки D-лога (`D26`, `D45`, `D62`) несут кросс-плановый id без
  квалификатора донора;
- **посторонний дифф в рабочем дереве, НЕ созданный этой сессией**: удаления
  `plans/2026.07.26-EST-TRAIN/**` + новый каталог `plans/archive/2026.07.26-EST-TRAIN/**` —
  унаследовано из предыдущих сессий; эта сессия их не коснулась (не коммитила, не стэшила).

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: checkpoint-requeue для
archive/delta/backlog (Open risks выше); разовое окно archive-прогона без правки профиля —
сознательно только через консоль (D113 `Consequences`); наполнение `ref_okpd2` (Q13); ветка
223-ФЗ (Q10); предел `okpd2Ids` и стабильность id НСИ сверх разового детекта дрейфа P2.5;
агрегаты `prc_fetches` на боевом объёме. Open questions: Q9, Q10, Q13, Q14, Q15, Q16 —
`Exploration`, ни один не блокирует P3.8.
