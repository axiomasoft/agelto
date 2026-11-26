---
name: testing-rules
description: "Правила безопасного тестирования Agelto: изолированный стенд agelto_core_test (core-*), RefreshDatabase, concurrency-тесты, фабрики и локальные фейки."
---

# Правила тестирования (Agelto)

> Все тесты выполняются только в изолированном стенде `agelto_core_test` (`agelto_test`). Никогда не используй dev/shared PostgreSQL или боевую БД `agelto`.

## 1. Изолированный стенд `core-*`

Подробный ранбук: `docs/dev/runbooks/process-test-rig.md` и `.ai/guidelines/cheatsheet.md`.
- Поднятие стенда: `make core-test-up` (при устаревших runtime-inputs: `rm -rf docker/tests/process-core/runtime-inputs && make core-test-up`).
- Таргеты проверки:
  - `make core-test-env`, `make core-quality`, `make core-test-unit`
  - `make core-test-execution`, `make core-test-concurrency`, `make core-test-network`
  - `make core-test-platforms`, `make core-test-acquisition`, `make core-test-documents`
  - `make core-test-ai`, `make core-test-observability`, `make core-test-install`, `make core-test-regression`
  - Точечный файл: `make core-test-file TEST_FILE=tests/Feature/...`
- `Http::preventStrayRequests()` работает совместно с Docker egress denial: внешняя сеть на стенде закрыта, используются локальные сервисы `fixture-http`, `fixture-proxy`, `fake-gemini`, `object-storage`.

## 2. Изоляция состояния и конкурентность

- Обычные serial feature-тесты используют `RefreshDatabase` согласно `tests/Pest.php`.
- Concurrency-тесты (`tests/Integration/ProcessCore/Concurrency/`) используют реальные параллельные соединения/воркеры PostgreSQL и детерминированную очистку без внешней обёрточной rollback-транзакции.

## 3. Стиль тестов и именование

- Создавай модели через фабрики (`Model::factory()->create()`).
- Имена тестов: `test('<русский intent поведения>', fn () => ...)` — описывай проверяемый инвариант или сценарий без привязки к номерам задач (`T26`, `P1.x`, `bp-jobs`).
- При намеренном изменении контрактных или gold-фикстур запусти `make core-manifest-sync`. При изменении других staged inputs обнови их SHA-256 в `tests/Fixtures/ProcessCore/runtime-inputs.json`. Для миграций обнови `tests/Support/ProcessCore/schema-inventory.json`.
- При добавлении/удалении классов в `App\{Enums,Exceptions,Dto,Repositories}\Procurement` или ядровых пространствах имён проверяй `DomainLayoutTest.php` и `CoreKnowsNoDocumentTypeTest.php`.
