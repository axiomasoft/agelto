# Troubleshooting (Docker)

## Vite / wayfinder: Permission denied

1. Задайте `PUID`/`PGID` в `.env.docker`.
2. Скопируйте `docker-compose.override.example.yml` → `docker-compose.override.yml`.
3. `make frontend-dev-reset`.

## Приложение не открывается

```bash
docker compose ps
docker compose logs -f app
curl -fsS http://127.0.0.1:${APP_PORT:-8080}/up
```

Проверьте, что `APP_URL` в `.env` совпадает с портом из `.env.docker`.

## Reverb не подключается

```bash
docker compose logs -f reverb
docker compose exec app php artisan reverb:install
```

Клиентские переменные: `REVERB_HOST=localhost`, `REVERB_PORT`, `VITE_REVERB_*`.

## Очистка кэшей Laravel

```bash
make clear-cache
# prod:
make prod:clear-cache
```

## Полная пересборка dev-стека

```bash
docker compose down
docker compose up -d --build
```

Не используйте `down -v`, если нужно сохранить данные PostgreSQL.
