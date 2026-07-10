---
name: testing-rules
description: "Правила безопасного тестирования Agelto: изоляция тестовой БД (pgsql agelto_test), RefreshDatabase, фабрики. Активировать при написании/запуске тестов или настройке тестового окружения."
---

# Правила тестирования (Agelto)

> ВАЖНО: тесты используют отдельную БД `agelto_test`. Всегда применяй `RefreshDatabase` —
> без него тесты на боевой БД уничтожат данные.

## 1. Изоляция тестовой БД

Конфигурация зафиксирована в `phpunit.xml` и форсится (`force="true"`), переопределить из `.env` нельзя:

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="pgsql" force="true"/>
<env name="DB_DATABASE"   value="agelto_test" force="true"/>
```

- Тестовая БД: `agelto_test` (PostgreSQL, изолирована).
- Боевая БД: `agelto` — защищена (`force="true"` не даёт тестам её коснуться).
- НИКОГДА не ставь `DB_DATABASE=agelto` в тестовом окружении.

Тестовая БД должна существовать в том же PostgreSQL-инстансе:

```bash
createdb agelto_test    # один раз; или psql: CREATE DATABASE agelto_test;
```

## 2. RefreshDatabase — обязателен

`RefreshDatabase` подключается глобально в `tests/Pest.php` для нужных сюит. Для тестов с БД трейт обязателен.
Он оборачивает каждый тест в транзакцию и откатывает её — данные между тестами не текут. Безопасно,
потому что используется отдельная БД `agelto_test`.

## 3. Фабрики, не ручные insert'ы

- Данные создавай через фабрики: `User::factory()->create()`, не `User::create()`.
- Не вставляй записи напрямую и не полагайся на сидеры боевых данных в тестах.

## 3а. LLM-вызовы — только через тестовый двойник

Реальные запросы к Gemini/любому провайдеру в тестах ЗАПРЕЩЕНЫ (Execution Rules плана EST-AI).
Подменяй провайдер двойником `Tests\Support\Doubles\FakeLlmProvider`: интерфейс
`App\Services\Llm\Contracts\LlmProvider` НЕ забинжен в контейнере нигде (`AiRouteResolver::providerFor()`
резолвит КОНКРЕТНЫЙ класс, `container->make(PrismGeminiProvider::class)`/`container->make(
OpenAiCompatibleProvider::class)`, намеренно — комментарий в коде объясняет, что это ради подмены в
тестах) — подменяй в тесте КОНКРЕТНЫЙ класс маршрута, `app()->instance(PrismGeminiProvider::class,
$fake)` или `app()->instance(OpenAiCompatibleProvider::class, $fake)`, НЕ интерфейс (подмена
интерфейса тихо не резолвится — двойник никогда не используется). Фабричные методы
`succeeding()`/`truncated()`/`throwing()`, `andThen()` — для сценариев re-ask/эскалации (без
tuple-возвратов и boolean-флагов, канон хелперов). Реализует и `SupportsFileUpload`, и
`SupportsContextCaching` заглушками — не нужно писать свой двойник под extraction/screening-тесты.
Детали LLM-слоя — skill `llm-layer-dev`.

## 4. Проверка перед запуском

```bash
grep -E "DB_(CONNECTION|DATABASE)" phpunit.xml      # ожидаем: pgsql / agelto_test
```

## 5. Запрещено / Обязательно

НИКОГДА: тесты на БД `agelto`; `DB_DATABASE=agelto` в тестовом окружении; ручной truncate/delete
боевых таблиц; тестирование через tinker на боевой БД.

ВСЕГДА: PostgreSQL `agelto_test`; `RefreshDatabase`; фабрики; проверка конфигурации перед прогоном.

## 6. CI

В CI поднимается сервис PostgreSQL и та же БД `agelto_test`; DB-параметры берутся из `phpunit.xml`.

## Чеклист

- [ ] `phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=agelto_test` (оба `force="true"`).
- [ ] БД `agelto_test` существует в инстансе PostgreSQL.
- [ ] Тест с БД использует `RefreshDatabase`.
- [ ] Данные — через фабрики.
- [ ] Боевая БД `agelto` не затрагивается.

## Связанные

- `pest-testing` — как писать и запускать тесты Pest.
- `test-isolation-guard` — библиотечные гарантии изоляции БД.
- `llm-layer-dev` — контракт `LlmProvider`, что доступно на `FakeLlmProvider`.
- `estimate-extraction-dev` — extraction-специфичные тестовые сценарии (checkpoint-requeue, preflight, unprocessable).
