---
id: D72
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Coupling-поверхность P6 перечислена явно** (`plan-protocol` §5): (1) `resources/crawler/health/eis-baseline.json` — P5.1 заводит вкладку `supplier-results` со `status: known_broken`, P6.2 обязана перевести её на `measured`, когда парсер заработал; исполнение P6.2 раньше P5.1 легально, но тогда перевод уходит строкой в Pending Work; (2) `app/Models/Procurement/Procurement.php` — P5.2 добавляет колонку исхода разбора, P6.3 — связи, P6.5 — колонку `succeeded_at`; (3) `app/Enums/Procurement/ProcurementEventType.php` — P6.5 добавляет значение, P6.3 его читает; (4) `app/Services/Procurement/ProcurementIngestProcessor.php` — P6.5 добавляет ОДНУ строку вызова оценщика и не трогает ничего больше; (5) `tests/Fixtures/Crawler/Card/*.html` — P1.4 снимает HTML, P5.1 берёт из него якоря, P6.2 промоутит вкладку в фикстуру

## Почему

Видимая coupling-поверхность предупреждает рябь: автор поздней фазы видит, что его правка заденет раннюю, ДО того как напишет её. Прецедент — тот же приём в D47/D58 для P4/P5
