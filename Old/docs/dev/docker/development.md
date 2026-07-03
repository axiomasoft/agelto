# Локальная разработка (Docker)

Запуск локального стека из корня репозитория.

## Быстрый старт

```bash
cp .env.example .env
cp .env.docker.example .env.docker
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
make frontend-dev
```

## Полезно

- Не используйте `docker compose down -v`, если нужно сохранить БД.
- Для фронта используйте сервис `vite` или `make frontend-dev`.
- Проверяйте состояние через `docker compose ps` или `make ps`.
- Документация: `make docs-up` (порт `DOCS_PORT`, по умолчанию 4173).

## WSL2 / bind-mount (права на файлы)

Если `make frontend-dev` падает с `Permission denied` на `resources/js/wayfinder` или `node_modules`:

1. В `.env.docker` задайте `PUID` и `PGID` (`id -u`, `id -g` на хосте).
2. Скопируйте локальный override:

```bash
cp docker-compose.override.example.yml docker-compose.override.yml
```

3. В `docker-compose.override.yml` при необходимости поправьте `user:` у `vite` под свои `PUID`/`PGID`.

См. также [Troubleshooting](/dev/docker/troubleshooting).
