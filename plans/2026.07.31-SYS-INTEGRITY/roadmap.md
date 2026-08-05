# Roadmap исполнения — 2026.07.31-SYS-INTEGRITY

**Обновлён:** 2026-08-05 · **Соответствует plan.md:** v0.25.0

> Пересобран ЦЕЛИКОМ заходом `plan-design <ID> finish` (design pass 5/5, D141–D145) — тем же
> шагом, который закрыл сквозной reconcile восьми фаз. Roadmap **не переопределяет** `plan.md` §3
> Routing: он только группирует запуски и даёт готовые launch-block'и.
>
> **Провенанс точечных правок карты** (реопен P1 D82; восемь ре-дизайнов фазы P2 — D88–D111;
> пересчёт фактуры P3 D112; ремедиация боевого прогона P4 — D118/D123/D129) не пересказывается
> здесь второй прозой: его носитель — `plan.md` §6 Update Log (append-only) и §5 Decision Log.
> Каждая из тех правок меняла состав/очередь items ВНУТРИ фазы, не трогая контракты фаз, — поэтому
> полного `finish` не требовала (`plan-protocol` §8).

## Состояние на 2026-08-05

**Фазы P1–P7 терминальны** — все семь `🟠 Done with deviations` (счётчики `Items 🟢/всего` — в
Status Board `plan.md` §4, он единственный носитель статуса фазы). Их строки в карте ниже —
**провенанс исполнения**, а не список к запуску.

**Открыта одна фаза — P8** (`⬜ Not started`, 0/7), детализированная 2026-08-05 (D136–D140),
прошедшая сквозной reconcile 2026-08-05 (D141–D145) и **pre-exec гейт
`task:plan-audit … design`** (2026-08-05, вердикт `ATTENTION`; 9 находок сняты точечной правкой
ТЗ — D146–D150). Предстартовых шагов не осталось: следующий запуск — **P8.1**.

## Порядок фаз — обязательный (D63)

**Нумерация фазы — её адрес, а не место в очереди.** Очередь исполнения:

```
P1 Прокси → P2 Контракты LLM → P3 Раскладка → P5 Смета → P6 Хранилище → P4 ЕИС+прогон → P7 Ревизия → P8 Деградированный источник
```

Из чего она собрана (каждое звено — отдельное решение, вместе они дают именно этот порядок):

| Ограничение | D# | Почему нарушать нельзя |
|:--|:--|:--|
| Вся P4 (включая research-item) — после терминальной P1 | D3, D36 | Прямой доступ к ЕИС с рабочего хоста не отвечает; прогон обязан идти через прокси-скоуп |
| P6 терминальна ДО боевого прогона P4.7 | D12 | Пока боевых файлов нет — это миграция без backfill; после — перенос данных с dual-read и верификацией |
| P5 терминальна ДО боевого прогона P4.7 | D47 | Пока боевых смет нет — миграция nullable; после — ПОВТОРНОЕ платное извлечение по новому контракту |
| Вся P7 — после терминальной P4 | D56, D71 | Ревизия ставится на дерево с боевыми данными; метрик, которыми ПОДТВЕРЖДАЕТСЯ решение «одна БД» и проверяется триггер пересмотра D72, до прогона не существует |
| P4/P6/P7 создают новые классы ПОСЛЕ P3 | D65 | Файл в выровненном слое обязан лечь по правилу `research/04`, иначе `DomainLayoutTest` краснеет |
| P5 ждёт терминальных фаз P4/P5/P7 чужого плана `2026.07.26-EST-TRAIN` | D41, D42 | Кросс-планный гейт: предусловие проверяется по строкам Status Board донора (все три терминальны) |
| **P8 — в хвосте: потребляет терминальные P2/P3/P5** | D120, D140, **D141** | Носитель пометки — поле контракта P2 и колонка таблицы P5; правка обязана быть АДДИТИВНОЙ, ни одна закрытая фаза не переоткрывается. Сама P8 не блокирует никого |

P2 и P3 между собой переставимы и ничего не блокируют; всё остальное в очереди — жёстко.

## Карта исполнения

Строки идут в порядке очереди фаз (P1 → P2 → P3 → P5 → P6 → P4 → P7 → P8), а не по номерам.
Items фазы P8 идут строго последовательно (`P8.1 → … → P8.7`): `Inputs` каждого ждут
`Deliverables` предыдущего — поэтому **батчей в фазе нет, все items solo**, и по той же причине
не выполняется критерий заявленной оркестрации (`plan-protocol` §7, «N≥3 items с независимым
scope») — workflow-скрипт не заводится.

| Item | Batch | Запуск | Model class/Effort | Гейт владельца | Примечание |
|---|---|---|---|---|---|
| P1.1 | solo | manual (plan-run) | frontier/high | — | ✅ закрыт. design-item: модель данных, политика выбора, контракт резолвера |
| P1.2 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. миграции по `research/02`; класс «migrations» всегда `full` |
| P1.3 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. миграция + правка боевого цикла прогонов |
| P1.4 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. инварианты выбора и circuit breaker |
| P1.5 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. security-sensitive гейт страны + порт `OutboundProxy` (D16) |
| P1.6 | B1 | plan-exec серия | implementation/medium | — | ✅ закрыт. скоупы в ТРИ call-site'а `pick()` по замороженному контракту P1.4 (D77) |
| P1.7 | B1 | ↑ | implementation/medium | — | ✅ закрыт. Filament-поверхность прокси; сцеплен с P1.6 одной сессией |
| P1.8 | solo | plan-exec | implementation/low | — | ✅ закрыт 🟠 (D85: красная Validation) — доки + приёмочный прогон |
| P1.9 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. заведён реопеном D82/D83/D84: привязка профиля + `required_country` + предохранитель гейта LLM |
| P1.10 | solo | plan-exec | implementation/low | — | ✅ закрыт. заведён реопеном D87: доки под факт + повторная приёмка фазы |
| P2.1 | solo | manual (plan-run) | frontier/high | — | ✅ закрыт 🟢 — первичное подтверждение вендора (гейт D9 снят D88) |
| P2.8 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. разреженный обход листа в `TextGridRenderer` + два порога-настройки |
| P2.2 | solo | manual (plan-run) | implementation/high | ✅ цена парного baseline (пройден) | ✅ закрыт 🟢. гидрация БД из git-SSOT (D93) + пара M0/M0′ |
| P2.3 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. `#[Description]` + ридер + арх-гейт покрытия |
| P2.9 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. бюджет вывода маршрута как настройка + оживление предохранителя MAX_TOKENS |
| P2.10 | solo | manual (plan-run) | implementation/high | ✅ цена двух прогонов (D100) | ✅ закрыт 🟢. пара M0₂/M0₂′ на починенном контуре |
| P2.4 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. эмиссия описаний и порядка свойств в исходящую схему |
| P2.5 | solo | manual (plan-run) | implementation/high | ✅ пройден (D101–D103) | ✅ закрыт 🟠. вариант C — типизированный рендер синонимов; OQ-2 закрыт |
| P2.6 | solo | manual (plan-run) | frontier/high | — | ✅ закрыт 🟠 БЕЗ item-коммита: редакция текстов провалила M4 и откачена |
| P2.11 | solo | plan-exec | implementation/medium | — | ✅ закрыт 🟢. фикс `PromptCanonSynchronizer` на даунгрейд канона |
| P2.13 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟢. режим `--criterion=noise-aware` + `overall`-агрегат + профиль вызовов |
| P2.12 | solo | manual (plan-run) | implementation/high | ✅ пройден (D110) | ✅ закрыт 🟠 БЕЗ item-коммита: дедупликация НЕ поставлена, тексты откачены (**D141** — вход контракта P8) |
| P2.7 | solo | plan-exec | implementation/low | — | ✅ закрыт 🟠. доки/спека/скил + приёмочный прогон фазы |
| P3.1 | solo | manual (plan-run) | frontier/high | ✅ список слоёв класса A | ✅ закрыт 🟢. правило размещения выведено и заморожено arch-тестом |
| P3.2 | B2 | plan-run серия | implementation/high | — | ✅ закрыт 🟠. перенос 353 файлов по таблице `research/04` (D112) |
| P3.3 | B2 | ↑ | implementation/high | — | ✅ закрыт 🟠. слои класса A по гейту OQ-8/D113 |
| P3.4 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. `DomainLayoutTest` с обязательной красной пробой |
| P3.5 | solo | plan-exec | implementation/low | — | ✅ закрыт 🟠. синхронизация канона |
| P5.1 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. инвентарь потери на записи + дельта-вердикты (D41); **находка «`RerunRangeAction` — второй writer-путь» → вход P8.4** |
| P5.2 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. полная запись сметы по обоим writer-путям |
| P5.3 | solo | manual (plan-run) | frontier/high | ✅ носители, имена, индексы | ✅ закрыт 🟢. решение по носителям подтверждённых величин |
| P5.4 | solo | manual (plan-run) | implementation/high | ✅ апрув суммы прогона | ✅ закрыт 🟢. baseline платного цикла и критерий приёмки |
| P5.5 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. миграции/enum'ы/контракты величин — **схема `prc_estimate_items`, на которую ложится P8.4** |
| P5.6 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟢. gold-мапперы + `EvalMetrics` — **прецедент вердикта измеримости D46, форма заимствуется P8.6** |
| P5.7 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. промты под новые поля + контрольный прогон |
| P5.8 | solo | plan-exec | implementation/low | — | ✅ закрыт 🟠. канон сметной модели в доках + приёмка фазы |
| P6.1 | solo | manual (plan-run) | frontier/high | — | ✅ закрыт 🟢. вердикты по носителям + контракт резолвера + процедура cutover |
| P6.2 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟢. колонка `disk` на подтверждённых носителях |
| P6.3 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟢. резолвер адреса как единственная точка чтения/записи |
| P6.4 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟢. фолбэк-цепочка дисков на окно cutover |
| P6.5 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. backfill с верификацией |
| P6.6 | solo | plan-exec | implementation/low | — | ✅ закрыт 🟢. runbook смены провайдера + доки |
| P4.1 | solo | manual (plan-run) | frontier/high | — | ✅ закрыт. живая разведка ЕИС через прокси (D36) |
| P4.2 | solo | manual (plan-run) | frontier/high | — | ✅ закрыт. состав двух профилей, план прогона, метрики |
| P4.3 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. типизированный `EisSearchFilter` |
| P4.4 | B3 | plan-exec серия | implementation/medium | — | ✅ закрыт. Filament-поля площадки |
| P4.5 | B3 | ↑ | implementation/medium | — | ✅ закрыт. сиды площадки, реестра и двух профилей |
| P4.9 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. раскладка колонок CSV и резолв карточки (D118/D121) |
| P4.6 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. перекрытие окон, delta-scan под флагом, метрика отставания (D122) |
| P4.7 | solo | manual (plan-run) | frontier/high | ✅ окно прогона (D126) | ✅ закрыт. первый боевой прогон; после него схема неподвижна |
| P4.10 | B4 | plan-exec серия | implementation/medium | — | ✅ закрыт. детерминизм разбора дат (Д-1) |
| P4.11 | B4 | ↑ | implementation/medium | — | ✅ закрыт. три тихих дефекта контура прогона (Д-2/Д-3/Д-4, узкое исключение D127) |
| P4.12 | solo | manual (plan-run) | implementation/high | ✅ форма доверия корню (D125) | ✅ закрыт. CA-бандл в образе + узкий `verify` клиента ЕИС |
| P4.13 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. документный контур + живая проба вкладок карточки (Д-5) |
| P4.14 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт. детальный слой карточки (Д-6/Д-7) |
| P4.15 | solo | manual (plan-run) | frontier/high | ✅ судьба данных (D124) · объём (D126) | ✅ закрыт. очистка, перезалив трёх дней, пере-baseline |
| P4.16 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. Д-8 (транспорт стирал query карточного URL) + два сторожа (D130/D131) — **64 документа боевого прогона, вход п.5 P8.1** |
| P4.8 | solo | plan-exec | implementation/low | — | ✅ закрыт 🟢. доки домена + приёмка фазы, исполнялся ПОСЛЕДНИМ (D123/D129) |
| P7.1 | solo | manual (plan-run) | frontier/high | ✅ список находок (D132) | ✅ закрыт 🟢. ревизия схемы и рантайма на живой БД — 13 находок |
| P7.2 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟢. сверка пяти критериев + вердикт измеримости |
| P7.3 | solo | manual (plan-run) | frontier/high | только при срабатывании триггера D72 | ✅ закрыт 🟢. D71 «одна БД» подтверждён числами, модель D72 — канон |
| P7.4 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. наблюдаемость, параметры кластера, `shm_size`, кэш на Redis |
| P7.5 | solo | manual (plan-run) | implementation/high | ✅ F-02/F-06 (D133) | ✅ закрыт 🟠. восемь неразрушающих миграций |
| P7.6 | solo | manual (plan-run) | frontier/high | ✅ перечень фиксов → OQ-9/D134 | ✅ закрыт 🟢. аудит 52 процессов, 12 находок |
| P7.7 | solo | manual (plan-run) | implementation/high | — | ✅ закрыт 🟠. фиксы контракта процессов по гейту D134 |
| P7.8 | solo | plan-exec | implementation/low | — | ✅ закрыт 🟠. приёмочный прогон + итоговая трассировка брифа |
| **P8.1** | solo | **manual (plan-run)** | implementation/high | — | research-item фазы (D2): инвентарь потери сигнала + ЖИВАЯ проба среза скан-эталона, РОВНО один платный прогон с пре-гейтом `Whole` до денег (D138). Срез снимается `PdfPageSlicer::sliceRange()` через `tinker` — CLI у него нет (D143). Solo: его фактура и есть вход P8.2 |
| **P8.2** | solo | **manual (plan-run)** | frontier/high | ✅ четыре предмета: enum причин · форма `RowQuality` · носитель уровня сметы · границы «что НЕ помечаем» (D146) | единственный frontier фазы: открытые design-решения, форма уезжает разом в контракт LLM, миграцию, витрину и метрику. Item НЕ закрывается без вердикта владельца (Implementation Rules 4) |
| **P8.3** | solo | **manual (plan-run)** | implementation/high | — | контракт ответа + правило промта `extraction:rows` (версия 2→3, `ai:prompts:sync`). Текст промта НЕ вычищен от дублей схемы (**D141**) — чужие не чистим, своих не добавляем, якорный `PromptCompositionTest` обязан остаться зелёным |
| **P8.4** | solo | **manual (plan-run)** | implementation/high | — | миграция `prc_estimate_items.quality` на БД С БОЕВЫМИ ДАННЫМИ + оба writer-пути + правило re-run; класс «migrations» всегда `full` |
| **P8.5** | solo | **`task:plan-exec`** | implementation/medium | — | read-only ресурс смет (D137). Solo: соседи по очереди идут `manual/high` — критерий слияния в батч (совместимые Model/effort и Exec) не выполняется |
| **P8.6** | solo | **manual (plan-run)** | implementation/high | — | критерий приёмки качества пометок: fill-rate вместо F1 (D139), `FIELD_MAP` неприкосновенна и охраняется тестом; ноль платных вызовов |
| **P8.7** | solo | **`task:plan-exec`** | implementation/low | — | доки сметного домена, оба глоссария, скилл + приёмочный прогон фазы `--no-tia`; Inputs ждут Completion Notes P8.1–P8.6 |

**Гейт закрытия любого item'а** (`plan-protocol` §8): инвокация линтера названа целиком в
`plan.md` §2 Execution Rules (D76) — скрипт лежит ВНЕ репозитория, из дерева его путь не выводится.

Оркестрация (workflow-скрипты) не заявлена ни в одной фазе: внутри каждой items сцеплены по данным
(Inputs следующего ждут Deliverables предыдущего), то есть критерий «N≥3 items с независимым scope»
(`plan-protocol` §7) не выполняется нигде.

## Launch-block'и — фаза P8 (по одному на item, батчей нет)

> Порядок обязателен: `P8.1 → P8.2 → P8.3 → P8.4 → P8.5 → P8.6 → P8.7`. Гейт
> `task:plan-audit 2026.07.31-SYS-INTEGRITY design` **пройден 2026-08-05** (`ATTENTION`, находки
> сняты D146–D150) — P8.1 запускается без предварительных шагов.

### P8.1 — инвентарь потерь + живая проба скана

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | сеть (реальный LLM-вызов, ровно один) · Docker-контейнер приложения |
| Context | same-session — item |
| Суть | Research-item фазы: инвентарь потери сигнала чтением кода + оффлайн-профиль пустоты по шести gold + ОДИН платный прогон среза скан-эталона после пре-гейта `Whole` |
| Команда | — (`Exec = manual`: `task:plan-exec` отказал бы по undershoot-гейту §9) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.1
```

### P8.2 — синтез: модель неопределённости (гейт владельца)

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | Заморозить enum причин, форму `RowQuality`, провенанс, правило re-run и карту следствий для P8.3–P8.6; владельцу ДО закрытия item'а предъявляются ЧЕТЫРЕ предмета (сверх enum и формы — вердикт по носителю уровня сметы и границы «что НЕ помечаем», D146) |
| Команда | — (`Exec = manual`, frontier) |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.2
```

### P8.3 — контракт ответа модели и правило промта

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | Docker-контейнер приложения (тесты, `ai:prompts:sync`) |
| Context | same-session — item |
| Суть | Опциональный носитель качества в `EstimateRow` + `#[Description]` + правило поведения в `extraction:rows` (версия 2→3); ноль реальных LLM-вызовов |
| Команда | — (`Exec = manual`) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.3
```

### P8.4 — персист: колонка `quality` и оба writer-пути

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | Docker-контейнер приложения (миграции на `agelto_test`) |
| Context | same-session — item |
| Суть | Аддитивная миграция на БД с боевыми данными + запись пометок модели и валидатора через `EstimateStoreRepository` + поведение на точечном re-run |
| Команда | — (`Exec = manual`, класс «migrations» всегда `full`) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.4
```

### P8.5 — операторская поверхность (read-only ресурс смет)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | Docker-контейнер приложения (Livewire-прогон страниц) |
| Context | same-session — item |
| Суть | Минимальный read-only Filament-ресурс смет: счётчик помеченных агрегатом, просмотр позиций, фильтр «только помеченные» |
| Команда | `/task:plan-exec 2026.07.31-SYS-INTEGRITY P8.5` |

### P8.6 — gold и eval: представимость пометок и вердикт измеримости

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | Docker-контейнер приложения |
| Context | same-session — item |
| Суть | Пометка представима в эталоне и валидируется; отдельный ключ fill-rate; сторож «пометки нет в `FIELD_MAP`»; структурное условие пересмотра вердикта |
| Команда | — (`Exec = manual`) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.6
```

### P8.7 — доки сметного домена и приёмочный прогон фазы

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | low |
| Capabilities | Docker-контейнер приложения (полный сюит `--no-tia`) |
| Context | same-session — item |
| Суть | Синхронизация спеки/БД-доков/панели/операторского сценария/глоссариев/скилла + приёмка фазы с атрибуцией падений + `Phase Handoff` P8 |
| Команда | `/task:plan-exec 2026.07.31-SYS-INTEGRITY P8.7` |

## Исполненные батчи P1–P4 (провенанс)

### B1 — P1.6–P1.7: перевод каналов на скоупы + Filament-поверхность прокси

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | same-session — item |
| Суть | Серия P1.6–P1.7 одной сессией по замороженному контракту резолвера P1.4 |
| Команда | `/task:plan-exec 2026.07.31-SYS-INTEGRITY P1.6 P1.7` |

### B2 — P3.2–P3.3: перенос enum'ов и слоёв класса A

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | Серия P3.2–P3.3 одной сессией: массовая правка namespace'ов по таблице `research/04` (353 файла на `4b628a8`, D112) |
| Команда | — (`Exec = manual`: `run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P3.2-P3.3`) |

### B3 — P4.4–P4.5: Filament-фильтры и сиды площадки

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | same-session — item |
| Суть | Серия P4.4–P4.5 одной сессией по замороженному контракту фильтра P4.3 |
| Команда | `/task:plan-exec 2026.07.31-SYS-INTEGRITY P4.4 P4.5` |

### B4 — P4.10–P4.11: детерминизм дат и тихие дефекты контура прогона

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | same-session — item |
| Суть | Серия P4.10–P4.11 одной сессией: оба item'а — предписанные фиксы по названным диагнозам, оффлайн, с детерминированным гейтом «красное без фикса» |
| Команда | `/task:plan-exec 2026.07.31-SYS-INTEGRITY P4.10 P4.11` |

## Гейты владельца (сводно)

| Где | Что утверждает | Блокирует |
|---|---|---|
| P2 (четыре design-шага) | ~~цена парного baseline · скоуп фикса MAX_TOKENS и цена пере-baseline'а · топология повторного захода после M4 · направление после второго красного~~ — **все пройдены 2026-08-01/02** (D91, D98/D100, D105–D107, D109/D110) | — |
| P2.5 / P2.12 | ~~условные гейты (префикс ниже 4096; второй красный замер подряд)~~ — **оба сработали и пройдены** (D101–D103, D108–D110) | — |
| P3.1 | ~~список слоёв класса A~~ — **пройден** (OQ-8/D113; класс B вынесен в отдельный план) | — |
| P4.7 / P4 ре-дизайны | ~~окно прогона · состав ремедиации семи дефектов · судьба данных первого прогона · доверие корню Минцифры · форма сторожа и объём живой приёмки Д-8~~ — **пройдены 2026-08-05** (D123–D126, D130/D131) | — |
| после P4.16 | расширение окна прогона и включение delta-scan/fresh (D126) — пересматривается владельцем, когда документный контур доказан живыми числами | набор корпуса для P5/P8 |
| P5.3 / P5.4 | ~~носители величин, имена, индексы · сумма платного цикла~~ — **пройдены 2026-08-04** | — |
| P7.1 / P7.3 / P7.6 | ~~список находок ревизии (D132) · топология (снят D71) · перечень фиксов процессов (OQ-9/D134)~~ — **пройдены 2026-08-05** | — |
| P8 design-заход | ~~носитель пометки · объём операторской поверхности · цена фактуры по сканам · измеримость пометок~~ — **пройден 2026-08-05**: отдельная jsonb-колонка (D136), минимальный read-only ресурс (D137), срез + один платный прогон (D138), fill-rate вместо F1 (D139) | — |
| **P8.2** | **четыре предмета перед заморозкой** (Implementation Rules 4, **D146**): состав enum причин · форма носителя `RowQuality` · вердикт «нужен ли носитель качества уровня СМЕТЫ» · поимённый список «что НЕ помечаем» — последние два и есть место, где план сузил дословное «напротив каждого пункта» | **P8.3–P8.6** |
| **P8.1** (условный) | срез не доводится до `ExtractionRoute::Whole` ни при каком разумном числе страниц → решение по D138 (оконный путь или отказ от платной пробы) | платный шаг P8.1 |
| OQ-10 (после первых боевых пометок) | нужен ли контур операторских правок помеченной позиции и чем он является — продолжением `prc_ai_corrections` или отдельным редактированием витрины | основание отдельной работы; P8 не блокирует |
