---
id: D108
date: 2026-08-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Периметр гейта скоупа (P2.2) чинится ДЕФОЛТАМИ ФАБРИК, а не пошту́чной правкой шестнадцати тестовых файлов:** `CrawlerFactory::definition()['filter']` → `new EisSearchFilter(okpd2Codes: ['43'])` вместо `null`, `RegistryFactory::definition()['code']` → `'fz44'` вместо `fake()->randomElement(['fz44','fz223'])`; поштучно правятся ТОЛЬКО пять файлов с ЯВНО заданным фильтром (`CsvSourceMethodTest` 11 мест, `EisCsvUrlBuilderTest` 5, `ArchiveWindowScannerTest` 1, плюс `CrawlerFilterContractTest`/`CrawlerFormTest`) и одна ассерта `EisSeedersTest`. Обе правки фабрик едут ВМЕСТЕ: дефолтный непустой фильтр на случайно выпавшем `fz223` падал бы в `RuntimeException` резолвера, а не в гейт. Отвергнут вариант «только тестовые файлы, фабрики не трогаем»: он оставляет флакость (`config('crawler.filters')` знает один код, а фабрика выдаёт два) и заставляет КАЖДЫЙ будущий тест краулера заново наступать на ту же ловушку. **Consequences:** `Files` P2.2 расширен с двух тестовых файлов до шести плюс две фабрики — item стал заметно шире объявленного при детализации фазы, и это плата за то, что периметр закрыт один раз, а не по одному файлу при каждом падении; пиннинг `RegistryFactory` на `fz44` означает, что тест, которому по смыслу нужен другой реестр, обязан задать код ЯВНО (канон уже применён в трёх местах — `CrawlerFilterContractTest:75`, `StatusMappingSeederTest:60`, `EisCsvRowMapperTest:61`). Решение владельца 2026-08-07 (`AskUserQuestion` захода редизайна P2.2)

## Почему

Вопрос 1 эскалации P2.2. Измерено парным прогоном, а не оценено: гейт даёт +53 падения против baseline `618a2ce6`, и 39 из них — один дефолт `'filter' => null`. Фабрика, отдающая НЕСОХРАНЯЕМУЮ модель, — дефект самой фабрики (её контракт в этом репозитории — «персистентные модели только через фабрики»), поэтому чинить её тестами значит лечить симптом. `RAG:— (repo-grounded: research/11-p2.2-scope-gate-perimeter.md §1, §1.1, §2; artifacts/P2.2-perimeter-baseline.txt, artifacts/P2.2-perimeter-with-diff.txt; database/factories/Procurement/Crawler/CrawlerFactory.php:29; database/factories/Procurement/Platform/RegistryFactory.php:22)`
