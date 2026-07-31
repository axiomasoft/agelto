<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: backend-laravel@0.11.2
Template: rules/reviewer-overrides.md
Edit-policy: user-owned — файл ваш, пак его не перезапишет
Extension-points: .claude/rules/agelto-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack backend-laravel --target .
-->
# Оверрайды ревью — agelto

Сюда — проектные оси ревью; пак этот файл не перезаписывает.

## Тесты (дополняет `.claude/rules/tests.md`)

- Только `test('<интент по-русски>', fn)` — без `it()` (весь текущий сюит уже на `test()`, канон
  зафиксирован в `.ai/guidelines/glossary.md`).
- Изоляция от прод/dev БД и media — гейт `Tests\Support\TestEnvironmentGuard`
  (`tests/Support/TestEnvironmentGuard.php`, покрыт `tests/Feature/Support/TestEnvironmentGuardTest.php`);
  падение теста на «не та БД» — сначала проверь этот гейт, не патчи симптом.

## LLM-слой / промты / лимиты провайдера (skills `llm-layer-dev`, `estimate-extraction-dev`, `prompt-engineering-agelto`)

- Домен (`Services/Procurement/**`, `Jobs/**`) не вызывает Prism/конкретный SDK напрямую и не
  содержит Gemini-специфики (`cachedContent`, Files API-деталей) — только через
  `Contracts\LlmProvider` и его опциональные `SupportsFileUpload`/`SupportsContextCaching`.
  Найдено прямое обращение к транспорту в контроллере/сервисе домена — блокирующее замечание.
- Реальный LLM-вызов в тесте (не `Tests\Support\Doubles\FakeLlmProvider`) — блокирующее замечание
  (сеть/деньги/недетерминизм в CI).
- Новая/изменённая модель маршрута без записи в `config('ai.capabilities.<model>')`
  (`max_request_file_mb`/`max_pages`/`eol_date`/`successor`) — блокирующее: `ExtractionPreflight`
  не сможет решить путь, EOL-мониторинг не увидит модель.
- Правка `is_protected`-промта напрямую в БД/сидере в обход обоих корней канона (`resources/ai/prompts/**` +
  `resources/<домен>/ai/prompts/**`, D32) — блокирующее
  (нарушает D5/D17: канон — файл, БД — рантайм-копия).
- Extraction-вызов (`extract_*`/`extraction:discovery`) с `tools`/function-calling или где
  содержимое документа могло бы попасть в `system_instruction` — блокирующее (indirect prompt
  injection, `docs/specs/v2/08-estimate-extraction.md` §7).
- `EstimateExtractionJob`/наследники checkpoint-requeue переведены на `ShouldBeUnique` вместо
  `ShouldBeUniqueUntilProcessing` — блокирующее (self-dispatch продолжения тихо дедуплицируется,
  документ зависает в extraction, D39).

## Статический анализ (`docs/03_Dev/static-analysis.md`)

- Рост `phpstan-baseline.neon` (новая запись сверх легальных рычагов — `databaseMigrationsPath`,
  `@property`/`@property-read` докблоки, отражающие фактическую схему/связь) без D-записи, объясняющей
  причину — блокирующее замечание.
- `@phpstan-ignore` точечно / `excludePaths` / понижение `level` / отключение
  `reportUnmatchedIgnoredErrors` как способ «снять» ошибку — блокирующее (глушит baseline вместо
  документирования долга, политика `docs/03_Dev/static-analysis.md`).
