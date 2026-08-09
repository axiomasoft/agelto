---
id: D120
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Ре-дизайн P4.2 снимает escalation без ослабления D38: валидный default `CrawlerFactory` — `use_proxy = false`; явный state `withProxies(Proxy ...$proxies)` сначала создаёт pivot-привязку, затем включает флаг. Реальный тест сидера — `tests/Feature/Seeders/EisSeedersTest.php`; direct-регрессии остаются в трёх тестах своих HTTP-выходов. Эта запись supersedes ошибочную часть D104 о пути и происхождении `CrawlerProfileSeederTest`. **Consequences:** P4.2 расширяет `Files` на фабрику и пять существующих тестовых носителей; все тесты, которым нужен включённый прокси, обязаны выражать привязку явно, поэтому скрытый несохраняемый default больше не маскирует продуктовый инвариант.

## Почему

Фактура из `findings/P4.2-blocked-redesign-2026-08-08.md`: D104 назвала отсутствующий файл, а P4.2 не объявляла обязательную правку фабрики, хотя гейт D38 делает её текущий default несохраняемым. `RAG:— (repo-grounded: findings/P4.2-blocked-redesign-2026-08-08.md; database/factories/Procurement/Crawler/CrawlerFactory.php; tests/Feature/Seeders/EisSeedersTest.php)`
