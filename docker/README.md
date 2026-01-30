# Docker Setup для Laravel проекта

Этот проект использует Docker с образами `serversideup/php:8.4-fpm-nginx` для PHP и Nginx, MySQL 8.4 для базы данных, Vite для сборки фронтенда, Laravel Reverb для WebSocket соединений, Task Scheduler для выполнения задач по расписанию, и Queue Worker для обработки очередей.

## Требования

-   Docker
-   Docker Compose

## Быстрый старт

1. Скопируйте `.env.example` в `.env` (если еще не сделано):

```bash
cp .env.example .env
```

2. Настройте переменные окружения в `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=agelto
DB_USERNAME=agelto
DB_PASSWORD=agelto_password
APP_PORT=8080
APP_URL=http://localhost:8080

# Queue Configuration
QUEUE_CONNECTION=database

# Vite Dev Server Configuration
VITE_HOST=0.0.0.0
VITE_PORT=5174
VITE_HMR_HOST=localhost
VITE_HMR_PORT=
VITE_USE_POLLING=true

# Reverb Configuration
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8000
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8000
```

Для генерации ключей Reverb выполните:

```bash
docker compose exec app php artisan reverb:install
```

3. Соберите и запустите контейнеры:

```bash
docker compose up -d --build
```

4. Сгенерируйте ключ приложения (если нужно):

```bash
docker compose exec app php artisan key:generate
```

5. Создайте таблицу для очередей (если используете database драйвер):

```bash
docker compose exec app php artisan queue:table
docker compose exec app php artisan migrate
```

6. Запустите миграции (если AUTORUN_ENABLED=false):

```bash
docker compose exec app php artisan migrate
```

7. Откройте браузер: http://localhost:8080

## Laravel Automations

Проект настроен для использования автоматизаций Laravel от `serversideup/php`. Эти автоматизации выполняются при запуске контейнера.

### Включение автоматизаций

В `.env` установите:

```env
AUTORUN_ENABLED=true
```

### Настройка автоматизаций

Основные переменные окружения для автоматизаций:

-   `AUTORUN_ENABLED` - включить/выключить автоматизации (по умолчанию: false)
-   `AUTORUN_LARAVEL_MIGRATION` - запускать миграции (по умолчанию: true)
-   `AUTORUN_LARAVEL_OPTIMIZE` - оптимизация Laravel (по умолчанию: true)
-   `AUTORUN_LARAVEL_CONFIG_CACHE` - кэширование конфигурации (по умолчанию: true)
-   `AUTORUN_LARAVEL_ROUTE_CACHE` - кэширование маршрутов (по умолчанию: true)
-   `AUTORUN_LARAVEL_VIEW_CACHE` - кэширование представлений (по умолчанию: true)
-   `AUTORUN_LARAVEL_STORAGE_LINK` - создание символической ссылки storage (по умолчанию: true)

Полный список переменных смотрите в `docker compose.yml` или в [документации](https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel/automations).

**Важно:** Автоматизации рекомендуется включать только в production окружении.

## Полезные команды

### Остановка контейнеров

```bash
docker compose down
```

### Просмотр логов

```bash
docker compose logs -f app
docker compose logs -f reverb
docker compose logs -f scheduler
docker compose logs -f queue
docker compose logs -f mysql
```

### Выполнение Artisan команд

```bash
docker compose exec app php artisan [command]
```

### Выполнение Composer команд

```bash
docker compose exec app composer [command]
```

### Выполнение Node.js/Pnpm команд

```bash
docker compose exec app pnpm [command]
```

### Запуск Vite Dev Server

```bash
docker compose exec app pnpm run dev
```

Vite dev server будет доступен на порту, указанном в `VITE_PORT` (по умолчанию: 5174).

Для остановки нажмите `Ctrl+C`.

### Доступ к MySQL

```bash
# Доступ через пользователя agelto
docker compose exec mysql mysql -u agelto -p agelto

# Доступ через root (если нужны расширенные права)
# Пароль берется из DB_ROOT_PASSWORD в .env
DB_ROOT_PASSWORD=$(grep "^DB_ROOT_PASSWORD=" .env | cut -d '=' -f2)
docker compose exec mysql mysql -u root -p"${DB_ROOT_PASSWORD}" agelto
```

**Важно:** Если вы видите предупреждение `The "MYSQL_ROOT_PASSWORD" variable is not set`, нужно пересоздать MySQL контейнер. См. инструкцию в `docker/SERVER_DEPLOY.md` раздел "Пересоздание MySQL контейнера с правильным паролем".

### Восстановление базы данных из дампа

Для восстановления базы данных из SQL дампа есть несколько способов:

**Вариант 1: Импорт через Docker контейнер (рекомендуется)**

```bash
docker compose exec -T mysql mysql -u agelto -p'ваш_пароль_из_env' agelto < /path/to/dump-agelto.sql
```

Где `ваш_пароль_из_env` - это значение переменной `DB_PASSWORD` из `.env` файла.

**Вариант 2: Импорт через root пользователя**

```bash
docker compose exec -T mysql mysql -u root -p'ваш_root_пароль' agelto < /path/to/dump-agelto.sql
```

Где `ваш_root_пароль` - это значение переменной `DB_ROOT_PASSWORD` из `.env` файла (или `root_password` по умолчанию).

**Вариант 3: Импорт с хоста через mysql клиент**

Если MySQL клиент установлен на хосте:

```bash
mysql -u root -p -P ${MYSQL_PORT:-3306} agelto < /path/to/dump-agelto.sql
```

**Важно:**
- При подключении с хоста используйте порт из переменной `MYSQL_PORT` (по умолчанию 3306, но может быть изменен в `.env`)
- При подключении с хоста пользователь `agelto` может не иметь прав доступа - используйте `root`
- Путь к файлу дампа должен быть абсолютным или относительным от текущей директории

**Пример с переменными из .env:**

```bash
# Читаем пароль из .env
DB_PASSWORD=$(grep "^DB_PASSWORD=" .env | cut -d '=' -f2)
docker compose exec -T mysql mysql -u agelto -p"${DB_PASSWORD}" agelto < /path/to/dump-agelto.sql
```

### Пересборка контейнеров

```bash
docker compose up -d --build
```

### Очистка volumes (удалит все данные БД!)

```bash
docker compose down -v
```

## Структура

-   `Dockerfile` - конфигурация образа приложения
-   `docker-compose.yml` - конфигурация всех сервисов (app, reverb, scheduler, queue, mysql)
-   `docker/nginx/default.conf` - конфигурация Nginx
-   `vite.config.js` - конфигурация Vite (порт, HMR, и другие настройки настраиваются через переменные окружения)
-   `.dockerignore` - файлы, исключаемые из образа

**Важно:** Все порты и настройки настраиваются через переменные окружения в `.env` файле.

## Порты

Все порты настраиваются через переменные окружения в `.env`:

-   **APP_PORT** (по умолчанию: 8080) - веб-сервер (Nginx)
-   **VITE_PORT** (по умолчанию: 5174) - Vite Dev Server
-   **REVERB_PORT** (по умолчанию: 8000) - Laravel Reverb (WebSocket)
-   **MYSQL_PORT** (по умолчанию: 3306) - MySQL (внешний доступ)
-   **DB_PORT** (всегда 3306) - MySQL (внутри Docker сети)

### Настройка портов

Измените порты в `.env` файле, если они заняты:

```env
APP_PORT=8081
VITE_PORT=5175
MYSQL_PORT=3307
REVERB_PORT=8001
```

После изменения портов перезапустите контейнеры:

```bash
docker compose down
docker compose up -d
```

## Laravel Reverb

Проект использует Laravel Reverb для WebSocket соединений. Reverb запускается как отдельный контейнер.

### Настройка Reverb

1. **Генерация ключей Reverb:**

```bash
docker compose exec app php artisan reverb:install
```

Эта команда создаст необходимые ключи и добавит их в `.env` файл.

2. **Переменные окружения:**

**Серверные переменные (для Laravel):**

-   `REVERB_APP_ID` - ID приложения Reverb
-   `REVERB_APP_KEY` - публичный ключ для клиентов
-   `REVERB_APP_SECRET` - секретный ключ для сервера
-   `REVERB_HOST` - хост для подключения клиентов (для локальной разработки: `localhost`)
-   `REVERB_PORT` - порт для подключения клиентов (для локальной разработки: `8000`)
-   `REVERB_SCHEME` - схема подключения (`http` для локальной разработки, `https` для production)
-   `REVERB_SERVER_HOST` - хост для сервера Reverb (обычно `0.0.0.0`)
-   `REVERB_SERVER_PORT` - порт для сервера Reverb (обычно `8000`)

**Переменные для фронтенда (Vite):**
Добавьте в `.env` переменные с префиксом `VITE_` для доступа во фронтенде:

```env
VITE_REVERB_APP_KEY=${REVERB_APP_KEY}
VITE_REVERB_HOST=${REVERB_HOST}
VITE_REVERB_PORT=${REVERB_PORT}
VITE_REVERB_SCHEME=${REVERB_SCHEME}
```

После изменения переменных VITE пересоберите фронтенд:

```bash
docker compose exec app pnpm run build
# или для разработки
docker compose exec app pnpm run dev
```

3. **Настройка Broadcasting:**

В `.env` установите:

```env
BROADCAST_CONNECTION=reverb
```

4. **Запуск Reverb:**

Reverb автоматически запускается при старте контейнеров:

```bash
docker compose up -d
```

Для проверки статуса:

```bash
docker compose ps reverb
docker compose logs -f reverb
```

### Подключение клиентов

Для локальной разработки клиенты подключаются напрямую к порту `8000`:

-   Host: `localhost`
-   Port: `8000`
-   Scheme: `http`

В production рекомендуется использовать reverse proxy (например, Traefik или Nginx) для маршрутизации WebSocket трафика.

### Troubleshooting Reverb

**Reverb не запускается:**

```bash
# Проверьте логи
docker compose logs reverb

# Проверьте, что ключи установлены
docker compose exec app php artisan reverb:install
```

**Проблемы с подключением клиентов:**

-   Убедитесь, что `REVERB_HOST` и `REVERB_PORT` правильно настроены
-   Проверьте, что порт 8000 не занят другим процессом
-   Для production используйте reverse proxy с SSL

Подробнее о настройке Reverb: [Laravel Reverb Documentation](https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel/reverb)

## Vite Dev Server

Проект использует Vite для сборки фронтенда. Vite dev server можно запустить внутри Docker контейнера для разработки с Hot Module Replacement (HMR).

### Настройка Vite

Все настройки Vite настраиваются через переменные окружения в `.env`:

```env
# Vite Dev Server Configuration
VITE_HOST=0.0.0.0          # Хост для Vite сервера (0.0.0.0 для Docker)
VITE_PORT=5174             # Порт для Vite dev server
VITE_HMR_HOST=localhost    # Хост для HMR (Hot Module Replacement)
VITE_HMR_PORT=             # Порт для HMR (если пусто, используется VITE_PORT)
VITE_USE_POLLING=true      # Использовать polling для watch (рекомендуется для Docker)
```

### Запуск Vite Dev Server

Для запуска Vite dev server:

```bash
docker compose exec app pnpm run dev
```

Vite будет доступен на `http://localhost:5174` (или на порту, указанном в `VITE_PORT`).

### Сборка для production

Для сборки фронтенда для production:

```bash
docker compose exec app pnpm run build
```

### Настройка портов

Если порт 5174 занят, измените `VITE_PORT` в `.env`:

```env
VITE_PORT=5175
```

После изменения порта перезапустите контейнер app:

```bash
docker compose restart app
```

### Troubleshooting Vite

**Vite недоступен с хоста:**

-   Убедитесь, что порт проброшен в `docker-compose.yml`: `${VITE_PORT:-5174}:${VITE_PORT:-5174}`
-   Проверьте, что `VITE_HOST=0.0.0.0` в `.env`
-   Убедитесь, что порт не занят другим процессом

**HMR не работает:**

-   Убедитесь, что `VITE_HMR_HOST=localhost` в `.env`
-   Проверьте, что `VITE_HMR_PORT` совпадает с `VITE_PORT` (или оставьте пустым)
-   Очистите кэш браузера и выполните жесткую перезагрузку (Ctrl+Shift+R)

**Изменения в файлах не отслеживаются:**

-   Убедитесь, что `VITE_USE_POLLING=true` в `.env` (необходимо для Docker)
-   Проверьте, что volumes правильно смонтированы в `docker-compose.yml`

## Laravel Task Scheduler

Проект использует Laravel Task Scheduler для выполнения задач по расписанию. Scheduler запускается как отдельный контейнер и проверяет расписание каждую минуту.

### Настройка расписания

Расписание задач настраивается в файле `routes/console.php` (Laravel 12+):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('app:clean-temp-files')
    ->dailyAt('01:00')
    ->description('Clean temporary files older than 24 hours');
```

### Запуск Scheduler

Scheduler автоматически запускается при старте контейнеров:

```bash
docker compose up -d
```

Для проверки статуса:

```bash
docker compose ps scheduler
docker compose logs -f scheduler
```

### Просмотр запланированных задач

Для просмотра всех запланированных задач:

```bash
docker compose exec app php artisan schedule:list
```

### Troubleshooting Scheduler

**Scheduler не запускается:**

```bash
# Проверьте логи
docker compose logs scheduler

# Проверьте, что расписание настроено в routes/console.php
docker compose exec app php artisan schedule:list
```

**Задачи не выполняются:**

-   Убедитесь, что контейнер scheduler запущен: `docker compose ps scheduler`
-   Проверьте логи: `docker compose logs -f scheduler`
-   Убедитесь, что расписание настроено правильно в `routes/console.php`

Подробнее о настройке Scheduler: [Laravel Task Scheduler Documentation](https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel/task-scheduler)

## Laravel Queue

Проект использует Laravel Queue для асинхронной обработки задач. Queue Worker запускается как отдельный контейнер.

### Настройка очередей

1. **Выбор драйвера очереди:**

В `.env` установите:

```env
QUEUE_CONNECTION=database
```

Доступные драйверы: `database`, `redis`, `sqs`, `sync` (синхронный, для разработки)

2. **Создание таблицы для очередей (если используете database):**

```bash
docker compose exec app php artisan queue:table
docker compose exec app php artisan migrate
```

3. **Создание Job класса:**

```bash
docker compose exec app php artisan make:job ProcessOrder
```

### Запуск Queue Worker

Queue Worker автоматически запускается при старте контейнеров:

```bash
docker compose up -d
```

Для проверки статуса:

```bash
docker compose ps queue
docker compose logs -f queue
```

### Отправка задач в очередь

В коде используйте:

```php
use App\Jobs\ProcessOrder;

ProcessOrder::dispatch($order);
```

### Мониторинг очередей

**Просмотр неудачных задач:**

```bash
docker compose exec app php artisan queue:failed
```

**Повтор неудачных задач:**

```bash
docker compose exec app php artisan queue:retry all
# или конкретную задачу
docker compose exec app php artisan queue:retry {id}
```

**Очистка неудачных задач:**

```bash
docker compose exec app php artisan queue:flush
```

### Масштабирование Queue Workers

Для увеличения количества воркеров, добавьте дополнительные сервисы в `docker compose.yml`:

```yaml
queue-2:
    # ... копия конфигурации queue
    container_name: agelto_queue_2
```

Или используйте `docker compose up --scale`:

```bash
docker compose up -d --scale queue=3
```

### Graceful Shutdown

Queue Worker использует `SIGTERM` для graceful shutdown, что позволяет завершить обработку текущей задачи перед остановкой контейнера.

### Troubleshooting Queue

**Queue Worker не запускается:**

```bash
# Проверьте логи
docker compose logs queue

# Проверьте, что таблица jobs создана
docker compose exec app php artisan migrate:status
```

**Задачи не обрабатываются:**

-   Убедитесь, что контейнер queue запущен: `docker compose ps queue`
-   Проверьте логи: `docker compose logs -f queue`
-   Убедитесь, что `QUEUE_CONNECTION` правильно настроен в `.env`
-   Проверьте, что таблица `jobs` существует (для database драйвера)

**Задачи остаются в очереди:**

-   Проверьте, что Queue Worker запущен
-   Проверьте логи на наличие ошибок
-   Убедитесь, что задачи отправляются в правильную очередь

Подробнее о настройке Queue: [Laravel Queue Documentation](https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel/queue)

## Troubleshooting

### Проблемы с правами доступа

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### Проблемы с подключением к БД

Убедитесь, что:

1. MySQL контейнер запущен и здоров: `docker compose ps`
2. Переменные окружения в `.env` правильные
3. `DB_HOST=mysql` (имя сервиса в docker compose.yml)

### Проблемы с миграциями

Если миграции не запускаются автоматически:

```bash
docker compose exec app php artisan migrate
```

### Включение debug режима для автоматизаций

```env
AUTORUN_DEBUG=true
```

Это покажет детальную информацию о выполнении автоматизаций.
