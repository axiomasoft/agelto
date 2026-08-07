# HANDOFF — 2026-08-07 — after P3.9

**Next:** `/task:plan-close 2026.08.06-CRAWL-HARDEN P3`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — plan-close пин |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P3: все 9 items терминальны (7 🟢, 2 🟠 — P3.4/P3.5 material-отклонения из Known Deviations), проверить критерий завершения фазы (`## Phase Context` «Завершением фазы считается») выполненным реопеном (P3.7+P3.8+P3.9), заполнить `## Phase Handoff` (снять пометку `> УСТАРЕЛ`), docs-sync. |
| Команда | `/task:plan-close 2026.08.06-CRAWL-HARDEN P3` |

```
/task:plan-close 2026.08.06-CRAWL-HARDEN P3
```

**Done:** дизайн закрыт (2/2 прогона + два захода фиксов аудита). Фаза P3 реопенена 2026-08-07 по
вердикту `RED` аудита (D111): доска несёт **P3 7/9** (счёт `🟢/всего`), все 9 items терминальны
(фаза не закрыта — ждёт `plan-close`). Сдано и не переисполняется: сортировка выдачи в фильтре
профиля (P3.1), прогон на очереди Horizon — `CrawlRunJob`, стоп-гейт, checkpoint-requeue, подъём
зависших (P3.2), политика «только актуальные данные» (P3.3), пять операторских действий и отказ
второму запуску (P3.4), два сидовых профиля (P3.5), витрина истории прогонов (P3.6), кооперативный
стоп доведён до обёрток-дневных-циклов (P3.7), окно дат archive-прогона живёт на профиле (P3.8),
**регрессионный гейт консольного отказа `CrawlerRunAlreadyActive` (P3.9)** — два новых кейса в
`tests/Feature/Console/RunFreshCrawlerTest.php` (одиночный отказ по активному `Running`-прогону:
`FAILURE`, текст исключения, `Queue::assertNotPushed`, вторая строка `Run` не создана;
мульти-профильный — активный прогон одного профиля не мешает запуску свободного), анти-самообман
подтверждён (временная порча `RunFreshCrawler::handle()` красит оба кейса, откат чист), прикладной
код не тронут (`git diff HEAD -- app/` пусто). 19 новых тестов на `88ce1f9a` (8/8 в целевом
фильтре, 28 assertions); полный прогон — 18 failed / 1204 passed на `88ce1f9a`, Δ=0 против baseline
`8728723e` (D112).

**Remaining:** 34 items из 54. Фаза P3 терминальна (все 9 items 🟢/🟠) → `/task:plan-close
2026.08.06-CRAWL-HARDEN P3` (следующий шаг, см. Launch-block выше). Дальше — фаза **P4** (носитель
настроек обхода, привязка прокси, авто-дизейбл, общий бюджет, подача пачками, журналы), первый item
— P4.1, `Exec = manual`. Жёсткий порядок фаз прежний: P2 → P3 → P4 → P5 (D98); P8 — после P2–P6
(D4/D83); P9 — последняя.

**Sources of truth:** `plan.md` (Meta v0.16.0, §3 Routing построчно, Status Board, D1–D114, §2
Execution Rules — мера приёмки полного прогона, §7 Contracts) · `roadmap.md` (порядок запуска,
P3.7 → P3.8 → P3.9) · `phases/P3.md` (Phase Context с блоком реопена, items P3.1–P3.9 все
терминальны, `## Phase Status` 9 строк, `## Audit P3 — 2026-08-07` — первоисточник находок,
`## Phase Handoff` всё ещё помечен устаревшим — снимает `plan-close`) · `phases/P4.md`–`P9.md` ·
`research/04-p3-runs-design.md` (дизайн контура прогонов) · `docs/03_Dev/crawling-pipeline.md`,
`docs/04_Operators/how-to/restart-failed-run.md`, `docs/04_Operators/reference/statuses.md`
(актуализированы items P3.2/P3.4/P3.6/P3.8).

**Open risks:**
- **Pending Work P3.2 (2)**: checkpoint-requeue для archive/delta/backlog не заведён —
  у обёрток-дневных-циклов нет чистой точки выхода по бюджету МЕЖДУ днями; P3.7 это СОЗНАТЕЛЬНО
  не закрывал (Scope Excluded), риск остаётся открытым до включения delta/backlog по расписанию
  (P4);
- **Pending Work P3.6**: агрегаты `withCount`/`withSum` по `crawler_run_id` проверены только на
  тестовом масштабе (1–52 строки `prc_fetches`) — на боевом объёме возможен полный скан партиций;
- **`only_fresh_margin_minutes = 60` не калиброван боевым прогоном** (P3.3): занижение —
  пропущенные обновления, завышение — лишние блоки;
- **разовое окно archive-прогона без правки профиля — сознательно не поддерживается** (D113
  `Consequences`): «прогнать март 2024» требует либо консольного `--from`/`--to`, либо временной
  правки формы профиля — известный trade-off, не дефект;
- **предсуществующий красный baseline**: полный прогон — 18 failed на `8728723e` (`Prepare/Archive`,
  `Prepare/Gsfx`, `Prepare/DocumentPrepare`, `TextGridRenderer`, `Screening/Branch`,
  `Ai/PromptApply`, `Ai/PromptComposition`, `Ai/PromptRoot`, `Llm/GeminiProxyRouting`,
  `Architecture/CoreKnowsNoDocumentType`, `Procurement/Contour`); `pint --test` — 12 style issues
  вне путей плана; `migrate:fresh --seed --force` красен (`EisPlatformSeeder` /
  `prc_registries.uuid` not-null). D112 задаёт меру приёмки (Δ), но НЕ узаконивает baseline как
  норму — он остаётся кандидатом в follow-up item вне фаз P2/P3;
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
сознательно только через консоль/форму (D113 `Consequences`); наполнение `ref_okpd2` (Q13); ветка
223-ФЗ (Q10); предел `okpd2Ids` и стабильность id НСИ сверх разового детекта дрейфа P2.5;
агрегаты `prc_fetches` на боевом объёме. Open questions: Q9, Q10, Q13, Q14, Q15, Q16 —
`Exploration`, ни один не блокирует P3.9 или закрытие фазы P3.
