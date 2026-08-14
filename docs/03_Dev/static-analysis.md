---
audience: dev
type: reference
status: living
---

# Статический анализ (PHPStan/Larastan)

## Текущая обязательная политика (P10.6)

`phpstan.neon` анализирует `app/` на `level: max`. В репозитории **нет**
`phpstan-baseline.neon`, `ignoreErrors` или `excludePaths`: новая ошибка исправляется в её
реальном контракте, а не скрывается конфигурацией. Полный проверяемый carrier —
`make quality`; точечная команда — `docker compose exec -T app ./vendor/bin/phpstan analyse
--memory-limit=1G --no-progress`.

Rector зафиксирован зависимостями `rector/rector 2.6.2` и
`driftingly/rector-laravel 2.5.0`; разрешён только `--dry-run`. Pint — dry gate
`docker compose exec -T app ./vendor/bin/pint --test`.

## Историческая baseline-политика до P10.6

Следующий раздел — provenance P6.3. Он описывает удалённый baseline и не является текущей
инструкцией для изменения PHPStan.

## Правило: baseline не растёт без D-записи

- Новая запись в `phpstan-baseline.neon`, которую твой item не может снять легальным рычагом
  (ниже) — item чинит код (если это настоящая ошибка) либо эскалирует (`plan-protocol` §10, если чинить
  вне Scope) и заводит D-запись в плане. Молчаливое расширение baseline — блокирующее замечание
  ревью (ось — `.claude/rules/agelto-reviewer-overrides.md`).
- Легальные рычаги снятия: (а) `databaseMigrationsPath` в `phpstan.neon` — сканирование миграций
  Larastan; (б) `@property`/`@property-read` докблоки на моделях, отражающие ФАКТИЧЕСКУЮ схему/связь.
- Запрещены как способ «снятия»: точечный `@phpstan-ignore`, `excludePaths`, понижение `level`,
  отключение `reportUnmatchedIgnoredErrors`. Ни один из этих приёмов не появляется в диффе
  `phpstan.neon`/коде — при подозрении `git diff <base>..HEAD -- phpstan.neon | grep -E
  "excludePaths|level"` обязан быть пуст.
- Настоящая ошибка, обнаруженная ПОПУТНО при разборе долга (не ложное срабатывание, а живой
  дефект) — не чинится «заодно»: фиксируется отдельной находкой и эскалируется под свой item
  (пример — §4 «Найденный вживую дефект» ниже).

## Легальные рычаги — что сработало, что нет (P6.3, снимок `<base>` = `0badded`)

| Рычаг | Итог | Почему |
|:--|:--|:--|
| `databaseMigrationsPath` в `phpstan.neon` | **Неприменим, 0 эффекта** | Larastan's `SchemaAggregator` резолвит имя таблицы `Schema::create()` ТОЛЬКО из литерального `String_`-узла AST; 47 из 51 миграции проекта используют `Schema::create($this->table(), …)` (динамическое имя через `(new Model)->getTable()`) — ни одна доменная таблица не даёт распознать колонки этим путём. Единственные таблицы, которые рычаг видит — фреймворковые (`users`/`sessions`/`jobs`/…, литеральное имя), у них `property.notFound` и так не было. Правка конвенции 47 миграций — рефакторинг прикладного кода, вне Scope Excluded этого item'а |
| `@property`/`@property-read` докблоки | **Сработал** — основной рычаг | Модели AI-контура и домена `Llm` (плюс примыкающие `Document`/`DocumentVersion`/`Estimate`-семейство, которые AI-пайплайн читает/пишет напрямую) не несли докблоков вовсе или несли частично; добавлены докблоки, отражающие реальные колонки миграций и relation-аксессоры, читаемые через magic-property (`$analysis->documentVersion`, а не `$analysis->documentVersion()`) |

`phpstan.neon` НЕ содержит `databaseMigrationsPath` (рычаг опробован и откачен как не дающий эффекта —
Implementation Rule 4/5 P6.3: «рычаг неприменим — откатить и записать почему»).

## Числа: `<base>` → после P6.1–P6.3

`<base>` = `0badded` (коммит перед P6.3, после P6.1+P6.2). Числа воспроизводятся:

```
git show 0badded:phpstan-baseline.neon | grep -c "message:"                              # записи, до
git show 0badded:phpstan-baseline.neon | grep -oP "count: \K\d+" | paste -sd+ | bc        # ошибки, до
grep -c "message:" phpstan-baseline.neon                                                   # записи, после
grep -oP "count: \K\d+" phpstan-baseline.neon | paste -sd+ | bc                            # ошибки, после
```

| | Записей | Ошибок |
|:--|--:|--:|
| До (`0badded`) | 240 | 309 |
| После (P6.3) | 74 | 84 |
| Дельта | −166 (−69%) | −225 (−73%) |

## Распределение по `identifier` — до/после

Команда: `grep -oP "identifier: \K.*" phpstan-baseline.neon | sort | uniq -c | sort -rn`
(на `0badded` — то же на `git show 0badded:phpstan-baseline.neon`).

| `identifier` | До | После |
|:--|--:|--:|
| `property.notFound` | 203 | 41 |
| `argument.type` | 12 | 6 |
| `nullsafe.neverNull` | 7 | 7 |
| `return.type` | 3 | 3 |
| `larastan.noUnnecessaryCollectionCall` | 2 | 2 |
| `class.notFound` | 2 | 2 |
| `arrayValues.list` | 2 | 2 |
| `instanceof.alwaysTrue` | 0 | 2 |
| `trait.unused` | 1 | 1 |
| `notIdentical.alwaysTrue` | 1 | 1 |
| `method.notFound` | 1 | 1 |
| `method.nonObject` | 1 | 1 |
| `larastan.noEnvCallsOutsideOfConfig` | 1 | 1 |
| `identical.alwaysFalse` | 1 | 1 |
| `booleanOr.rightAlwaysFalse` | 1 | 1 |
| `argument.unresolvableType` | 1 | 0 |
| `assign.propertyType` | 0 | 1 |
| `argument.unknown` | 1 | 1 |
| **Σ (18 identifier'ов)** | **240** | **74** |

Сумма считается той же формой команды с добавленным подсчётом: `grep -oP "identifier: \K.*" phpstan-baseline.neon | wc -l` (после — на текущем дереве; до — `git show 0badded:phpstan-baseline.neon | grep -oP "identifier: \K.*" | wc -l`). Дописывающий строку в эту таблицу обязан сверить получившуюся СУММУ колонки с этой командой, а не только свою строку — иначе повторится расхождение, найденное аудитом P6 (F2).

`instanceof.alwaysTrue`(2)/`assign.propertyType`(1) — НОВЫЕ (0 на `<base>`), появились КАК ПОБОЧНЫЙ
эффект рычага (см. §3.6 таблицы ниже) — не рост неучтённого долга, а более точный анализ на месте,
где раньше свойство было вовсе не типизировано. `argument.unresolvableType`(1 → 0) — класс УШЁЛ в
ноль тем же рычагом `@property`, скрытого долга нет.

**Расхождение с `findings/repo-structure-2026-07-28.md` §12.2** («19 записей» прироста) не
воспроизводится ни этим, ни предыдущим снимком (`findings/inherited-debts-2026-07-29.md` §2.4 даёт
+9/+18 между другими коммитами) — P6.3 считает числа сам на своём дереве, не подгоняя под §12.2
(Implementation Rule 1, D53 Consequences).

## Классификация остатка

Группировка `property.notFound` — по ПРИЧИНЕ внутри identifier (D82: гейт без «мягкой точки», вывод
называется поимённо, а не общей фразой «долг»); остальные identifier — по одной причине на группу.
Все 74 записи baseline закрыты одной из строк ниже; команда, воспроизводящая КАЖДУЮ строку —
`grep -A2 "identifier: <ID>" phpstan-baseline.neon | grep path:` (на актуальном дереве).

| Группа | `identifier` | Записей | Что изменится, чтобы группа исчезла |
|:--|:--|--:|:--|
| Crawling-контур (`Crawler`/`Run`/`Proxy`/`Platform\Registry`/`Platform\StatusMapping`) | `property.notFound` | 19 | Docblock-рычаг НЕ применён намеренно — crawling-контур неприкосновенен планом `2026.07.28-EST-ARCH` (Execution Rules §2); группа исчезает отдельным item'ом вне этого плана, тем же рычагом (`@property` по факту миграций `Crawler`/`Platform`) |
| `Procurement`/`ProcurementRevision` (закупка — общий домен обнаружения/скрининга, не AI-подомен) | `property.notFound` | 13 | Вне Scope P6.3 (Scope Included ограничен «моделями AI-контура и домена `Llm`»); снимается отдельным item'ом тем же рычагом, когда домен `Procurement`-ядра будет в периметре плана |
| Кросс-доменные `$uuid` (`Organization`, `Award`, `Protocol`, `Platform`) | `property.notFound` | 4 | Вне AI-контура/`Llm`; тот же рычаг (`@property string $uuid`) применим, но не предмет этого item'а |
| `Illuminate\Eloquent\Model` — relation-цепочка теряет конкретный класс (остаток после докблоков) | `property.notFound` | 3 | Три оставшихся хопа лежат в `Eis`/`Platform\Registry`-цепочках (`EisCsvRowMapper`, `EisCsvUrlBuilder`, `BacklogRescanProcessor`) — crawling-контур, та же граница, что строка 1 |
| `LlmCall::$calls` — вычисляемый alias агрегатного запроса | `property.notFound` | 1 | НЕ реальная колонка (`selectRaw('count(*) as calls')` на билдере, типизированном как `LlmCall`) — докблок исказил бы схему (Implementation Rule 4 требует «отражающие ФАКТИЧЕСКУЮ схему»); снимается точным типом результата агрегатного запроса на call-site, не докблоком модели |
| `Estimate\Section::$section_number` — **живой дефект, не ложное срабатывание** | `property.notFound` | 1 | См. §4 «Найденный вживую дефект» — колонка удалена миграцией D100, `EstimateStoreRepository::findOrCreateSection()` продолжает её читать/писать; докблок НЕ заводится (реальной колонки нет), группа снимается правкой репозитория отдельным item'ом |
| `instanceof Collection<string, mixed>` избыточен после типизации `@property` | `instanceof.alwaysTrue` | 2 | Известное ограничение Larastan: после объявления `@property Collection<string, mixed>` инстанс-проверка того же типа всегда истинна статически — либо убрать проверку в прикладном коде (вне Scope Excluded — не докблок), либо оставить как defensive-код на рантайм-несоответствие каста |
| Присвоение `array{…}` в `Analysis::$result` (типизирован `Collection<string, mixed>`) | `assign.propertyType` | 1 | Larastan не понимает, что `AsCollection`-каст Eloquent принимает `array` на входе и хранит `Collection` на выходе — типизация `@property` через каст статически видит только тип ПОСЛЕ каста; ложное срабатывание конкретно для `AsCollection`-полей, известное ограничение анализатора, не дефект кода |
| `?->` там, где Larastan (после более точных типов) видит невозможность `null` | `nullsafe.neverNull` | 7 | Защитное программирование (nullsafe как страховка от будущих nullable-полей) — безопасно, не поведенческий дефект; снимается заменой `?->` на `->` там, где убеждение подтверждено, вне Scope этого item'а (правка прикладного кода) |
| Сигнатуры методов, ожидающие конкретный класс (`DocumentVersion`/`Analysis`/…), получают `Model\|null` в оставшихся relation-цепочках | `argument.type` | 6 | Тот же корень, что «`Model` теряет класс» выше — остаток внутри `Eis`/`Registry`-цепочек, crawling-контур |
| `Collection<T>`-generic метода уже частично сужен (`return.type`) | `return.type` | 3 | `resolveClassifiers()`/`implicitHitStats()`/`loadResults()` объявляют более узкий generic-параметр `Collection<K,V>`, чем фактически строит тело (`pluck`/`map` на смешанном источнике) — типизация тела метода, вне Scope (правка сигнатуры/тела, не докблок модели) |
| `->pluck()` мог бы уйти на уровень запроса | `larastan.noUnnecessaryCollectionCall` | 2 | Perf-совет Larastan (уже загруженная коллекция вместо query-level `pluck`), не ошибка поведения; вне Scope |
| `Organization`↔`ProcurementParty` взаимная FQN-ссылка не резолвится при анализе одного файла | `class.notFound` | 2 | Циклическая ссылка в relation-методах (`hasMany(related: 'App\Models\Procurement\Organization')`/обратно) — опечатка в namespace или незавершённый рефакторинг domain `Organization`, вне AI-контура и вне Scope P6.3 |
| `array_values()` на уже нормализованном `list<...>` | `arrayValues.list` | 2 | No-op вызов (аргумент уже list) — safe, косметика прикладного кода, вне Scope |
| `GracefullyStoppable` — трейт нулевого использования в анализируемом `paths: [app]` | `trait.unused` | 1 | Либо мёртвый код, либо используется файлом вне `paths` (например, консольным раннером на другом уровне) — требует точечной проверки вне Scope P6.3 |
| Filament `IconColumn::boolean()` не принимает именованный параметр `$state` в установленной версии | `argument.unknown` | 1 | Несовпадение сигнатуры стороннего пакета (Filament) с кодом вызова — не относится к AI-контуру/статическому долгу этого item'а |
| `env()` вне `config/*.php` в `OpenAiCompatibleProvider` | `larastan.noEnvCallsOutsideOfConfig` | 1 | Laravel best-practice предупреждение (значение станет `null` при `config:cache`) — реальный, но НЕ новый риск (провайдер уже в проде под этим паттерном); правка — вынос в `config/ai.php`, отдельный item, не докблок |
| `Provider::cache()` не объявлен в интерфейсе пакета `prism-php/prism` в установленной версии | `method.notFound` | 1 | Несовпадение версии контракта стороннего SDK — не относится к схеме/докблокам |
| Три статически недостижимые defensive-ветки (`identical.alwaysFalse`/`notIdentical.alwaysTrue`/`booleanOr.rightAlwaysFalse`) | — | 3 | Защитные проверки на типы, которые Larastan (после уточнения) считает невозможными — безопасны, не дефект поведения; вне Scope (правка тела метода) |
| `EstimateToContractMapper` вызывает `->get()` на значении, суженном до `array` | `method.nonObject` | 1 | Метод принимает `array\|Collection` и различает их веткой `is_array()` в рантайме — Larastan не видит сужения на этом пути; вне Scope (правка тела метода) |

Итого по строкам: 19+13+4+3+1+1+2+1+7+6+3+2+2+2+1+1+1+1+3+1 = **74** записи (совпадает со счётчиком выше).

## Найденный вживую дефект (не чинится этим item'ом)

`app/Repositories/Procurement/Analyzers/Estimate/EstimateStoreRepository.php::findOrCreateSection()`
читает и пишет `Section::$section_number`. Миграция `2026_07_04_100034_create_prc_estimate_sections_table.php`
(комментарий на строке 33) явно удаляет эту колонку решением D100 — колонки `section_number` в
`prc_estimate_sections` НЕТ. `Section::$fillable` её тоже не содержит, поэтому запись через
`firstOrCreate()`/`update()` молча ОТБРАСЫВАЕТСЯ mass-assignment guard'ом (SQL-ошибки нет), а
сравнение `$section->section_number !== $sectionNumber` ВСЕГДА истинно при непустом `$sectionNumber`
(магический геттер отдаёт `null` — атрибута не существует), из-за чего `update()` вызывается на
КАЖДОМ резолве раздела без необходимости. Функционально это мёртвый, безвредный, но вводящий в
заблуждение код (комментарий в коде подразумевает трекинг номера раздела, которого фактически нет).

Это НАСТОЯЩАЯ ошибка (Implementation Rule 7 P6.3), не ложное срабатывание анализатора — P6.3 её НЕ
чинит (правка `EstimateStoreRepository.php` вне `Files` этого item'а) и НЕ гасит докблоком
(колонки не существует — докблок был бы ложью о схеме). Требуется отдельный item: либо удалить мёртвый
код в репозитории, либо (если трекинг номера раздела всё же нужен) — новая миграция, добавляющая
колонку обратно, с D-записью, отменяющей D100.

## Что делать, когда phpstan краснеет на твоей правке

1. Прогони `docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G` (или
  `docker exec agelto_app …` — см. workaround в `handoff.md` плана, если голый `docker compose`
  падает на `MINIO_ROOT_PASSWORD`).
2. Новая ошибка — НЕ баг твоего item'а по построению (например legacy-код, тронутый переездом файла)?
   Проверь, снимается ли она легальным рычагом (`@property`/`@property-read`, отражающие реальную
   схему/связь) — если да, добавь докблок и перегенерируй baseline целиком
   (`--generate-baseline`, НЕ ручная правка файла).
3. Рычаг неприменим (ошибка про прикладной код, а не про недостающий тип) — почини код в рамках
   Scope своего item'а.
4. Ни то ни другое не подходит (чужой код, вне твоего Scope) — заведи D-запись в плане, объясняющую,
   почему запись остаётся, и допиши строку в таблицу «Классификация остатка» этого документа тем же
   форматом (группа · identifier · записей · что изменит группу).
5. Baseline регенерируется ЦЕЛИКОМ (`--generate-baseline`) — ручная правка отдельных записей
   запрещена (Implementation Rule 3 P6.3).
6. Запрещено: `@phpstan-ignore` точечно, `excludePaths`, понижение `level`, отключение
   `reportUnmatchedIgnoredErrors` — любой из них превращает baseline в глушилку, а не в границу
   известного долга.

## См. также

- Скилл `static-analysis` — общий гайд по Pint/PHPStan/Rector в проекте.
- `.claude/rules/agelto-reviewer-overrides.md` — ось ревью «baseline не растёт».
- `plans/archive/2026.07.28-EST-ARCH/plan.md` §5 D53 — граница «снимается / объясняется» для этого item'а.
- `plans/archive/2026.07.28-EST-ARCH/findings/inherited-debts-2026-07-29.md` §2 — исходный разбор корня
  `property.notFound` и механического рычага.
