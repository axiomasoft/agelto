---
id: D17
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**UI выбора ОКПД2 — сгруппированный поисковый мультиселект** (`Select::multiple()->searchable()` с группировкой по родительскому узлу), а не собственный древовидный виджет и не сторонний плагин. Оба сценария владельца ложатся на одно поле: «маска» = выбран узел верхнего уровня, «конкретные коды» = выбрано несколько узлов ниже — форма запроса к площадке одна и та же (D9). Закрывает развилку № 8. **Consequences:** буквального «дерева с раскрывающимися узлами, как на ЕИС» в админке не будет — при большой карте оператор ищет узел поиском, а не разворачивает ветки; если владелец позже захочет именно виджет, это отдельный item с собственным JS-компонентом

## Почему

Решение владельца 2026-08-06 (`brief/01-refinements.md`). Внешний факт проверен: у Filament 5 нет штатного tree-компонента формы; `promethys/checkbox-tree` документирован только под Filament 3.x; `solution-forest/filament-tree` и `openplain/filament-tree-view` — страницы управления иерархией, а не поле формы. `RAG:✅ 2026-08-06 (perplexity-web «Filament v4/v5 hierarchical tree checkbox form field plugins»; filamentphp.com/plugins/promethys-checkbox-tree, packagist openplain/filament-tree-view)`. Группировка опций есть только у `Select`: `RAG:✅ 2026-08-06 (laravel-boost search-docs, filament/filament@5.x, forms/03-select.md «Grouping options»)`
