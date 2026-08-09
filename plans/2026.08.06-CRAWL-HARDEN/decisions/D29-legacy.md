---
id: D29
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**«Режим работы» из брифа кодируется как `prc_crawlers.stop_policy` + enum `RunStopPolicy` (`Default`/`OnlyFresh`), а не как второе поле `mode`.** UI-подпись остаётся владельческой («Режим работы»). Политика легальна только у профилей `mode = fresh` — структурный гейт в `Crawler::saving()`

## Почему

Слово «режим» в проекте занято: `prc_crawlers.mode` — это `CrawlerMode` (`fresh`/`archive`). Второе поле с тем же корнем рядом с первым — путаница на годы; канон проекта требует, чтобы имя раскрывало намерение, а бизнес-язык оставался в UI. `RAG:— (repo-grounded: app/Enums/Procurement/Crawler/CrawlerMode.php; .ai/guidelines/glossary.md)`
