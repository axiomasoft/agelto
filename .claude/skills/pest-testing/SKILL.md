---
name: pest-testing
description: "Написание и правка тестов Pest (Feature/Unit) в Agelto, отладка падений. Активировать при добавлении тестов, изменении поведения под тестами, php artisan test, Pest/PHPUnit."
---

# Pest-тестирование (Agelto)

## Сначала прочитай

1. **`testing-rules`** — изоляция БД `agelto_test` (pgsql, форсится в `phpunit.xml`), `RefreshDatabase`, фабрики.
2. Тип теста:
   - **Feature** (`tests/Feature/`) — HTTP/Inertia-эндпоинты, сценарии доступа, флоу парсинга/crawling, очереди (sync), интеграция Actions/Services с БД.
   - **Unit** (`tests/Unit/`) — чистая логика без БД (парсеры смет, DTO, хелперы, value-объекты).

## Сценарий работы агента

1. Pre-flight: убедись в конфиге тестовой БД (`testing-rules`) — `pgsql` / `agelto_test`, БД создана.
2. Открой изменённые классы и при необходимости их фабрики (`database/factories/`).
3. Запускай только нужные тесты фильтром, а не всю сюиту.

## Ожидания проекта

- По умолчанию Feature-тесты, если нет явной причины ограничиться Unit.
- Тестовые данные — через фабрики моделей.
- Утверждения про **поведение** (HTTP-код, редирект/Inertia-компонент, состояние БД, отправленные
  уведомления, заэнкьюенные Jobs), а не про детали реализации.
- Долгие операции (browser, Gemini, обработка файлов) в тестах — не вызывай реально: мокай сервисы
  или проверяй, что Job/уведомление поставлены в очередь (`Queue::fake()`, `Notification::fake()`,
  `Bus::fake()`).

## Команды

```bash
php artisan test                      # вся сюита (использует agelto_test автоматически)
php artisan test --filter=ИмяТеста    # точечный прогон
./vendor/bin/pest                     # напрямую через Pest
./vendor/bin/pest --filter=ИмяТеста
```

Тесты всегда работают с БД **`agelto_test`** (форсится в `phpunit.xml`), не с боевой из `.env`.

Движок Tia (`--tia`/`--no-tia`) реплеит незатронутые правкой тесты — дневной прогон дешевле полного
сюита; приёмочный прогон item'а/фазы плана — **только `--no-tia`** (D8). `--agent='<php>'` — разовая
проверка в реальном тестовом контексте, не замена постоянному тесту. Детали, флаги, устройство кэша
— `docs/dev/process/testing.md` (разделы «Tia» и «--agent»), правила ревью —
`.claude/rules/agelto-reviewer-overrides.md`.

**Симптом `Running in TIA mode, however TIA as skipped as it needs Needs ext-pcov or Xdebug`** —
графа Tia нет и записать его нечем: PCOV в образе есть, но выключен (`pcov.enabled=0`, zero-cost
для fpm/horizon). Лечится ОДНОРАЗОВОЙ записью графа — командой целиком, без сокращений:

```bash
docker exec agelto_app php -d pcov.enabled=1 -d pcov.directory=/var/www/html \
    ./vendor/bin/pest --tia --fresh --coverage
```

Обе укороченные формы молча НЕ работают, и обе выглядят как успешный прогон: без
`-d pcov.directory` Pest 5 `PcovRestarter` перезапускает процесс со своим `pcov.directory` и
теряет `pcov.enabled=1`; без `--coverage` не поднимается внутренний флаг `Coverage::$coverage`.
Отдельно: `php -d pcov.enabled=1 artisan test` не работает В ПРИНЦИПЕ — `artisan test` порождает
Pest дочерним процессом, `-d` до него не доезжает (наследуется только окружение, не `-d`).
После записи графа PCOV для последующих прогонов не нужен, граф живёт в томе
`app_pest_tia:/var/www/.pest` и переживает пересборку образа.

## Чеклист

- [ ] Выбран корректный тип (Feature по умолчанию; Unit только для логики без БД).
- [ ] Данные через фабрики; тест использует `RefreshDatabase`.
- [ ] Внешние операции (browser/Gemini/файлы) замоканы или проверены через `*::fake()`.
- [ ] Ассерты про поведение, а не реализацию.
- [ ] Прогон зелёный на `agelto_test`, боевая БД не затронута.

## Связанные

- `testing-rules` — безопасность и изоляция тестовой БД.
- `laravel-testing`, `test-strategy`, `test-isolation-guard` — библиотечные практики.
