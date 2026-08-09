---
id: D10
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Настройки запуска Playwright для госплощадок оформлены вне фаз плана** (инфраструктурное препятствие, прецедент P2.5 плана `2026.07.31-SYS-INTEGRITY`): `scripts/playwright-mcp.sh` получил `browser.contextOptions.ignoreHTTPSErrors`, рецепт канала вынесен в проектный скилл `.claude/skills/eis-recon/SKILL.md`. Послабление ограничено launcher-ом разведки и НЕ распространяется на боевой клиент (`SourceTlsTrust` + `crawler.tls.bundles`, `verify => false` запрещён — D125 плана `2026.07.31-SYS-INTEGRITY`)

## Почему

Прямое требование владельца «это надо в скиллах прописать». Без этого каждая следующая сессия разведки заново упирается в `net::ERR_CERT_AUTHORITY_INVALID` и зависание запросов без RU-прокси — оба симптома уже стоили времени в прошлом плане. `RAG:— (repo-grounded: scripts/playwright-mcp.sh, docker/php/certs/russian-trusted-ca.pem, config/crawler.php `tls.bundles`)`
