# HANDOFF — 2026-08-07 — after P3

**Next:** `/task:plan-audit 2026.08.06-CRAWL-HARDEN P3`

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | xhigh — фаза закрыта с `🟠`-items (P3.4/P3.5), аудит перед следующей фазой |
| Context | NEW SESSION — шаг-не-item |
| Суть | Adversarial-аудит фазы P3 (все 9 items, включая три реопен-item'а P3.7–P3.9): сверить `🟢`/`🟠` с кодом/git, проверить, что критерий завершения фазы («оба сидовых профиля работают из админки») теперь выполнен, no-op/scope-drift, handoff. |
| Команда | `/task:plan-audit 2026.08.06-CRAWL-HARDEN P3` |

```
/task:plan-audit 2026.08.06-CRAWL-HARDEN P3
```

**Done:** Фаза P3 закрыта повторно 2026-08-07 (реопен D111 закрыт). Все 9 items терминальны:
P3.1/P3.2/P3.3/P3.6/P3.7/P3.8/P3.9 — 🟢, P3.4/P3.5 — 🟠 (material-отклонения: дифф вне `Files`
у обоих, у P3.4 ещё и невыполненный Scope Included п. 10, закрытый отдельным item'ом P3.9).
Сдано: сортировка выдачи в фильтре профиля (P3.1); прогон на очереди Horizon — `CrawlRunJob`,
кооперативный стоп-гейт, checkpoint-requeue, подъём зависших (P3.2); политика «только актуальные
данные» (P3.3); пять операторских действий и отказ второму запуску (P3.4); два сидовых профиля
(P3.5); витрина истории прогонов (P3.6); кооперативный стоп доведён до
обёрток-дневных-циклов `ArchiveRunProcessor`/`DeltaScanProcessor`/`BacklogRescanProcessor` —
`complete()` теперь условный `UPDATE ... WHERE status='running'` (P3.7); окно дат archive-прогона
живёт на профиле (`HasArchiveWindow`), сидовый архивный профиль проходит из админки все пять
действий (P3.8); регрессионный гейт консольного отказа `CrawlerRunAlreadyActive` — два новых
кейса, анти-самообман подтверждён (P3.9). Критерий завершения фазы («оба сидовых профиля
работают из админки — запуск/остановка/продолжение/отмена/запуск с начала») выполнен для ОБОИХ
профилей — находки F1/F2 предыдущего аудита закрыты P3.8/P3.7 соответственно. `Phase Handoff`
в `phases/P3.md` перезаписан целиком (маркер `> УСТАРЕЛ` снят), Status Board `plan.md` — `P3 7/9,
🟠 Done with deviations`.

**Remaining:** 34 items из 54 (P4–P9 не начаты). Фаза P4 (носитель настроек обхода, привязка
прокси, авто-дизейбл, общий бюджет, подача пачками, журналы) — следующая после аудита P3, первый
item P4.1, `Exec = manual`. Жёсткий порядок фаз: P2 → P3 → P4 → P5 (D98); P8 — после P2–P6
(D4/D83); P9 — последняя.

**Sources of truth:** `plan.md` (Meta v0.16.0, §3 Routing построчно, Status Board `P3 7/9`,
D1–D114, §2 Execution Rules — мера приёмки полного прогона, §7 Contracts) · `roadmap.md` (порядок
запуска) · `phases/P3.md` (Phase Context с блоком реопена, items P3.1–P3.9 все терминальны,
`## Phase Status` 9 строк, `## Phase Handoff` перезаписан — маркер `УСТАРЕЛ` снят, `## Audit P3 —
2026-08-07` — первоисточник находок предыдущего аудита) · `phases/P4.md`–`P9.md` ·
`research/04-p3-runs-design.md` (дизайн контура прогонов) · `docs/03_Dev/crawling-pipeline.md`,
`docs/04_Operators/how-to/restart-failed-run.md`, `docs/04_Operators/reference/statuses.md`
(актуализированы items P3.2/P3.4/P3.6/P3.8).

**Open risks:**
- **Pending Work (P3.2/P3.7)**: checkpoint-requeue для archive/delta/backlog не заведён —
  у обёрток-дневных-циклов нет чистой точки выхода по бюджету МЕЖДУ днями; риск остаётся открытым
  до включения delta/backlog по расписанию (P4);
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
`Exploration`, ни один не блокирует закрытие фазы P3.
