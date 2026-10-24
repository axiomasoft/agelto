---
name: testing-rules
description: "Правила безопасного тестирования Agelto: изоляция тестовой БД (pgsql agelto_test), RefreshDatabase, фабрики. Активировать при написании/запуске тестов или настройке тестового окружения."
---

# Правила тестирования (Agelto)

> Тесты используют только изолированную БД `agelto_test`. `RefreshDatabase` очищает состояние
> тестов, но не доказывает безопасность выбранной БД. Сначала проверь identity целевого rig.

## 1. Изоляция тестовой БД

`phpunit.xml` задаёт serial defaults. До загрузки приложения `tests/bootstrap.php`
синхронизирует `$_SERVER`, `$_ENV` и `putenv()` с testing-конфигурацией. При
`PROCESS_CORE_TEST=1` выбираются отдельные PostgreSQL/Redis rig и его queue namespace.
Одного `force="true"` недостаточно для защиты от destructive reset.

Process-core запускается через `core-*` targets из repository root: explicit Compose project
`agelto_core_test`, отдельный PostgreSQL 17, БД `agelto_test`, internal network и local fixtures.
Не создавай test DB в dev/shared PostgreSQL и не используй dev `.env`/credentials.
Перед reset проверяются exact project/container/database identity, testing env и volumes.
Identity/connectivity разрешены до migrations; readiness проверяет установленную схему после них.
Подробный порядок: [process-test-rig](../../../docs/dev/runbooks/process-test-rig.md).

## 2. Изоляция состояния теста

Для обычных serial DB tests используй `RefreshDatabase` согласно `tests/Pest.php`.
Настоящие concurrency tests используют отдельные PostgreSQL connections/processes и собственную
детерминированную очистку isolated DB; внешняя rollback-транзакция не должна скрывать их writes.
Транзакция, фабрика или mock не заменяют проверку целевой БД и реальную конкурентную проверку.

## 3. Фабрики, не ручные insert'ы

- Данные создавай через фабрики: `User::factory()->create()`, не `User::create()`.
- Не вставляй записи напрямую и не полагайся на сидеры боевых данных в тестах.

## 3а. LLM-вызовы — только через тестовый двойник

Реальные запросы к Gemini/любому провайдеру в тестах ЗАПРЕЩЕНЫ (Execution Rules плана EST-AI).
Подменяй провайдер двойником `Tests\Support\Doubles\FakeLlmProvider`: интерфейс
`App\Services\Llm\Contracts\LlmProvider` НЕ забинжен в контейнере нигде (`LlmRouteResolver::providerFor()`
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

Используй identity/readiness guards harness, а не только чтение `phpunit.xml`.
`Http::preventStrayRequests()` дополняет Docker egress denial: raw sockets и CLI требуют
сетевой изоляции. Manifest проверяет fixture hashes/provenance; локальные HTTP/proxy/fake LLM
проверки не обращаются к платным моделям. Future suite обязана вернуть `suite_not_implemented`
(exit 78); пустой набор, skip или отсутствие assertions не являются успешной приёмкой.

## 5. Запрещено / Обязательно

НИКОГДА: тесты на БД `agelto`; `DB_DATABASE=agelto` в тестовом окружении; ручной truncate/delete
боевых таблиц; тестирование через tinker на боевой БД.

ВСЕГДА: isolated PostgreSQL `agelto_test`; подходящая изоляция состояния теста; фабрики;
проверка resolved конфигурации и identity перед прогоном.

## 6. CI

В CI также нужна отдельная PostgreSQL `agelto_test` и проверенная testing-конфигурация;
не наследуй dev/shared runtime. Bootstrap и resolved guards действуют и в CI.

## Чеклист

- [ ] Resolved testing config и exact rig identity проверены.
- [ ] БД `agelto_test` находится в отдельном test PostgreSQL.
- [ ] Serial DB test использует `RefreshDatabase`; concurrency test — реальные connections и isolated cleanup.
- [ ] Данные — через фабрики.
- [ ] Боевая БД `agelto` не затрагивается.

## Связанные

- `pest-testing` — как писать и запускать тесты Pest.
- `test-isolation-guard` — библиотечные гарантии изоляции БД.
- `llm-layer-dev` — контракт `LlmProvider`, что доступно на `FakeLlmProvider`.
- `estimate-extraction-dev` — extraction-специфичные тестовые сценарии (checkpoint-requeue, preflight, unprocessable).
