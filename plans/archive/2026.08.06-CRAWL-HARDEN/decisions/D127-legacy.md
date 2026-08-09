---
id: D127
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Fast path D126 применён внутри P5.7 к единственной новой красной дельте полного suite: stale-ожидание `GeminiProxyRoutingTest` сравнивало URL с сырыми credentials после введённого ранее percent-encoding.** Тест исправлен inline, продуктовый LLM/proxy-код не менялся; файл находится вне исходного `Files`, поэтому P5.7 честно закрывается 🟠. **Consequences:** полный suite возвращён к именованному baseline D112; отдельный design/fix-item не создаётся, а факт блока остаётся в D-логе, journal и Completion Notes.

## Почему

Ошибка локальна, решение однозначно задано действующим контрактом `ProxyLease::httpOptions()` и уже покрывается самим тестом; новая итерация не добавила бы проектного знания. `RAG:— (repo-grounded: tests/Feature/Llm/GeminiProxyRoutingTest.php; tests/Unit/Crawler/ProxyLeaseTest.php; phases/P5.md P5.7 Completion Notes)`
