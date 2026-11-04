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
- `Http::preventStrayRequests()` работает совместно с Docker egress denial: внешняя сеть на стенде закрыта, используются локальные контейнеры `fake-source`, `forward-proxy`, `fake-llm`, `minio`.

## 2. Изоляция состояния и конкурентность

- Обычные serial feature-тесты используют `RefreshDatabase` согласно `tests/Pest.php`.
- Concurrency-тесты (`tests/Feature/ProcessCore/Concurrency/`) используют реальные параллельные соединения/воркеры PostgreSQL и детерминированную очистку без внешней обёрточной rollback-транзакции.

## 3. Стиль тестов и именование

- Создавай модели через фабрики (`Model::factory()->create()`).
- Имена тестов: `test('<русский intent поведения>', fn () => ...)` — описывай проверяемый инвариант или сценарий без привязки к номерам задач (`T26`, `P1.x`, `bp-jobs`).
- При изменении файлов, перечисленных в `tests/Fixtures/ProcessCore/manifest.json` (`source_classes`, `files`), синхронизируй SHA-256 хэши манифеста перед `make core-quality` и `make core-test-regression`.
