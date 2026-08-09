# P9 — Filament 5 UX contracts: official-source verification

Дата проверки: 2026-08-09. Проектная версия: `filament/filament v5.7.6`, подтверждено
`composer show filament/filament --no-interaction` на текущем tree.

## Проверенные contracts

1. Navigation item label задаётся явным `$navigationLabel`/`getNavigationLabel()`, группы можно
   упорядочить через `Panel::navigationGroups()`, а enum группы может реализовать `HasLabel`.
   Источник: https://filamentphp.com/docs/5.x/navigation/overview. Confidence: high.
2. `Filament\Support\Contracts\HasLabel` даёт человекочитаемые подписи enum для form options,
   table columns/groups и infolist entries при корректном model cast.
   Источник: https://filamentphp.com/docs/5.x/advanced/enums. Confidence: high.
3. `Filament\Schemas\Components\Section` штатно группирует form/infolist components и несёт
   heading/description; schema/layout остаются server-driven PHP configuration.
   Источники: https://filamentphp.com/docs/5.x/schemas/sections и
   https://filamentphp.com/docs/5.x/schemas/overview. Confidence: high.
4. Технические колонки можно оставить доступными оператору, но скрыть по умолчанию через
   `toggleable(isToggledHiddenByDefault: true)`; заголовки могут переноситься через `wrapHeader()`.
   Источник: https://filamentphp.com/docs/5.x/tables/columns/overview. Confidence: high.
5. Длинный текст поддерживает `wrap()` и `lineClamp()`, а responsive visibility —
   `visibleFrom()`/`hiddenFrom()`; горизонтальный scroll остаётся штатным fallback, не целевой
   единственной стратегией.
   Источники: https://filamentphp.com/docs/5.x/tables/columns/text и
   https://filamentphp.com/docs/5.x/tables/layout. Confidence: high.
6. Несколько record actions можно сгруппировать штатным `Filament\Actions\ActionGroup`, не
   создавая custom CSS/JS carrier.
   Источник: https://filamentphp.com/docs/5.x/tables/actions. Confidence: high.
7. В locked source v5.7.6 присутствуют `helperText()`, `wrap()`, `lineClamp()`, `toggleable()`,
   `visibleFrom()` и `hiddenFrom()`; это дополнительно проверено по
   `vendor/filament/{forms,infolists,support,tables}/src/**`. RAG:— (repo-grounded: installed
   Composer source at `vendor/filament/**`).

## Ограничение

Context7 и Perplexity в текущей сессии недоступны. Ladder продолжен WebSearch только по official
Filament 5.x documentation; внешних фактов, оставшихся без official source, нет.

## Verification status

- RAG: WebSearch official Filament docs, 8 narrow queries / 7 primary pages, OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: ничего.
- Обходки/[POSSIBLE-DEFECT]: ничего.
