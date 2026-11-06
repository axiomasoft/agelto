---
name: node-yarn-preflight
description: "Проверка Node/pnpm перед frontend-командами Agelto; историческое имя skill сохранено для совместимости ссылок."
---

# Node/pnpm Preflight

Проект использует `packageManager` из `package.json` (`pnpm@9.15.0`) и `pnpm-lock.yaml`.
Имя skill историческое: команды выполняются через pnpm.

1. Перед хостовыми frontend-командами проверь `node --version` и `pnpm --version`.
2. Сверь pnpm с `package.json`, версию Node — с frontend-stage `docker/php/Dockerfile`
   и сервисом `vite` в `docker-compose.yml`.
3. Если инструментов нет, используй существующий frontend-контур Docker; не устанавливай
   глобальный yarn и не меняй lockfile ради запуска.
4. Для воспроизводимой установки используй `pnpm install --frozen-lockfile`, для сборки —
   `pnpm build` / `pnpm documentation:build` согласно активной задаче.

Dev-сервис `vite` устанавливает зависимости и запускает Vite; `make dev:up` управляет текущим
Compose-проектом. Runtime PHP-образ не является frontend-средой. Старые `make yarn` /
`make frontend-dev` и образ `agelto/app:latest` не входят в текущий контракт команд.
