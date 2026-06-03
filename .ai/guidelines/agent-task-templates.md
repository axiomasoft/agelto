# Шаблоны задач для агента

Краткие промпты под правила проекта (русский язык, parsing pipeline, named arguments, Docker tests). Использовать как стартовые сообщения в Cursor.

## Code review

```
Проведи ревью последнего diff: границы Module/Job/Service, влияние на стадии парсинга 1–4,
соответствие docs/workflow/parsing-process.md. Укажи только блокирующие замечания и конкретные файлы/строки.
Не предлагай рефакторинг вне области diff.
```

## Генерация тестов

```
Добавь минимальные Pest-тесты для изменённого поведения: один happy path и один негативный.
Следуй testing-rules: make test-preflight перед artisan test.
Запуск: php artisan test --compact --filter=...
```

## Security checklist

```
Проверь изменение на: утечку данных через Inertia props, обход Policy/Gate, mass assignment,
сырой SQL, утечку proxy credentials. Перечисли только реальные риски с путём исправления.
```

## Docker deploy

```
Нужен деплой после git pull. Определи минимальную make-цель: prod:deploy:code / prod:deploy:php-deps /
prod:deploy:frontend / prod:deploy:runtime / prod:deploy:image. Обоснуй выбор по diff.
```
