# Roadmap исполнения — 2026.08.06-CRAWL-HARDEN

<!-- Собран режимом `finish` 2026-08-07 ПОСЛЕ сквозного reconcile всех девяти фаз.
     Отвечает на «какую команду дальше?» без чтения всего плана.
     Roadmap НЕ переопределяет §3 Routing — модель/effort/Exec берутся оттуда, здесь только
     группировка запусков и порядок. Закрытие фазы или re-design обновляет эту таблицу
     (+ строка в `## 6. Update Log` плана). -->

**Обновлён:** 2026-08-08 (P5.7 remediation и fast path D127) · **Соответствует plan.md:**
v0.25.0 · **Items:** 59, терминальны 37 — P1.1–P1.5, P2.1–P2.6, P3.1–P3.10,
P4.1–P4.9 и P5.1–P5.7 закрыты
(🟢/🟠 — точные статусы только в Status Board `plan.md` и `## Phase Status` фаз, здесь не
дублируются), открыты 22 items. AUDIT-CAP P3 закрыт решением владельца: P3 принята 🟠 без
четвёртого реопена. Ближайший шаг — **повторный audit P5**, затем P6.1.

## Жёсткий порядок

1. **P1 — первая.** Три фазы ждут её фактуру: P2 (карта ОКПД2), P3 (параметры листинга),
   P5/P6 (якоря вкладок, этапы, success-карточка), P8 (инвентарь карточки → Q16).
2. **P2 → P3 → P4 → P5** — последовательно: перед обычной цепочкой P4 исполняется follow-up
   **P4.7** (D117), затем P4.1→P4.8→P4.2→P4.4→P4.9→P4.5→P4.6; семь прежних coupling-точек D98 сохраняются, каждая следующая
   фаза дописывает то, что предыдущая уже сузила (`config/crawler.php`, гейты `Crawler::saving()`,
   сидер профилей, `EisSearchFilter`, `routes/console.php`).
3. **P6** — после P5 (наследует конвенцию доменного события, D55) и после P1.4/P1.5.
4. **P7** — независима: не ждёт ни одной фазы плана и не производит схемных правок (D75/D78).
   Исполнима в любой момент, в том числе параллельно P1–P6 в отдельной сессии.
5. **P8** — ПОСЛЕ всех схемных фаз (P2–P6) и после терминальной P1.5 (D4/D83).
6. **P9** — последняя (D4): описывает финальные схему и настройки.

## Карта исполнения

| Item | Batch | Запуск | Model class/Effort | Гейт владельца | Примечание |
|---|---|---|---|---|---|
| P1.1 | B1 | plan-run (manual) | frontier/high | ⚠️ при отрицательном вердикте | живой замер; вердикт «сортировка действует на CSV-выгрузке» — физическая основа P3.3 |
| P1.2 | B1 | ↑ | frontier/high | ⚠️ если колонка ОКПД2 окажется НЕпустой | карта «код → id НСИ»; непустая колонка = `supersedes D16` и реопен P2.6 |
| P1.4 | B1 | ↑ | frontier/high | — | этапы 44-ФЗ, якоря вкладок, сырой HTML success-карточки |
| P1.3 | solo | plan-run (manual) | frontier/high | — | RAG-ступень, канал разведки не нужен; две темы после D97 → вход P4.1/P4.4/P4.5 |
| P1.5 | solo | plan-run (manual) | frontier/high | — | до 60 запросов к площадке — отдельная сессия, иначе один вежливостный бюджет на ~100 обращений |
| P2.1 | — | plan-exec | implementation/medium | — | таблица `prc_okpd2_nodes`, датафайл, сид, резолвер |
| P2.2 | — | plan-run (manual) | implementation/high | — | поле `okpd2Codes` + структурный гейт скоупа (D18); coupling `Crawler::saving()`; после редизайна 2026-08-07 несёт также дефолты фабрик (D108) и минимальное поле формы (D109) |
| P2.3 | — | plan-run (manual) | implementation/high | — | `okpd2Ids` + `okpd2IdsWithNested`, отказ на нерезолвящемся коде (D21) |
| P2.4 | — | plan-exec | implementation/medium | — | ДОВОДКА поля, заведённого P2.2 (D109): поиск, группировка, helperText, пустая карта (D17) |
| P2.5 | — | plan-run (manual) | implementation/high | — | единственная точка фазы, ходящая на живую площадку |
| P2.6 | — | plan-exec | implementation/medium | — | сплошное удаление мёртвой ветки (D16): grep-гейт + `migrate:fresh --seed` |
| P3.1 | — | plan-run (manual) | implementation/high | — | инвертированная семантика `sortDirection` — coupling с P2 по `EisSearchFilter` |
| P3.2 | — | plan-run (manual) | implementation/high | — | `CrawlRunJob`, стоп-гейт, подъём зависших; инвариант `checkpoint < timeout < retry_after` |
| P3.3 | — | plan-run (manual) | implementation/high | — | зависит от вердикта P1.1: нет сортировки на выгрузке — режим пересматривается D-записью |
| P3.4 | — | plan-exec | implementation/medium | — | пять операторских действий (D26) поверх P3.2 |
| P3.5 | — | plan-exec | implementation/medium | — | сиды двух профилей; обязан пройти уже введённые гейты P2.2 |
| P3.6 | — | plan-exec | implementation/medium | — | витрина на `prc_fetches`/`runs.stats`; N+1 на партиционированной таблице |
| P3.7 | solo | plan-run (manual) | implementation/high | — | **реопен D111.** Кооперативный стоп в трёх обёртках + гейт `complete()`; solo — правит контракт, которым пользуются все режимы, а следующий item по данным ждёт его результата |
| P3.8 | solo | plan-run (manual) | implementation/high | — | **реопен D111.** Окно archive-прогона на профиле (D113); solo — приёмка «пять действий на архивном профиле» требует уже сданного P3.7, слить в батч нельзя |
| P3.9 | solo | plan-exec | implementation/medium | — | **реопен D111.** Гейт консольного отказа; solo — по `Model/effort` и `Exec` несовместим с P3.7/P3.8 (`plan-exec/medium` против `manual/high`), критерий слияния в батч не выполняется |
| P3.10 | solo | plan-run (manual) | implementation/high | — | **реопен D115.** Курсор — единственный источник дат в запросе (`EisCsvUrlBuilder`): профильное окно публикации перестаёт утекать в sweep-запрос delta/backlog (F1 аудита захода 2); solo — единственный открытый item фазы |
| P4.7 | solo, **FIRST** | plan-run (manual) | implementation/high | — | **follow-up AUDIT-CAP, D117.** Profile-driven `standard/long`: архив 2025 идёт в отдельные connection/queue/supervisor с 3600/3900 с; общий crawl и detailed jobs не лимитируются этим lane |
| P4.1 | — | plan-run (manual) | implementation/high | — | три уровня настроек (D36); читает свод P1.3 тема 1 |
| P4.8 | solo | plan-run (manual) | implementation/high | — | **remediation D119.** Восстанавливает UUID seed, document-neutral core boundary и fail-closed HTTP guard до P4.2 |
| P4.2 | — | plan-run (manual) | implementation/high | — | привязка прокси; ошибка = иностранный узел на ЕИС |
| P4.3 | — | plan-exec | implementation/medium | — | авто-дизейбл по серии открытий breaker'а (D41) |
| P4.4 | — | plan-run (manual) | frontier/high | — | общий бюджет площадки; читает свод P1.3 тема 1; необратимая цена — бан |
| P4.9 | solo | plan-run (manual) | implementation/high | — | **follow-up D121.** Типизированный outcome scanner: request-budget checkpoint не двигает недренированный день и не переводит archive/delta/backlog в failure |
| P4.5 | solo | plan-run (manual) | implementation/high | — | подача пачками; читает свод P1.3 тема 2 |
| P4.6 | — | plan-exec | implementation/medium | — | журнал всех заборов + единственный писатель `prc_operation_logs` |
| P5.1 | — | plan-exec | implementation/medium | — | эталон в git + `prc_platform_checks` + четыре вердикта (D50/D52) |
| P5.2 | — | plan-run (manual) | implementation/high | — | зонд якорей на ГОРЯЧЕМ пути снятия карточки |
| P5.3 | — | plan-run (manual) | implementation/high | — | здесь рождается «ok»; пропущенный `not_enough_data` = ложное здоровье |
| P5.4 | — | plan-run (manual) | implementation/high | — | единственная активная проба фазы: доказательство, что выборка строительная |
| P5.5 | — | plan-run (manual) | implementation/high | — | ПЕРВОЕ доменное событие проекта; конвенция наследуется P6.5 (D55) |
| P5.6 | — | plan-exec | implementation/medium (`Review = light`) | — | read-only витрина, единственный `light` в плане |
| P5.7 | solo | plan-run (manual) | implementation/high | — | remediation D125/D127 закрыт; пять healthcheck findings и stale full-suite expectation исправлены в одной сессии |
| P6.1 | — | plan-run (manual) | implementation/high | — | канон положительного исхода (D61); вход — `artifacts/eis-stages.json` (P1.4) |
| P6.2 | — | plan-run (manual) | frontier/high | — | живой контакт с площадкой + правка боевого парсера по свежей фактуре |
| P6.3 | — | plan-run (manual) | implementation/high | — | история на ДВУХ партиционированных таблицах; граница по времени в сигнатуре |
| P6.4 | — | plan-exec | implementation/medium | — | витрина истории; реестр не редактируется (grep-гейт) |
| P6.5 | — | plan-run (manual) | implementation/high | — | контракт события замораживается для чужого плана (§7 Contracts) |
| P7.1–P7.3 | W1 | orchestration | implementation/medium посрезово (см. §3 Routing) | — | автономная цепочка трёх `plan-exec`-срезов, связанных по данным (D79 в редакции D100); срезы идут ПОСЛЕДОВАТЕЛЬНО |
| P7.4 | — | plan-run (manual) | implementation/high | — | `Exec = manual` (D100): правка БОЕВОГО `EstimateValidator` и подбор порогов регресс-гейта — в скрипт не отдаётся |
| P7.5 | — | plan-run (manual) | implementation/high | — | `Exec = manual` (D100): кастомная страница вне enum-RBAC и тела промтов — в скрипт не отдаётся |
| P8.1 | — | plan-run (manual) | frontier/high | ✅ **список полей под колонки (Q16)** | карта поглощения + счётный снимок; ошибка тиражируется P8.2 и P8.3 |
| P8.2 | — | plan-run (manual) | implementation/high | — | закупочно-краулинговый контур; потерянный индекс не видит НИ ОДИН тест |
| P8.3 | — | plan-run (manual) | implementation/high | — | AI/LLM/сметный контур + удаление кросс-контурных `…100069`/`…100070` |
| P8.4 | — | plan-exec | implementation/medium | — | пост-аудит: `fresh --seed`, `partitions:maintain`, полный прогон, phpstan |
| P9.1 | — | plan-run (manual) | implementation/high | — | карта переноса ~1900 строк; ошибка тиражируется четырьмя items |
| P9.2 | — | plan-exec | implementation/medium | — | Pest-гейт целостности — ЕДИНСТВЕННАЯ защита P9.3–P9.5 |
| P9.3 | — | plan-exec | implementation/medium | — | `docs-private/` исчезает; периметр зеркалирования в Brain расширяется (D89) |
| P9.4 | — | plan-run (manual) | implementation/high | — | транскрипция 1155 строк спек, известных как разошедшиеся с кодом |
| P9.5 | — | plan-run (manual) | implementation/high | — | 584 строки контракта извлечения, цитируемого ПО ПАРАГРАФАМ правилом ревью |
| P9.6 | B2 | plan-exec серия | implementation/medium | — | кроулинг + диаграммы Д7–Д12 |
| P9.7 | B2 | ↑ | implementation/medium | — | документы и AI + Д13–Д14; входное состояние дерева общее с P9.6 |
| P9.8 | — | plan-exec | implementation/medium | — | навигация, `docs/meta/structure.md`, Д15, сверка «до/после» |

Solo-запуски обоснованы построчно в `## 3. Routing`: `manual` — там, где остались открытые
design-решения или цена ошибки необратима; `plan-exec` — там, где решения запинены D-записями,
а гейты детерминированы командой.

## Следующий запуск — повторный audit P5

**Next:** task:plan-audit: frontier/xhigh

| Параметр | Значение |
|:--|:--|
| Model class | frontier (`gpt-5.6-sol`) |
| Effort | xhigh |
| Capabilities | — |
| Context | cold-start — шаг-не-item |
| Суть | Повторно сверить P5 после P5.7 и подтвердить, что F1–F6 закрыты без новых регрессий. |
| Команда | `$ task:plan-audit 2026.08.06-CRAWL-HARDEN P5` |

```text
select session model class frontier
select session effort xhigh
$ task:plan-audit 2026.08.06-CRAWL-HARDEN P5
```

## Готовые launch-block'и групп

### B1 — живая разведка одним каналом (P1.1 → P1.2 → P1.4)

| Параметр | Значение |
|:--|:--|
| Model class | frontier (`gpt-5.6-sol`) |
| Effort | high — открытые design-решения, вердикты замораживают форму P2/P3/P5/P6 |
| Capabilities | Playwright MCP (RU-прокси + `ignoreHTTPSErrors`), repo-чтение |
| Context | cold-start: `plan.md` → `roadmap.md` → `phases/P1.md` → скилл `eis-recon` |
| Суть | Канал поднимается ОДИН раз, три item'а идут последовательно, закрытие КАЖДОГО — полный протокол `plan-protocol` §8 |
| Команда | `/task:plan-run 2026.08.06-CRAWL-HARDEN P1.1 P1.2 P1.4` |

```
/model gpt-5.6-sol
/effort high
/task:plan-run 2026.08.06-CRAWL-HARDEN P1.1 P1.2 P1.4
```

<!-- Батч идёт через plan-run, а не plan-exec: все три item'а — `Exec = manual` (frontier/high),
     а plan-exec держит жёсткий потолок implementation/medium. -->

### B2 — два раздела документации одной сессией (P9.6 + P9.7)

| Параметр | Значение |
|:--|:--|
| Model class | implementation (`gpt-5.6-terra`) |
| Effort | medium — открытых решений нет, текст пишется по уже финальному коду |
| Capabilities | repo-чтение, запись `docs/**`, прогон Pest-гейта P9.2 |
| Context | cold-start: `plan.md` → `roadmap.md` → `phases/P9.md` (после закрытия P9.5) |
| Суть | Нулевая связность между items, один тир, одно входное состояние дерева; закрытие КАЖДОГО — полный протокол §8 |
| Команда | `/task:plan-exec 2026.08.06-CRAWL-HARDEN P9.6 P9.7` |

### W1 — срезы P7.1–P7.3 оркестрацией

```
kind: orchestration
orchestration_id: 2026.08.06-CRAWL-HARDEN:W1
script: plans/2026.08.06-CRAWL-HARDEN/workflows/wf-crawl-harden-p7.js
inputs: [plan.md, roadmap.md, phases/P7.md]
slices: P7.1 → P7.2 → P7.3 (последовательно, implementation/medium посрезово из §3 Routing)
```

Запуск — NEW SESSION:

```
Workflow({scriptPath: "plans/2026.08.06-CRAWL-HARDEN/workflows/wf-crawl-harden-p7.js"})
```

Скрипт доводит каждый срез до зелёного и коммитит дифф среза; бухгалтерию плана (статусы,
handoff, `plan-lint`) закрывает `/task:plan-close 2026.08.06-CRAWL-HARDEN P7.m` — скрипт items
НЕ закрывает.

**Фаза P7 скриптом не заканчивается.** P7.4 и P7.5 несут `Exec = manual` (§3 Routing) и в SLICES
не входят (D100) — после зелёного W1 они запускаются ВРУЧНУЮ, по одному, отдельными сессиями:

```
/task:plan-run 2026.08.06-CRAWL-HARDEN P7.4
```

```
/task:plan-run 2026.08.06-CRAWL-HARDEN P7.5
```

## Гейты владельца (сводно)

| Где | Что утверждает | Блокирует |
|---|---|---|
| P8.1 | Список полей инвентаря карточки, получающих собственные колонки против `attributes` (jsonb) — Q16, правило решения запинено D83, открыт сам список | P8.2 (состав колонок `prc_procurements`) |
| P1.1 (условно) | Если CSV-выгрузка НЕ упорядочена по дате обновления — новая развилка вместо обходного пути | P3.3 (режим «только актуальные») |
| P1.2 (условно) | Если колонка «Классификация по ОКПД2» окажется НЕпустой — D-запись `supersedes D16` | P2.6 (снос мёртвой ветки) |

Остальные развилки закрыты: 34 из 34 в `## Обсуждение` несут `Resolved → D#`. Незакрытыми
остаются Q9/Q10/Q15 (`Exploration`), Q13 (вне скоупа плана) и Q16 (`Decision pending` — состав
колонок инвентаря карточки). Исполнение блокирует только Q16, и ровно в той точке, что названа
таблицей гейтов владельца выше: решение принимается на P8.1, блокирует P8.2. Остальные четыре
не блокируют ничего.
