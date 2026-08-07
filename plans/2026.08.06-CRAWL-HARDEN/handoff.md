# HANDOFF — 2026-08-07 — after P3.6

**Next:** «ЗАПУСК ВРУЧНУЮ: sonnet/high» — P4.1 (§3 Routing: `implementation/high`, `Exec = manual`;
носитель настроек лежит на coupling-поверхности `config/crawler.php` и формы профиля — неверное
слияние трёх уровней молча вернёт глобальные значения и обесценит калибровку боевым прогоном;
phase-first: P3 не терминальна формально (Phase Handoff — скелет), но все 6 её items закрыты —
следующий шаг по нумерации фазы P4 не заблокирован; закрыть P3 формально —
`/task:plan-close 2026.08.06-CRAWL-HARDEN P3` перед или после P4.1, порядок не критичен)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписано Routing (`manual`), команда `plan-exec` пинит sonnet/medium и обязана отклонить этот item по гейту п. 2 |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать P4.1: трёхуровневый носитель настроек обхода (площадка → профиль → config) и единственный резолвер |
| Команда | `—` |

```
Прочитай plans/2026.08.06-CRAWL-HARDEN/plan.md (Context, Execution Rules, §3 Routing, Status Board),
plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P4.md
(Phase Context + P4.1 целиком), затем Required Reads item'а P4.1. Исполни P4.1 строго по его
Scope Included/Excluded, Implementation Rules, Code Guidance. Закрой item по полному протоколу
plan-protocol §8 (два коммита: item-коммит по Files + bookkeeping плана), запусти Validation
целиком, обнови phases/P4.md (Status/Completion Notes/Phase Status), plan.md (Status Board/Update
Log/Meta), handoff.md, journal.jsonl.
```

**Done:** дизайн закрыт (2/2 + два захода фиксов аудита, v0.15.0). **Фаза P3 фактически
завершена: все 6 items терминальны** (P3.3 🟢, P3.4 🟢, P3.6 🟢, P3.1 🟠, P3.2 🟠, P3.5 🟠) —
формальное закрытие фазы (`Phase Handoff` в `phases/P3.md` всё ещё скелет, docs-sync-проверка)
остаётся за `/task:plan-close 2026.08.06-CRAWL-HARDEN P3`, эта команда его не запускала (не входит
в scope `plan-exec` одного item'а). P3.6 (последний item фазы, эта сессия): связь
`Run::fetches(): HasMany` + `Run::stopRequestedBy(): BelongsTo`; `RunResource::getEloquentQuery()` —
`withCount('fetches')`/`withSum(..., 'rows_new')`/`withSum(..., 'rows_updated')` вместо построчной
выборки; `RunsTable` — badge-статус с цветовой картой, профиль, длительность (канон
`RunsRelationManager::diffForHumans`), агрегаты блоков, причина остановки и `cap_hit` из `stats`,
фильтры профиль/статус/окно старта; `RunInfolist` — секция «Где остановились» (декодированный
курсор) и «Итоги» (явный перечень 8 ключей `stats` с подписями, отсутствующий — «—»), секция
«Остановка оператором»; `docs/04_Operators/reference/statuses.md` пополнен разделами `RunStatus`/
`stop_reason`/`RunStopKind`. Новый тест `tests/Feature/Filament/CrawlerRunHistoryTest.php` — 6
тестов, включая N+1-гейт (канон `EstimateResourceTest`). Validation: целевой фильтр
`CrawlerRunHistory` — 6 passed/35 assertions (на `d6329cdb`); `pint --test`/`phpstan analyse` —
зелено без роста baseline; полный прогон — 18 failed/1183 passed (Δ=0 против baseline `8728723e`,
+6 моих зелёных, тот же состав failed-семейств); `plan-lint --baseline HEAD` — 0/0 новых. Статус
🟢 — item-коммит `d6329cdb` несёт дифф РОВНО по `Files` item'а (сверено `git status` до/после
`git add`), material-отклонений нет.

**Remaining:** 39 items из 51 (P3.6 закрыт этой сессией). Порядок жёсткий: P2 → P3 → P4 → P5 по
coupling-поверхности (D98); P8 — после P2–P6 (D4/D83); P9 — после P8. P3 фактически завершена (см.
Done выше). Следующая по порядку — фаза **P4** (носитель настроек обхода, привязка прокси, авто-
дизейбл, общий бюджет, подача пачками, журналы), items связаны последовательно по данным
(`research/05-p4-reliability-design.md` §6), первый — **P4.1**, `Exec = manual`.

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110, развилки
№ 1–№ 34, §7 Contracts) · `roadmap.md` (порядок запуска) · `phases/P3.md` (Phase Context, Completion
Notes P3.1–P3.6, Phase Status все 6 терминальны, `Phase Handoff` — скелет, не заполнен) ·
`phases/P4.md`–`P9.md` · `research/05-p4-reliability-design.md` (дизайн P4) ·
`docs/03_Dev/crawling-pipeline.md` §«Вход: команды-диспетчеры, обход — на очереди» (актуализирован
P3.2; P3.6 доки не трогал кроме `docs/04_Operators/reference/statuses.md`) ·
`docs/04_Operators/reference/statuses.md` (актуализирован P3.6 — разделы `RunStatus`/
`stop_reason`/`RunStopKind`) · `docs/03_Dev/database.md`, `docs/03_Dev/ocds-mapping.md`
(актуализированы P2.6, P3.1–P3.6 их не трогают).

**Open risks:**
- **Фаза P3 не закрыта формально** — `Phase Handoff` в `phases/P3.md` остаётся скелетом, `plan.md`
  Status Board несёт P3 как «🟡 In progress» при 6/6 закрытых items: следующая сессия обязана либо
  прогнать `/task:plan-close 2026.08.06-CRAWL-HARDEN P3` перед P4.1, либо явно принять, что
  phase-first гейт P4.1 это не блокирует (все items P3 терминальны, формальность закрытия фазы —
  бухгалтерия, не открытая работа);
- **Pending Work P3.6** (новое): агрегаты `withCount`/`withSum` по `crawler_run_id` проверены
  только на тестовом масштабе (1–52 строки `prc_fetches`) — если боевой объём покажет полный скан
  партиций, агрегат ограничивается окном `started_at`…`finished_at` (if-then Implementation Rules
  item'а), это НЕ сделано превентивно;
- **Pending Work P3.2** (унаследовано): `ArchiveWindowScanner::scan()` стоп-гейт останавливает дренаж
  ТЕКУЩЕГО date-окна, но обёртки-дневные-циклы (`ArchiveRunProcessor`/`DeltaScanProcessor`/
  `BacklogRescanProcessor`, вне `Files` P3.2) статус прогона после `scan()` не перечитывают —
  `Pause`/`Cancel` не останавливает archive/delta/backlog-прогон целиком. Для fresh-режима
  (единственного, что `CrawlRunJob` реально диспетчит из P3.2/P3.3/P3.4) не проявляется;
- **Pending Work P3.4** (унаследовано): admin-кнопки `CrawlersTable` не передают `from`/`to` — старт
  Archive-профиля из админки упадёт `LogicException` внутри `CrawlRunJob::handle()`; консольный
  путь (`--from/--to`) не затронут;
- **only_fresh_margin_minutes = 60 не калиброван боевым прогоном** (P3.3) — прода нет в скоупе
  плана, занижение = пропущенные обновления, завышение = лишние блоки;
- **загрязнение батч-прогона тестового контура**: `EisSeedersTest`/`StatusMappingSeederTest`
  видят чужие реестры при батч-прогоне, зелёные изолированно (D110) — follow-up вне фазы P2/P3;
- `migrate:fresh --seed --force` красен на baseline (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null) — вне Scope P3, не чинилось;
- `pint --test` — 12 предсуществующих style issues на репозитории вне путей P3 — вне Scope;
- **полный прогон `artisan test --no-tia --compact` красен на baseline** (18 failed: `Prepare/
  Archive`, `Prepare/Gsfx`, `TextGridRenderer`, `Ai/PromptApply`, `Ai/PromptComposition`,
  `Ai/PromptRoot`, `Llm/GeminiProxyRouting`, `Architecture/CoreKnowsNoDocumentType`,
  `Procurement/Contour` — распаковка 7z/`tmp_extract`/`memory_limit` и несвязанные с P3 семейства)
  — вне Scope P2/P3, приёмка следующих items по-прежнему идёт дельтой против baseline (`8728723e`),
  не «зелено»;
- остаток F5 (`research`, унаследовано из handoff P1): три строки D-лога (`D26`, `D45`, `D62`)
  несут кросс-плановый id без квалификатора донора;
- **посторонний дифф в дереве, НЕ созданный этой сессией**: удаления
  `plans/2026.07.26-EST-TRAIN/**` + новый каталог `plans/archive/2026.07.26-EST-TRAIN/**` —
  унаследовано из P3.1 handoff, эта сессия их также не коснулась (не коммитила, не стэшила).

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: наполнение `ref_okpd2`
(Q13, вне скоупа P2/P3); ветка 223-ФЗ (Q10); предел `okpd2Ids` и долгосрочная стабильность id НСИ
сверх разового детекта дрейфа P2.5; обёртки-дневные-циклы Archive/Delta/Backlog не перечитывают
статус прогона после `scan()` (см. Open risks выше); admin-старт Archive-профиля без `from`/`to`
(Open risks выше); агрегаты `prc_fetches` не проверены на боевом объёме (см. Open risks выше).
Open questions: Q9, Q10, Q13, Q14 (`prc_daily_stats`, вне скоупа P3), Q15, Q16 — `Exploration`, ни
один не блокирует P4.
