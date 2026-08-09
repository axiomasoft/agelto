---
id: D80
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Coupling-поверхность P7 перечислена явно** (`plan-protocol` §5): (1) рендер содержимого золота — ОДИН класс, рождается в P7.1 и потребляется P7.2 (два рендера формы §8.2 разошлись бы молча); (2) `app/Filament/Resources/Procurement/Ai/Fixtures/**` — P7.1 (вкладка, колонка) и P7.3 (ссылки); (3) `app/Enums/Procurement/Estimate/RowQualityReason.php` — P7.4 добавляет значение, и без подписи в том же `label()` витрина смет покажет пустой бейдж; (4) `app/Filament/Resources/Procurement/Estimates/**` — P7.3 (ссылки) и P7.4 (фильтр по причине); (5) `docs/03_Dev/admin-panel.md` — все пять items дописывают свой раздел, P9 сводит. Схемной поверхности с P8 у фазы НЕТ намеренно (D75)

## Почему

`research/08-p7-layers-design.md` §8
