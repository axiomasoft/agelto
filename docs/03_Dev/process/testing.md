---
audience: dev
type: howto
status: draft
---

# Тестирование

Маршрут, не копия содержимого — источники истины ниже.

## Запуск

```bash
make db:create-test   # разово: настройка agelto_test
make test-php         # ENV=dev по умолчанию; test-preflight + db:create-test + artisan test --compact
```

Точечный прогон одного класса/фильтра — `make artisan ARGS="test --filter=<Subject>"`
(**один `--filter` за прогон** — второй игнорируется Symfony Console, не Laravel-баг).

## Правила

- Изоляция от прод/dev БД и media, каноны именования/структуры тестов — skill `testing-rules`.
- Agelto-специфичные оверрайды (только `test('<интент по-русски>', fn)`, без `it()`; гейт
  `Tests\Support\TestEnvironmentGuard`) — `.claude/rules/agelto-reviewer-overrides.md`. Тест падает
  на «не та БД» — сначала проверить этот гейт, не патчить симптом.
- LLM-вызовы в тестах — ТОЛЬКО через тестовые двойники (`tests/Support/Doubles/`), никаких реальных
  запросов к Gemini (Execution Rules плана EST-AI).
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`,
  `->primaryAnalysisFailed()`); цепочки связей — `->for()`/`->has()` или именованный билдер.

## Стиль кода

`docker compose exec app ./vendor/bin/pint --dirty --test` — `--dirty` не видит untracked-файлы,
для новых файлов передавать явный список путей.

## Смежное

- `../getting-started.md` — первый прогон тестов после клонирования.
- `db-test-preflight` (skill) — Postgres-контейнер жив, `agelto_test` существует, миграции применены.
