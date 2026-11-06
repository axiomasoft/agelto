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

1. Pre-flight: прочитай `testing-rules` и `docs/dev/runbooks/process-test-rig.md`; используй только изолированный Compose project `agelto_core_test`.
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
make core-test-up
make core-quality
make core-test-file TEST_FILE=tests/Feature/Organization/OrganizationResolverTest.php
```

Для доменной сюиты выбери соответствующий `make core-test-*` из `testing-rules`.
Тестовая БД `agelto_test` должна находиться внутри стенда `agelto_core_test`; одного имени БД
недостаточно для изоляции. Не запускай тесты напрямую на хосте или в dev-контейнере `app`.
Не используй legacy-команды записи TIA-графа через `docker exec agelto_app`: текущая проверка
выполняется только через изолированный rig. Приёмка требует выполненных assertions, без skips
и выдачи закэшированного результата за текущий прогон.

## Чеклист

- [ ] Выбран корректный тип (Feature по умолчанию; Unit только для логики без БД).
- [ ] Данные через фабрики; тест использует `RefreshDatabase`.
- [ ] Внешние операции (browser/Gemini/файлы) замоканы или проверены через `*::fake()`.
- [ ] Ассерты про поведение, а не реализацию.
- [ ] Прогон зелёный в `agelto_core_test`, dev/shared БД и внешняя сеть не затронуты.

## Связанные

- `testing-rules` — безопасность и изоляция тестовой БД.
- `laravel-testing`, `test-strategy`, `test-isolation-guard` — библиотечные практики.
