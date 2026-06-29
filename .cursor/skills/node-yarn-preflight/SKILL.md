---
name: node-yarn-preflight
description: "Проверка доступности Node/yarn на хосте перед frontend-командами и git hooks."
---

# Node/yarn Preflight

Используйте перед хостовыми командами, где нужен `yarn` или `node`:

- frontend-команды (`yarn lint`, `yarn build`, `yarn dev`)
- локальные скрипты из `package.json`
- git hooks, если они вызывают yarn

## Порядок проверки

1. Проверьте `yarn --version`.
2. Если `yarn` не найден: `corepack enable` или `npm install -g yarn@1.22.22`.
3. Повторно проверьте `yarn --version`.
4. Если yarn недоступен — остановитесь и сообщите блокер.

## В Docker

Frontend-команды выполняются через Makefile (`make frontend-dev`, `make yarn ARGS="..."`) — yarn уже в образе `agelto/app:latest`.
