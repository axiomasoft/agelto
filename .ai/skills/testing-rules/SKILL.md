---
name: testing-rules
description: "Правила безопасного тестирования Agelto: изоляция тестовой БД (pgsql agelto_test), RefreshDatabase, фабрики. Активировать при написании/запуске тестов или настройке тестового окружения."
---

# Правила тестирования (Agelto)

> ⚠️ **КРИТИЧЕСКИ ВАЖНО**: тесты используют отдельную БД `agelto_test`. Всегда применяй
> `RefreshDatabase` — без него тесты на боевой БД уничтожат данные.

## 1. Изоляция тестовой БД

Конфигурация зафиксирована в [`phpunit.xml`](../../../phpunit.xml) и **форсится** (`force="true"`),
поэтому переопределить из `.env` нельзя:

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="pgsql" force="true"/>
<env name="DB_DATABASE"   value="agelto_test" force="true"/>
```

- ✅ Тестовая БД: `agelto_test` (PostgreSQL, изолирована).
- ✅ Боевая БД: `agelto` — защищена (`force="true"` не даёт тестам её коснуться).
- ❌ НИКОГДА не ставь `DB_DATABASE=agelto` в тестовом окружении.

Тестовая БД должна существовать в том же PostgreSQL-инстансе:

```bash
createdb agelto_test    # один раз; или psql: CREATE DATABASE agelto_test;
```

## 2. RefreshDatabase — обязателен

`RefreshDatabase` подключается глобально в [`tests/Pest.php`](../../../tests/Pest.php) для нужных
сюит. Для тестов, работающих с БД, трейт обязателен:

```php
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);
```

Он оборачивает каждый тест в транзакцию и откатывает её — данные между тестами не текут. Это
безопасно, потому что используется **отдельная** БД `agelto_test`.

## 3. Фабрики, не ручные insert'ы

- Данные создавай через фабрики: `User::factory()->create()`, не `User::create()`.
- Не вставляй записи напрямую и не полагайся на сидеры боевых данных в тестах.

## 4. Проверка перед запуском

```bash
# конфигурация тестовой БД
grep -E "DB_(CONNECTION|DATABASE)" phpunit.xml      # ожидаем: pgsql / agelto_test

# наличие тестовой БД
psql -lqt | cut -d '|' -f1 | grep -qw agelto_test && echo "agelto_test ✓"
```

## 5. Запрещено / Обязательно

❌ **НИКОГДА**: тесты на БД `agelto`; `DB_DATABASE=agelto` в тестовом окружении; ручной
`truncate`/`delete` боевых таблиц; тестирование через `tinker` на боевой БД.

✅ **ВСЕГДА**: PostgreSQL `agelto_test`; `RefreshDatabase`; фабрики; проверка конфигурации перед
прогоном.

## 6. CI

В CI поднимается сервис PostgreSQL и та же БД `agelto_test`; DB-параметры берутся из `phpunit.xml`
(env форсятся), отдельный `.env.testing` для них не нужен.

## Чеклист

- [ ] `phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=agelto_test` (оба `force="true"`).
- [ ] БД `agelto_test` существует в инстансе PostgreSQL.
- [ ] Тест с БД использует `RefreshDatabase`.
- [ ] Данные — через фабрики.
- [ ] Боевая БД `agelto` не затрагивается.

## Связанные

- `pest-testing` — как писать и запускать тесты Pest.
- `test-isolation-guard` — библиотечные гарантии изоляции БД.
