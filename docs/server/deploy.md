# Процесс деплоя

Инструкция для повседневного обновления приложения на production-сервере.

- Подготовка хоста (первый раз) — [setup.md](./setup.md)
- Docker Compose, volumes, secrets — [docker-prod.md](./docker-prod.md)

## 1. Подключение к серверу

```bash
ssh deploy@<IP_или_домен>
cd /var/www/app   # каталог проекта (PROJECT_DIR)
```

## 2. Получение изменений

```bash
git pull
```

После `git pull` смотрим, что изменилось, и выбираем нужный сценарий из таблицы ниже.

---

## 3. Сценарии деплоя

### Быстрая шпаргалка

| Что изменилось | Команда |
|----------------|---------|
| Только PHP / конфиги / шаблоны | `make prod:deploy:code` |
| `composer.lock` (новые пакеты) | `make prod:deploy:runtime` |
| Новые миграции БД | `make prod:deploy:runtime` |
| Только фронт (Vue/Vite, без PHP) | `make prod:deploy:frontend` |
| Изменился `.env` на сервере | обновить `.env` → `make prod:deploy:code` |
| Изменился `Dockerfile` / PHP-расширения | `make prod:deploy:image` |
| Первый деплой на новом сервере | `make prod:deploy:image` |

---

### `make prod:deploy:code` — обычное обновление кода

Используется в большинстве случаев: изменился PHP, Blade/Inertia, маршруты, конфиги.

```bash
git pull
make prod:deploy:code
```

Что происходит внутри:
1. `php artisan optimize:clear` — очистка кэшей
2. `php artisan config:cache` — кэш конфигурации
3. Перезапуск `app`, `horizon`, `scheduler`, `reverb`
4. Ожидание готовности `/up` (до 120 секунд)

---

### `make prod:deploy:runtime` — полное обновление

Используется когда: добавились новые Composer-пакеты, появились миграции, или нужен полный
пересбор (первый деплой, после долгого перерыва).

```bash
git pull
make prod:deploy:runtime
```

Что происходит внутри:
1. `composer install --no-dev --optimize-autoloader`
2. `yarn install` + `yarn build` (Vite)
3. `php artisan migrate --force`
4. `php artisan config:cache`
5. Перезапуск всех сервисов с ожиданием готовности

> **Включает `prod:deploy:php-deps` и `prod:deploy:frontend`** — не нужно запускать их отдельно.

---

### `make prod:deploy:frontend` — только фронт

Используется когда изменились только Vue-компоненты или Tailwind, PHP не трогали.

```bash
git pull
make prod:deploy:frontend
```

Что происходит: `yarn install` + `yarn build` → volume `frontend_build` обновляется.

> После смены маршрутов в PHP нужно также `make prod:deploy:code` (для `config:cache` и Wayfinder-типов).

---

### `make prod:deploy:php-deps` — только Composer

Обновление PHP-зависимостей без пересборки фронта:

```bash
git pull
make prod:deploy:php-deps
make prod:deploy:code
```

---

### Обновление `.env` на сервере

Переменные окружения (новый ключ API, смена настроек):

```bash
nano .env   # внести изменения
make prod:deploy:code
```

Если изменился `APP_KEY` или `DB_PASSWORD` — если используются Docker Swarm Secrets, обновить
secret (см. [docker-prod.md](./docker-prod.md#обновление-secret)), затем перезапустить сервисы.

---

### `make prod:deploy:image` — смена PHP-образа или расширений

Используется когда изменился `docker/php/Dockerfile` (обновили PHP, добавили расширение).

```bash
git pull
make prod:deploy:image
```

Пересборка образа занимает несколько минут. Включает:
1. Очистку dev-артефактов (`prod-pre-clean`)
2. Пересборку образа
3. Полный деплой runtime

---

### Перезапуск воркеров вручную

Если нужно перезапустить Horizon без полного деплоя (например, после изменения Job-класса):

```bash
make prod:workers:restart
```

Scheduler:

```bash
docker compose -f docker-compose.prod.yml restart scheduler
```

---

## 4. Проверка после деплоя

```bash
make prod:doctor
curl -fsS "http://127.0.0.1:${APP_PORT:-8080}/up" && echo OK
```

`make prod:doctor` проверяет: состояние сервисов, доступность `/up`, наличие `public/build`
и `vendor`.

Проверить логи при проблемах:

```bash
make prod:logs              # логи app
make prod:logs SERVICE=horizon
docker logs agelto_app --tail 50
```

---

## 5. Откат

### Быстрый откат кода

```bash
git log --oneline -10       # найти нужный коммит
git revert <commit_hash>    # создаёт новый коммит, не переписывает историю
make prod:deploy:code
```

Предпочтительнее `git revert` — не переписывает историю. `git checkout <hash>` — только если
`revert` невозможен.

### Откат миграции

```bash
make prod:artisan ARGS="migrate:rollback"
# или до конкретного батча:
make prod:artisan ARGS="migrate:rollback --step=1"
```

> Всегда делайте дамп БД перед деплоем с миграциями, которые удаляют столбцы или таблицы.

---

## Типичный рабочий процесс

```bash
# Обычное обновление после code review и merge в main:
ssh deploy@сервер
cd /var/www/app
git pull
make prod:deploy:code       # или prod:deploy:runtime если были composer/миграции
make prod:doctor && curl -fsS "http://127.0.0.1:${APP_PORT:-8080}/up" && echo OK
```
