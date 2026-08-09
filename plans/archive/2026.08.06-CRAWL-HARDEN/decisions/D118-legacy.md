---
id: D118
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.1 не удаляет `PoliteThrottle` и `crawler.throttle.*`: она мигрирует четыре HTTP-места на `CrawlSettingsResolver`, удаляет только `crawler.http.*` и возвращается из `🔴 Blocked` в `⬜ Not started`. `Okpd2NodeSynchronizer` — служебный запрос без профиля — использует `CrawlStage::Listing` с `crawler: null`, то есть берёт площадку/конфиг и никогда не наследует `CrawlerRuntimeOptions`. Полный переход всех вызовов `PoliteThrottle` на общий budget, удаление класса и последний grep-гейт остаются исключительно P4.4.**

## Почему

Исполнитель P4.1 честно остановился: его старый validation требовал убрать троттлер, хотя Scope прямо откладывал это на P4.4. Проверка дерева одновременно нашла четвёртый HTTP-читатель `crawler.http.*` и четвёртый потребитель троттлера — `Okpd2NodeSynchronizer`; без его явной роли удаление legacy-ключей либо сломает sync, либо оставит неучтённый обход общего бюджета. `RAG:— (repo-grounded: findings/P4-settings-readers-2026-08-08.md; phases/P4.md P4.1/P4.4; app/Services/Procurement/Eis/Okpd2NodeSynchronizer.php)`
