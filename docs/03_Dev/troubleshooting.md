---
audience: dev
type: howto
status: draft
---

# Troubleshooting (dev)

Проблемы вне Docker-инфраструктуры (для контейнерных сбоев — [`docker/troubleshooting.md`](docker/troubleshooting.md)).

## Тест падает на «не та БД»

Сначала проверить гейт `Tests\Support\TestEnvironmentGuard`
(`tests/Feature/Support/TestEnvironmentGuardTest.php` покрывает его тестом) — НЕ патчить симптом.
См. `process/testing.md`.

## `php artisan test --filter=X --filter=Y` не фильтрует по обоим

Второй `--filter` молча игнорируется Symfony Console — прогонять фильтры по одному
(`make artisan ARGS="test --filter=X"`, затем отдельно `--filter=Y`).

## `dispatch(...)->afterCommit()` срабатывает немедленно в тесте

Под `RefreshDatabase` в определённых условиях `afterCommit()`-диспатч исполняется сразу, не после
реальной транзакции — известный workaround плана EST-AI (см. `plans/archive/2026.07.07-EST-AI/handoff.md`
Workarounds), учитывать при тестировании job-цепочек пайплайна (`PipelineDispatcher`,
`PipelineJob::afterRelease()`).

## `Storage::disk('s3')` не работает в тесте

Требует `Storage::fake('s3')` явно в тесте — MinIO-диск не поднимается сам по себе под тестовым
окружением.

## `config('ai.capabilities.<model-с-точками>...')`

Имена моделей Gemini содержат точки (`gemini-2.5-flash-lite`) — `config()` с dot-notation корректно
резолвит вложенный ключ, но при отладке в tinker/дебаггере легко перепутать с разделителем пути —
проверять точной строкой ключа модели.

## Композитор/pint/vendor:publish не подхватывают правки

`composer require`/`composer update --lock`, `pint --dirty` авто-фикс, `vendor:publish`,
`guard:filament:generate` — надёжнее запускать С ХОСТА (не из контейнера) при проблемах с
bind-mount-правами (`www-data` внутри контейнера не ремаппится под хостовой uid — см.
[`docker/troubleshooting.md`](docker/troubleshooting.md)).

## `.env`/`.env.example` не читаются агентом

Файлы закрыты от чтения AI-агентами по правилам сессии — правки в них только append вслепую (без
предварительного чтения содержимого).

## Ключ Gemini / прокси

Как получить и куда положить — [`getting-started.md`](getting-started.md) §`.env`. Ошибки самого
транспорта (429, региональные блокировки) — [`runbooks/gemini-errors.md`](runbooks/gemini-errors.md).
