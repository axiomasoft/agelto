---
name: prompt-engineering-agelto
description: "Dev-раннбук текстов промтов AI-пайплайна: файл→сид→is_protected, композиция и приоритеты, корректирующие hints, синхронизация ai:prompts:sync."
paths:
  - "resources/ai/prompts/**"
  - "resources/*/ai/prompts/**"
  - "database/seeders/PromptSeeder.php"
  - "app/Services/Llm/PromptComposer.php"
---

# Prompt Engineering (Agelto) — dev-раннбук

Первичный источник для правки текстов промтов — `resources/ai/prompts/README.md` и `docs/dev/ai/prompt-engineering.md`.

## 1. Канон: файл → сид → `is_protected`

- **Единственный источник истины** — `resources/ai/prompts/**/*.md` (общие шаги) и `resources/<домен>/ai/prompts/**/*.md` (шаги анализатора), с front-matter `key`/`step`/`version`/`priority`/`is_active`. Таблица `llm_prompts` — рантайм-копия.
- Применение правки: `php artisan ai:prompts:sync` (или `PromptSeeder`) — идемпотентный upsert по `(key, version)`.
- Файловые промты получают `is_protected = true` и защищены от редактирования/удаления в админке (`PromptPolicy`). Канон меняется только через файл и повторный sync.
- При увеличении `version` сидер деактивирует прежнюю версию того же `key` (`is_active = false`), сохраняя историю.

## 2. Композиция и разделение «схема / правила»

- `PromptComposer::compose()` собирает `global` → `<step>-global` → `<step>` в порядке `priority ASC`, плюс блок словарей синонимов (`PromptContextGenerator` из `#[Alias]` на DTO).
- Шаг типизирован через `App\Enums\Llm\PromptStep` (`CorePromptStep` и enum анализатора, разрешаемые через `DocumentAnalyzerRegistry::promptStepFor()`).
- Текст промта описывает **правила поведения**, а не структуру JSON: структура и описания полей генерируются из DTO (`JsonSchemaGenerator` + `#[Description]`).
- Корректирующие подсказки оператора (`prc_ai_corrections`) добавляются в пользовательскую часть запроса при повторном запуске, чтобы не ломать кэшируемый системный префикс.

## 3. Известные антипаттерны

- Глубокая вложенность в одном structured-ответе — используй плоский `row_type`, собирая иерархию на бэкенде.
- Извлечение всей сметы на сотни строк одним вызовом — используй чанки/окна `ExtractionHandler`.
- Диапазоны страниц текстом в промте — нарезай PDF на бэкенде (`PdfPageSlicer` / `qpdf`) до отправки.

## 4. Проверка

```bash
make core-test-ai
```
