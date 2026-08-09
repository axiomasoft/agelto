---
id: D109
date: 2026-08-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**МИНИМАЛЬНОЕ поле формы `filter.okpd2Codes` переносится из P2.4 в P2.2; P2.4 остаётся ДОВОДКОЙ той же компоненты.** P2.2 заводит `Select::make('filter.okpd2Codes')->multiple()->required()` внутри существующей ветки `EisSearchFilter::class` с ПЛОСКИМИ опциями из `Okpd2NodeReadRepository::selectableNodes()`; P2.4 добавляет `->searchable()`, группировку `groupBy('parent_code')`, два `helperText` (семантика «узел и всё вложенное»; пустота классификаторных колонок CSV), `Placeholder` пустой карты и тест превью URL. `supersedes` границу `Scope Excluded` P2.2/`Scope Included` (1) P2.4 в редакции детализации фазы. Отвергнуты: (а) принять мёртвый интервал — между P2.2 и P2.4 оператор не мог бы ни завести, ни отредактировать НИ ОДИН профиль ЕИС (страница редактирования дегидрирует секцию фильтра из своих компонент и теряет `okpd2Codes` при любом `save()`), а `CrawlerFormTest` пришлось бы лишить единственного теста мутации формы; (б) слить P2.4 в P2.2 целиком — разнородный item на coupling-поверхности, и §3 Routing пришлось бы поднять весь объём до `implementation/high` + `manual`. **Consequences:** P2.2 стал шире на файл `CrawlerForm.php` и два теста формы, а P2.4 — уже: его `Scope Included` (1) переписан из «завести поле» в «довести поле», `Validation` снижена с «≥3 новых теста» до «≥2 сверх заведённых P2.2», и читать P2.4 без этой записи значит увидеть завышенный объём. Выброшенной работы нет: компонента одна, P2.4 её не переписывает. Решение владельца 2026-08-07 (`AskUserQuestion` захода редизайна P2.2)

## Почему

Вопрос 2 эскалации P2.2, назван в ней несущим. Это ровно тот класс, ради которого Implementation Rules P2.2 п. 6 уже требовал чинить сид ТЕМ ЖЕ коммитом («разнос по двум item'ам оставит дерево в нерабочем состоянии») — правило существовало, но применялось только к сиду. Материал для минимального поля готов: P2.1 закрыт, репозиторий узлов отдаёт `selectableNodes()`, площадка достаётся из реестра тем же путём, каким форма уже резолвит класс фильтра. `RAG:— (repo-grounded: research/11-p2.2-scope-gate-perimeter.md §3; app/Filament/Resources/Procurement/Crawler/Crawlers/Schemas/CrawlerForm.php filterFields()/resolveFilterClass(); app/Repositories/Procurement/Platform/Okpd2NodeReadRepository.php selectableNodes())`
