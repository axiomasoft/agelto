# HANDOFF — 2026-08-07 — after P2.2

**Next:** manual: implementation/high — исполнить P2.3 (участие ОКПД2 в сборке запроса:
`okpd2Ids` + `okpd2IdsWithNested`; `Exec = manual` по §3 Routing, `plan-exec` этот item не
исполняет)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Резолв `okpd2Codes` профиля → `okpd2Ids` + безусловный `okpd2IdsWithNested=on` в `EisCsvUrlBuilder` |
| Команда | — (нет одно-командного эквивалента: `Exec = manual`) |

```
/model sonnet
/effort high
/task:plan-run 2026.08.06-CRAWL-HARDEN P2.3
```

**Done:** P2.1 закрыт 🟠 Done with deviations (item-коммит `2e36ad56`) — таблица `prc_okpd2_nodes`,
модель `Okpd2Node`, фабрика, read-репозиторий `Okpd2NodeReadRepository`, датафайл
`database/data/eis-okpd2-nodes.json` (34 узла), идемпотентный `Okpd2NodeSeeder`.

**P2.2 закрыт 🟢 Done** (item-коммит `f172a43d`) — гейт скоупа ОКПД2 структурно обязателен:
поле `okpd2Codes` в `EisSearchFilter` (`required|array|min:1` + форма кода), контракт
`RequiresScope` и гейт `Crawler::saving()` (уже лежали в дереве незакоммиченными с прошлой
сессии — этот заход их не переписывал, только достроил периметр вокруг них). Достроено этим
заходом: дефолты фабрик `CrawlerFactory`/`RegistryFactory` (D108) — фабрика больше не отдаёт
несохраняемую модель; минимальный обязательный мультиселект `filter.okpd2Codes` в
`CrawlerForm` (D109), опции — `Okpd2NodeReadRepository::selectableNodes()`; точечная правка
пяти фикстурных файлов + ассерты `EisSeedersTest`/`CrawlerFormTest` под гейт. Приёмочная
дельта (D110) — **15 failed / 594 passed / 1 skipped на `f172a43d`** против baseline
**15 failed / 588 passed / 1 skipped на `618a2ce6`** (`artifacts/P2.2-perimeter-baseline.txt`) —
Δ failed = 0, падающие классы совпадают с baseline байт-в-байт. Детали, полный список
Validation-команд и Known Deviations (наблюдённая, но не воспроизведшаяся на закоммиченном
дереве флакость `FixtureRunResultTest`) — `phases/P2.md` P2.2 Completion Notes.

**Remaining:** 44 items из 51. Внутри P2: P2.3–P2.6.
Порядок жёсткий в трёх местах: P2 → P3 → P4 → P5 по coupling-поверхности (D98); P8 — после всех
схемных фаз P2–P6 и после терминальной P1.5 (D4/D83); P9 — после P8 (D4).

**Sources of truth:** `plan.md` (Meta 0.15.0, §3 Routing построчно, Status Board, D1–D110,
`## 6. Update Log`, `## 7. Contracts`, развилки № 35–№ 37) · `phases/P2.md` (P2.1/P2.2 закрыты,
P2.3 — ТЗ готово, P2.4–P2.6) · `research/03-p2-okpd2-design.md` §2.3/§2.4/§3.4/§5 ·
`findings/P1-okpd2-probe.md` §2/§3 (маска `okpd2IdsWithNested=on` — единственный рабочий режим) ·
`database/data/eis-okpd2-nodes.json` · `app/Repositories/Procurement/Platform/Okpd2NodeReadRepository.php`
(`externalIds()` — резолв кодов P2.3) · `open-questions.md` (Q9/Q10/Q13/Q15/Q16 — ни один не
блокирует P2).

**Open risks:**
- **Строка Validation «полный прогон — зелено» ложна ещё у 44 незакрытых items** — D110 поправил её
  только у P2.2 (и у P2.4). Долг назван в самой D-записи; правит его тот, кто эти items исполняет,
  либо следующий `plan-design`.
- **Загрязнение батч-прогона тестового контура**: `EisSeedersTest` (5 падений) и
  `StatusMappingSeederTest` (1) видят чужие реестры в батче и зелёные в одиночном прогоне. Дефект
  существует НА BASELINE (подтверждено ещё раз прогоном P2.2 на `f172a43d`), ни один item плана
  его не адресует — кандидат в follow-up item вне P2.
- **~7 предсуществующих падений окружения контейнера `app`** (`storage/app/tmp_extract`, запрет
  `ini_set('memory_limit')`: `Prepare/**`, `Screening/**`, `ContourTest`) — вне диффа плана.
- **Faker-флакость `Tests\Feature\Filament\FixtureRunResultTest`** (коллизия `fake()->words()` на
  `prc_platforms_code_unique`): наблюдалась ОДИН раз на прогоне P2.2 до item-коммита, на
  закоммиченном дереве не воспроизвелась, в изолированном прогоне зелёная. Не диффа P2.2, не
  адресована — некаузальный шум тестового контура, кандидат в тот же follow-up, что и загрязнение
  батча.
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
P1.4/P1.5) + починка загрязнения батч-прогона (включая Faker-флакость выше) + выравнивание строки
«полный прогон — зелено» у остальных items. Open questions: Q9, Q10, Q13, Q15 — `Exploration`,
Q16 — `Decision pending`, ни один не блокирует P2.
