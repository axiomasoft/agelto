# PHP Coding Standards

## Финальные классы

Все классы должны быть `final`, кроме абстрактных базовых классов, которые явно предназначены для наследования.

```php
// ✅ Правильно
final class SmetaParserService
{
    public function parse(Estimate $estimate): array { ... }
}

// ❌ Неправильно (не final)
class SmetaParserService
{
    public function parse(Estimate $estimate): array { ... }
}
```

## Именованные аргументы

См. `named-arguments.md` — отдельный гайдлайн с примерами.

## Строгая типизация

- `declare(strict_types=1);` в новых PHP-файлах.
- Явные return types и parameter types.
- Constructor property promotion где уместно.

## Actions vs Services

- **`Action`** — атомарная бизнес-операция с мутацией (если используется в проекте).
- **`Service`** — инфраструктура и оркестрация без HTTP (`BrowserService`, `GeminiService`, `SmetaParserService`).
- **Jobs** — длительные асинхронные стадии пайплайна (crawling, AI, parsing).
- **HTTP** — Form Request, Policy/Gate, тонкий Controller.

## Modules

- Код домена parsing/crawling — в `Modules/Platform` / `Modules/Eis`, не дублировать в `app/` без причины.
