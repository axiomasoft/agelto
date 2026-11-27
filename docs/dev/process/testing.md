---
audience: dev
type: howto
status: living
---

# Тестирование

## Запуск

Поднимите изолированный стенд `agelto_core_test` и выберите файл или предметную группу.

```bash
make core-test-up
make core-test-file TEST_FILE=tests/Feature/Filament/FilamentTableContractTest.php
make core-test-observability
make core-quality
```

Раннер привязывает команды к отдельным PostgreSQL, Redis и объектному хранилищу,
блокирует внешний трафик и сохраняет JUnit и вывод в
`storage/app/testing/process-core/reports/`. Остальные цели перечислены в [ранбуке стенда](../runbooks/process-test-rig.md).

`make core-manifest-sync` обновляет SHA-256 контрактных фикстур и `estimates/gold/`.
Манифест сохраняет инвентарь сценариев и историческое происхождение исходников. При изменении
других staged inputs обновите их записи в `tests/Fixtures/ProcessCore/runtime-inputs.json`.
Для миграций используется собственный inventory схемы и проверка каталога PostgreSQL.

### Браузерные сценарии

`@playwright/test` и образ браузерного профиля стенда используют Playwright 1.62.1.
Проект setup сохраняет отдельные состояния SuperAdmin, Operator и Analyst. Сценарии
оператора проверяют поиск и открытие соответствия статусов, а также исправление адреса
AI-провайдера и сохранение записи без вызова провайдера.

Release-проверка `bash docker/tests/clean-install-e2e.sh` использует отдельный checkout
точного `HEAD`, одноразовый Compose project, собственные БД, тома и учётные данные.
`E2E_ADMIN_EMAIL` и `E2E_ADMIN_PASSWORD_FILE` позволяют явно задать тестовые данные входа.

Релизная приёмка приватной документации — `make documentation:acceptance`, без обязательных
внешних учётных данных. Она объединяет отклонение активных SVG, целевой корпус Pest, чистые
проверки vendor, VitePress и образа и изолированный прогон Playwright на хэши, заголовки, выход и
отзыв прав; якоря кандидата и образа печатаются для отчёта о доказательствах релиза.

## Правила

- Изоляция от прод/dev БД и media, каноны именования/структуры тестов — skill `testing-rules`.
- Agelto-специфичные оверрайды (только `test('<интент по-русски>', fn)`, без `it()`; гейт
  `Tests\Support\TestEnvironmentGuard`) — `.claude/rules/agelto-reviewer-overrides.md`. Тест падает
  на «не та БД» — сначала проверить этот гейт, не патчить симптом.
- LLM-вызовы в тестах — ТОЛЬКО через тестовые двойники (`tests/Support/Doubles/`), никаких реальных
  запросов к Gemini (Execution Rules плана EST-AI).
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`,
  `->primaryAnalysisFailed()`); цепочки связей — `->for()`/`->has()` или именованный билдер.

## TIA (Test Impact Analysis)

`make core-test-tia` записывает граф зависимостей для изолированного стенда. Используйте
его для диагностики затронутых тестов. Для приёмки используйте указанный в задании
`core-test-*`: целевые файлы, предметные и регрессионные наборы запускаются с `--no-tia`.
Результаты replay служат диагностике, а приёмка подтверждает выполнение выбранных тестов
на текущем дереве.

Постоянные проверки поведения хранятся в `tests/`. Одноразовый Pest `--agent`
подходит для эксперимента в изолированном тестовом контексте. Фейки внешних
сервисов обязательны и для таких экспериментов.

## Agent-optimized output

`laravel/pao` — единственный formatter agent output. Docker Make targets forward Codex, Claude,
Cursor and `AI_AGENT` markers into `app`: Pest, Paratest and PHPStan return one structured JSON
result in agent mode. `php artisan test` delegates to Pest and therefore has the same JSON contract;
other Artisan commands retain normal human text output. Не добавлять второй output-wrapper и не
считать presence env marker доказательством — contract проверяет schema фактического stdout на
success и failure.

## Стиль кода

`make core-quality` запускает Pint в режиме проверки и PHPStan на `level: max`.

## Смежное

- `../getting-started.md` — первый прогон тестов после клонирования.
- `db-test-preflight` (skill) — Postgres-контейнер жив, `agelto_test` существует, миграции применены.
- [текущий inventory команд](../../operators/production/commands.md#current-command-inventory)
  — канонические test/quality targets и их exit/evidence contract.
