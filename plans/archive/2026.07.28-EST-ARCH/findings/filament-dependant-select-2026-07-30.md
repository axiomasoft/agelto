# findings — Filament v5: зависимый `Select` (опции из состояния другого поля)

Слой 1 (`plan-protocol` §16). Внешний факт, нужный ТЗ P2.5: после расщепления `LlmPurpose`/
`PromptStep` на интерфейс + ядерные/типовые enum'ы форма `AiRouteForm` не может строить опции из
`Select::options(LlmPurpose::class)` (у интерфейса нет `::cases()`), а `PromptForm` обязана
превратить свободный `Textarea` шага в `Select` (контракт P2.1 §7). Множество допустимых значений
зависит от значения поля `document_type` В ТОЙ ЖЕ форме — значит нужен зависимый select.

- **Запрос:** «Filament v5 — Select, опции которого зависят от значения другого поля формы».
- **Источник:** `mcp__laravel-boost__search-docs` (документация версии, установленной в проекте) →
  `filament/filament@5.x`, `packages/forms/docs/01-overview.md`, раздел **«Dependant select
  options»** (+ разделы «The basics of reactivity», «Field utility injection»).
  URL: <https://github.com/filamentphp/filament/blob/5.x/packages/forms/docs/01-overview.md>
- **Дата проверки:** 2026-07-30.
- **Вердикт:** механизм штатный и версионно-актуальный. RAG:✅ 2026-07-30.

## Механика (дословно по документации)

```php
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Select;

Select::make('category')
    ->options([...])
    ->live()                                 // без live() схема не перерисовывается

Select::make('sub_category')
    ->options(fn (Get $get): array => match ($get('category')) { ... })
```

Три факта, важные для ТЗ:

1. **Поле-источник обязано быть `->live()`** — иначе схема не перерисуется и опции зависимого поля
   останутся от первого рендера. Цена — round-trip к серверу на каждое изменение источника
   (документация называет это явной платой за реактивность; на админской форме из ~9 полей
   приемлемо).
2. **Опции зависимого поля — замыкание, принимающее утилиту `Get`** из неймспейса
   `Filament\Schemas\Components\Utilities\Get` (в v5 утилиты живут в `Schemas`, не в `Forms`).
   `$get('document_type')` возвращает СЫРОЕ состояние поля — строку, не enum.
3. **Опции допускается брать из любого источника данных** (документация показывает пример с
   Eloquent-запросом внутри замыкания) — то есть вызов реестра `DocumentAnalyzerRegistry` внутри
   `options()` укладывается в штатную форму, изобретать ничего не нужно.

## Что из этого следует для P2.5

- `document_type` в `AiRouteForm` и в `PromptForm` получает `->live()`;
- `purpose`/`step` получают `options(fn (Get $get) => …)`, где список собирается реестром по
  `$get('document_type')` (строка → `AiDocumentType|DocumentTypeScope`);
- список опций И ЕСТЬ защита пути записи через админку (контракт P2.1 §7): реестровая валидация
  стоит в сидерах, а Filament пишет через модель, минуя сидер, поэтому союз «все назначения всех
  типов» одним списком дал бы оператору возможность завести маршрут `extract_rows` для типа, у
  которого такого назначения нет.
