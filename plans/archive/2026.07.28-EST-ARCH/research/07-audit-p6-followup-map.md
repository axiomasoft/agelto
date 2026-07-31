# 07 — Карта носителей находок аудита P6 (заход `plan-design P7`, 2026-07-31)

**Вход (слой 1/отчёт аудита):** `phases/P6.md` → блок `## Audit P6 — 2026-07-31` (вердикт
ATTENTION, находки F1–F6, автор — `plan-audit` opus/xhigh).
**Якорь дерева:** `afcdaa6` (`git log --oneline -1`); код идентичен `72a4ff3` (item-коммит P6.3).
**Что делает этот синтез:** каждой находке аудита назначает НОСИТЕЛЯ — item, D-запись или правку
секции плана — и печатает команду, которой факт находки перепроверен ЭТИМ заходом (аудит не
принимается на веру: `plan-protocol` §16 требует восстановимой цепочки «сырьё → синтез → решение»).
Тот же приём и та же форма, что `research/04-audit-p3-followup-map.md` для находок аудита P3.

## 1. Таблица носителей

| Находка | Класс | Носитель | Перепроверка факта этим заходом (на `afcdaa6`) |
|:--|:--|:--|:--|
| **F1** (Major, PROMOTE-CANDIDATE) — дифф P6.1 вне `Files`: три тестовых файла, каждый затребован СОБСТВЕННОЙ Validation того же item'а | правило формы ТЗ (повторяющийся класс: P3.3, P6.1) | **D140** — строка в `## 2. Execution Rules` (уточнённая форма, два явных исключения) + PROMOTE-CANDIDATE в `plan-protocol`, вынесен владельцу (репозиторий скилла — вне периметра этого плана) | `sed -n '206,213p' phases/P6.md` — три файла названы Known Deviations 1 дословно; `grep -n "JsonSchemaGeneratorTest\|CoreKnowsNoDocumentTypeTest\|PromptComposerTest" phases/P6.md` в поле `Files` P6.1 (`:78`) — ноль вхождений. Свип формы по ЧЕТЫРЁМ уже детализированным items P7 (см. §4) — новых нарушений класса не найдено |
| **F2** (Major) — таблица «Распределение по `identifier`» не воспроизводится своей же командой: пропущена строка `argument.unresolvableType` (1 → 0), сумма колонки «До» = 239 при 240 записях baseline | опубликованное число в `living`-документе | новый item **P7.6** (+ **D138**) | перепрогон обеих команд — §3 ниже; сумма колонки «До» таблицы (`docs/03_Dev/static-analysis.md:65-81`, 17 строк) = **239**, `git show 0badded:phpstan-baseline.neon \| grep -c "message:"` = **240** |
| **F3** (Major) — нормативная спека `08-estimate-extraction.md:103` требует оба словаря для ЧЕТЫРЁХ шагов, код объявляет их только для `extraction:rows`; у расхождения нет владельца ни в одном item'е | контракт спеки ↔ факт кода | решение владельца **D137** (исход «а»: §2 приводится к факту, расширение — измеряемый вопрос **OQ-18**) + новый item-носитель **P7.5** | `sed -n 103p docs/specs/v2/08-estimate-extraction.md` — утверждение на месте; `sed -n '29,35p' app/Services/Procurement/Analyzers/Estimate/EstimateStepDictionaries.php` → `match`: `ExtractionRows => [EstimateHeaderMap::class]`, `default => []`; докблок класса (`:19-23`) сам объясняет, что `ResourceType` приходит в шаг строк ТРАНЗИТИВНО (`RowsChunkResponse → EstimateRow → RowResource`), то есть в замыкания passport/totals/discovery не входит вовсе. `grep -n "§2\|§1–§7" phases/P7.md` в `Scope Excluded` P7.3 (`:148`) — правка §1–§7 сверх перекрёстных ссылок и факт-класса имён item'у ЗАПРЕЩЕНА, то есть P7.3 §2 закрыть конституционно не может |
| **F4** (Minor) — P6.3 закрыт `🟢` с `Known Deviations: —`, при том что его же Completion Notes признают широкое прочтение границы Scope Included | бухгалтерия фазы | правка `Known Deviations` P6.3 + агрегата `## Phase Handoff` P6 ЭТИМ заходом; правило и провенанс — **D139** (статус `🟢` сохраняется решением владельца, обоснование в D-записи) | `sed -n 439p phases/P6.md` → `**Known Deviations:** —`; `grep -n "прочитана широко" phases/P6.md` → `:410` (внутри Completion Notes); `grep -n "P6.3 — отклонений нет" phases/P6.md` → `:461` (агрегат `## Phase Handoff`) |
| **F5** (Nit) — единственный числовой бюджет сюита (`PromptCompositionTest:44-50`, 12000 символов) меряет rules-часть (7374), а боевая системная часть шага строк — 26202 | интуиция читателя теста, не приёмка | носителя НЕТ намеренно: аудит сам квалифицирует находку как «дефекта в тесте нет»; прирост измерен и опубликован (P6.1 Implementation Rule 7), а переписывать бюджет БЕЗ пере-замера F1 (D52, вне плана) значит заводить второй носитель числа. Фиксируется открытым риском в `handoff.md` | `sed -n '44,50p' tests/Feature/Ai/PromptCompositionTest.php` — гейт на `compose($step)` без корней блока схем; число 26202 — Completion Notes P6.1 |
| **F6** (Nit) — форма Validation 6 P6.1 засчитывает строку делегации за вхождение правила | форма гейта закрытого item'а | носителя НЕТ намеренно: гейт закрытого item'а не перепрогоняется (тот же приём, что D116 — «правило действует на будущие переименования»); наследование формы предотвращено тем, что ни один оставшийся item плана такого гейта не несёт | `grep -n "встречается в ОДНОМ файле\|в ОДНОМ файле" phases/P*.md` → единственное вхождение — `phases/P6.md:98` (Validation 6 P6.1, item закрыт) |

## 2. Почему носители легли в фазу P7, а не в реопен P6 и не в новую фазу

Три варианта, взвешенные при выборе (провенанс — **D137**/**D139**):

- **Реопен P6** — исключён и владельцем захода, и самим аудитом: «дефектов в самих Deliverables P6
  не найдено», продукт фазы цел, все три долга закрыты рабочим кодом, ключевые числа
  воспроизведены до знака. F1/F6 — дефекты ФОРМЫ ТЗ, унаследованной исполнителем; F3 — дыра
  ВЛАДЕНИЯ, которой в закрытых `Scope` не было достижимого зелёного исхода (класс D89 «в»); F2/F4 —
  бухгалтерия. Реопен потребовал бы четырёх шагов протокола (`plan-protocol` §3) и инвалидации
  `## Phase Handoff` ради правок, ни одна из которых не переисполняет код.
- **Новая фаза P8** — отложила бы правку нормативной спеки за конец плана: §2
  `08-estimate-extraction.md` читает P7.3 (ревизия §8) и будет читать исполнитель второго типа
  документа (D14). Спека, расходящаяся с кодом, дороже отсутствующей — это утверждение самого
  плана (`Why` P7.3), и откладывать её починку за замыкающую фазу значит противоречить ему.
- **Два item'а в фазе P7 (выбрано)** — фаза `⬜ Not started`, порядок ещё не начат; оба новых
  item'а документарные, дешёвые и независимы друг от друга и от остальных четырёх. Плата названа
  честно: `Scope Included` фазы расширяется за пределы «датасет + документация» ровно на два
  remediation-item'а, `Границы` контракт-блока получают явный carve-out, а порядок исполнения
  фазы получает жёсткое требование «B4 ДО P7.3» (P7.5 правит §2 того же файла, который P7.3
  ревизует целиком).

## 3. Перепрогон таблицы «Распределение по `identifier`» (вход item'а P7.6)

Обе команды — те, что напечатаны в самом документе (`docs/03_Dev/static-analysis.md` §«Распределение»).

```
$ git show 0badded:phpstan-baseline.neon | grep -oP "identifier: \K.*" | sort | uniq -c | sort -rn
    203 property.notFound
     12 argument.type
      7 nullsafe.neverNull
      3 return.type
      2 larastan.noUnnecessaryCollectionCall
      2 class.notFound
      2 arrayValues.list
      1 trait.unused
      1 notIdentical.alwaysTrue
      1 method.notFound
      1 method.nonObject
      1 larastan.noEnvCallsOutsideOfConfig
      1 identical.alwaysFalse
      1 booleanOr.rightAlwaysFalse
      1 argument.unresolvableType      ← в таблице документа ОТСУТСТВУЕТ
      1 argument.unknown
                                        Σ = 240 (16 identifier'ов)

$ grep -oP "identifier: \K.*" phpstan-baseline.neon | sort | uniq -c | sort -rn
     41 property.notFound
      7 nullsafe.neverNull
      6 argument.type
      3 return.type
      2 larastan.noUnnecessaryCollectionCall
      2 instanceof.alwaysTrue
      2 class.notFound
      2 arrayValues.list
      1 trait.unused
      1 notIdentical.alwaysTrue
      1 method.notFound
      1 method.nonObject
      1 larastan.noEnvCallsOutsideOfConfig
      1 identical.alwaysFalse
      1 booleanOr.rightAlwaysFalse
      1 assign.propertyType
      1 argument.unknown
                                        Σ = 74 (17 identifier'ов)
```

**Итог сверки.** Объединение множеств — **18** identifier'ов; в таблице документа сегодня **17**
строк. Пропущена ровно одна — `argument.unresolvableType` (**1 → 0**): класс УШЁЛ в ноль рычагом
`@property`, скрытого долга нет. Сумма колонки «До» в документе = **239**, фактических записей
baseline на `0badded` = **240** (число 240 в §«Числа» того же документа верное — расходится именно
таблица). Сумма колонки «После» = **74** — сходится. То есть правка ровно одна: недостающая строка
плюс проверяемый контроль суммы, чтобы дефект не воспроизвёлся у следующего дописывающего.

## 4. Свип формы F1 по четырём уже детализированным items P7 (вход правила D140)

Правило бесполезно, если не применено к тому, что проектируется прямо сейчас. Проверено: у каждого
item'а P7 — совпадает ли перечень `Files` с файлами, которые называет его СОБСТВЕННАЯ `Validation`.

| Item | Файл, названный `Validation` | В `Files`? | Вердикт |
|:--|:--|:--|:--|
| P7.1 | `tests/Feature/Ai/DatasetExportTest.php` (V3: «десять обязательных кейсов присутствуют») | да | чисто |
| P7.1 | `tests/Feature/Ai/GoldExampleRecorderTest.php` (V4) | да | чисто |
| P7.1 | тест `DatasetImport` (V5: «зелёный **БЕЗ правок**») | нет | **правильно** — исключение (а) D140: гейт требует НЕИЗМЕННОСТИ |
| P7.1 | `phpstan-baseline.neon` (V7: «дифф пуст») | нет | **правильно** — исключение (а) D140 |
| P7.2 | `tests/Feature/Ai/{EstimateGoldImportTest,FixtureRunServiceTest}.php` (V2–V4, новый тест эквивалентности) | да | чисто |
| P7.3 | `docs/specs/v2/**` — периметр гейтов V2/V3 (`prc_document_types`/`has_extractor`, `prc_ai_*`) шире `Files` | покрыт | **чисто, проверено фактом:** на `afcdaa6` вхождения лежат ровно в файлах `Files` — `01-database.md` (4 + 8), `03-document-pipeline.md` (1 + 0), `04-llm-providers.md` (0 + 9), `05-admin-observability.md` (0 + 3), `06-roadmap.md` (0 + 2), `07-carryover-from-v1.md` (0 + 5); `00-overview.md` и `02-crawling.md` — **0/0**, то есть зелёный исход достижим в границах `Files` |
| P7.4 | `CLAUDE.md`, `.ai/guidelines/navigation.md`, ADR, файлы `docs/04_Operators/**` | да | чисто |
| P7.4 | `plans/2026.07.28-EST-ARCH/phases/P[1-7].md` (V5: строки `Pending Work` про хаб вычеркнуты) | нет | **правильно** — исключение (б) D140: бухгалтерия отдельным коммитом (`plan-protocol` §8; аудит P6 подтвердил, что ни один item-коммит плана файлов плана не несёт) |

Вывод: буквальная форма находки F1 («`Files` включает КАЖДЫЙ файл, названный `Validation`») дала бы
ЛОЖНЫЕ срабатывания на четырёх строках выше и предписала бы внести в `Files` файлы, которые item не
правит, — раздув периметр гейта стиля (`pint --test <Files>`, D115) и радиус отката. Поэтому в
`## 2. Execution Rules` уезжает уточнённая форма с двумя явными исключениями (**D140**), а буквальная
форма остаётся в тексте находки как её исходная формулировка.
