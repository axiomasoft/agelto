# Токен-бюджет маршрута `extraction` — диагноз блокера P2.4 (2026-08-01)

**Происхождение.** `handoff.md` после P2.3 передал в `plan-design` задачу: решить и оформить
D#-записью подъём `maxOutputTokens`/thinking-бюджета маршрута `extraction` — блокер P2.4
(`phases/P2.md` P2.3 Pending Work; полный диагноз red-замера M1 —
`research/03-prompt-contract.md` §8.5; исходный флейк — `findings/P2-eval-baseline.md` §3).
Этот файл — слой 1: снятая фактура (разведка дерева + RAG), БЕЗ решения. Решение — D-запись в
`plan.md` §5 и ТЗ items, их пишет `plan-design`, не этот файл.

Разведка велась на дереве `99735af` (`chore(plan): P2.3 закрыт 🟠`).

---

## 1. Что в дереве сегодня — четыре факта

### 1.1 Бюджет вывода не настраивается ни для одного маршрута: он константа 32768

`llm_routes.options` у ВСЕХ строк сидера — `null`
(RAG:— repo-grounded: `database/seeders/AiRoutingSeeder.php:51,72` — `'options' => null` и в
провайдерах, и в маршрутах), поэтому резолвер каждый раз берёт свой дефолт:

```php
// app/Services/Llm/LlmRouteResolver.php:30
private const int DEFAULT_MAX_TOKENS = 32768;
// app/Services/Llm/LlmRouteResolver.php:89
maxTokens: (int) ($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
```

То есть **механизм поmaршрутной настройки формально есть** (`options.max_tokens` читается), но
фактически не заполнен ни у одной строки, и величина 32768 одинакова для скрининга (ответ —
десятки токенов) и для `extraction:rows` (ответ — десятки тысяч токенов).
RAG:— (repo-grounded: `app/Services/Llm/LlmRouteResolver.php:27-30,89`).

Комментарий у константы описывает её происхождение честно: «8192 достаточно 2.5-линейке …
`gemini-3.x` тратит на reasoning ДО ответа 5900-7900+ токенов сверх этого» — то есть 32768
подбиралось под reasoning-накладную 2.5→3.x на ДРУГОЙ задаче (боевой прогон P6.2 плана-донора),
а не под объём вывода `extraction:rows`.

### 1.2 Thinking-уровень не передаётся провайдеру вообще

Адаптер отдаёт Prism ровно один provider-option — идентификатор кэша:

```php
// app/Services/Llm/Providers/PrismGeminiProvider.php:194-200
private function providerOptions(LlmOptions $options): array
{
    return array_filter(
        ['cachedContentName' => $options->cacheHandle?->id],
        static fn (mixed $value): bool => $value !== null,
    );
}
```

`LlmOptions` не несёт поля под thinking вовсе
(RAG:— repo-grounded: `app/Services/Llm/Dto/LlmOptions.php:20-28` — семь полей, thinking среди них
нет). Значит все вызовы идут с **дефолтным уровнем размышления модели**.

При этом **вендор-библиотека это поддерживает**: Prism читает `thinkingBudget` / `thinkingLevel` /
готовый `thinkingConfig` из provider-options и кладёт их в `generationConfig.thinkingConfig`
(RAG:— repo-grounded: `vendor/prism-php/prism/src/Providers/Gemini/Handlers/Structured.php:107-135`;
тот же блок в `Handlers/Text.php:70-90` и `Handlers/Stream.php:477-480`). Рычаг доступен и
не используется.

### 1.3 Предохранитель «уменьшить окно при MAX_TOKENS» на этом пути НЕ СРАБАТЫВАЕТ

Это главная находка разведки, и она объясняет, почему токен-лимит убивает эталон целиком,
а не деградирует плавно.

Спроектированный контур деградации выглядит так:

1. `RowRangeChunkSource::call()` при `finishReason === MaxTokens` зовёт колбэк
   (`app/Services/Procurement/Analyzers/Estimate/RowRangeChunkSource.php:193-210`);
2. колбэк бросает `MaxTokensExceededException`
   (`RowRangeChunkSource.php:104`);
3. `ExtractionOrchestrator` ловит её и **делит окно диапазона пополам** до
   `min_row_range_size`, а на минимуме помечает диапазон `failed` и идёт дальше
   (`ExtractionOrchestrator.php:230-250`; конфиг — `config/ai.php:355-357`,
   `row_range_size=30`, `min_row_range_size=5`).

Контур не запускается, потому что до `finishReason` дело не доходит: Prism на этом исходе
**бросает исключение** вместо того, чтобы вернуть ответ:

```php
// vendor/prism-php/prism/src/Providers/Gemini/Handlers/Structured.php:165-186
if ($finishReason === 'MAX_TOKENS') {
    …
    if (($isEmpty || $isInvalidJson) && $thoughtTokens > 0) {
        throw PrismException::providerResponseError(
            'Gemini hit token limit with high thinking token usage. '.
            "Token usage: {$promptTokens} prompt + {$thoughtTokens} thinking + {$outputTokens} output = {$totalTokens} total. ".
            "The {$errorDetail}. ".
            'Try increasing maxTokens to at least '.($totalTokens + 1000).' (suggested: '.($totalTokens * 2).' for comfortable margin).'
        );
    }
}
```

`RowRangeChunkSource::call()` ловит `Throwable`, пишет неуспех в `llm_calls` и **пробрасывает
исключение дальше** (`RowRangeChunkSource.php:167-191`) — то есть выше по стеку летит не
`MaxTokensExceededException`, а `PrismException`, и `catch (MaxTokensExceededException)`
оркестратора её не видит. Документ падает целиком.

Условие вендорского throw — `(пусто ИЛИ невалидный JSON) И thoughtTokens > 0`. У `gemini-3.x`
с включённым по умолчанию размышлением (§1.2) `thoughtTokens > 0` — норма, поэтому предохранитель
мёртв именно в той конфигурации, в которой работает проект. Формально живым он остаётся только
для случая «MAX_TOKENS, но JSON случайно оказался валидным» и для моделей без thinking.

Тот же слепой участок — у валидационного каскада: гейт
`if ($response->finishReason === FinishReason::MaxTokens)`
(`app/Services/Llm/Validation/StructuredResponseValidator.php:29-33`) и у `ReaskPolicy.php:56`
рассчитан на ОТВЕТ с признаком обрезки, а получает исключение.

### 1.4 Числа для калибровки уже оплачены и лежат в БД

Текст вендорского исключения (§1.3) несёт разложение бюджета —
`{prompt} prompt + {thinking} thinking + {output} output = {total} total` — и собственную
рекомендацию `maxTokens ≥ total + 1000` (комфортно — `total × 2`). `RowRangeChunkSource::call()`
записывает `error: $exception->getMessage()` в `llm_calls` при каждом падении
(`RowRangeChunkSource.php:167-188`; колонка — `database/migrations/2026_07_04_100026_create_llm_calls_table.php:31`,
`error text NULL`).

Следствие: **требуемый потолок вывода вычисляется из уже сделанных прогонов
(EvalRun #1/#2/#3) SQL-запросом, без единого нового платного вызова.**
RAG:— (repo-grounded, перечисленные файлы/строки).

Косвенное подтверждение порядка величины — длины усечения из
`findings/P2-eval-baseline.md` §3: `truncated at 127882 characters` (`EST-XLS-KS2-001`) — это
десятки тысяч токенов вывода при потолке 32768; и `truncated at 30641/29546 characters`
на других эталонах, где бюджет, судя по условию throw, съело размышление.

---

## 2. RAG — внешняя фактура (Perplexity Web, 2026-08-01)

**Запрос 1** — «Gemini 3.5 Flash maxOutputTokens limit; do thinking tokens consume maxOutputTokens;
thinkingLevel vs thinkingBudget in Gemini 3».
Источники: `firebase.google.com/docs/ai-logic/thinking`,
`docs.cloud.google.com/gemini-enterprise-agent-platform/models/thinking`.

- **Потолок вывода `gemini-3.5-flash` — 65 536 токенов.** RAG:✅ (2026-08-01). Текущий проектный
  дефолт 32768 — ровно половина доступного.
- **У линейки 3.x рычаг называется `thinking_level`** (`MINIMAL` / `LOW` / `MEDIUM` / `HIGH`,
  дефолт `MEDIUM`), а `thinking_budget` — рычаг 2.5-линейки; **одновременно задавать их в одном
  запросе Gemini 3 нельзя.** RAG:✅ (2026-08-01).
- Документация Firebase прямо говорит, что **более низкий уровень размышления оставляет больше
  общего лимита вывода под сам ответ.** RAG:✅ (2026-08-01).

**Запрос 2** — «finishReason MAX_TOKENS с пустым ответом при высоком thoughtsTokenCount;
рекомендуемый maxOutputTokens для structured JSON с включённым thinking». Источник: та же
документация + разбор известного паттерна отказа.

- **Подтверждён механизм отказа:** токены размышления расходуют тот же бюджет, что видимый вывод,
  поэтому `finishReason: MAX_TOKENS` приходит с ПУСТЫМ ответом — модель потратила бюджет на
  размышление раньше, чем начала печатать JSON. RAG:✅ (2026-08-01).
- **Диагностический рецепт вендора:** смотреть `usageMetadata.thoughts_token_count` против
  `candidates_token_count`; высокий thinking при почти нулевом выводе → поднимать
  `maxOutputTokens` ЛИБО понижать уровень размышления, а не править схему. RAG:✅ (2026-08-01).
- Для задач извлечения (structured JSON) рекомендация — минимизировать размышление: качество
  структурного вывода важнее глубины рассуждения. RAG:✅ (2026-08-01) — совет вендора, не замер
  на нашем корпусе; проверяется только eval'ом.

**Что RAG НЕ дал и осталось `[UNVERIFIED]`:** потолки вывода `gemini-3.1-flash-lite` и
`gemini-3.1-pro-preview` (маршруты `screening`/`discovery`/`priority=1`) — отдельно не
подтверждены; в capability-матрице `config/ai.php:104-178` поля под потолок вывода нет вовсе,
там живут только `max_request_file_mb`/`max_pages`/`eol_date`/`successor`. Та же оговорка, что
уже стоит в комментарии матрицы про транспортные лимиты 3.x (унаследованы от 2.5 как рабочее
допущение), распространяется и сюда.

---

## 3. Что из этого следует для плана (перечень, не решение)

1. **Подъём одного числа корень не закрывает.** Даже с потолком 65536 предохранитель §1.3
   остаётся мёртвым: следующий эталон, который не уложится, снова убьёт документ целиком вместо
   деградации до половинного окна. Это ровно тот класс, который план уже один раз назвал
   поимённо — D95 («корень блокера — не лимит памяти, а плотный обход листа; чинится он,
   а не симптом»).
2. **Величина берётся из фактуры, а не из вкуса** — §1.4 даёт разложение бюджета по каждому
   упавшему вызову уже оплаченных прогонов.
3. **Носитель величины — вопрос дизайна:** `llm_routes.options.max_tokens` (механизм готов,
   §1.1) и/или поле потолка модели в capability-матрице (`config/ai.php`), которая сегодня знает
   про транспортные лимиты, но не про лимит вывода.
4. **Уровень размышления — второй рычаг того же бюджета** (§1.2, §2) и требует своего носителя
   в `LlmOptions`, иначе `PrismGeminiProvider` физически не может его передать.
5. **Судьба baseline фазы — денежный вопрос владельцу.** M0/M0′ (D91) измерены на контуре, где
   доминирующий источник разброса — ровно этот дефект (`findings/P2-eval-baseline.md` §4:
   `EST-XLS-KS2-001` даёт разброс 0.5652 при пороге 0.05). После фикса старая величина шума
   описывает уже не существующий контур и как порог непрегрессии слишком широка.

---

## 4. Собранная фактура и решения P2.9 (2026-08-01, D98/D99)

**Разложение бюджета — семь падений EvalRun #1/#2/#3** (`llm_calls.error like '%hit token
limit%'`, ноль новых платных вызовов):

| # | prompt | thinking | output | total | thinking/total |
|:--|--:|--:|--:|--:|--:|
| 1 | 12382 | 25598 | -18444 | 45134 | 0.5672 |
| 2 | 6457 | 3695 | 25362 | 39209 | 0.0942 |
| 3 | 9190 | 6832 | 19088 | 41942 | 0.1629 |
| 4 | 6457 | 3695 | 25362 | 39209 | 0.0942 |
| 5 | 9162 | 31456 | -30160 | 41914 | 0.7505 |
| 6 | 12354 | 31453 | -30154 | 45106 | 0.6974 |
| 7 | 12354 | 23920 | -15088 | 45106 | 0.5303 |

(`output = candidatesTokenCount - thoughtsTokenCount`, вендорская диагностическая величина —
может быть отрицательной, когда thinking уже израсходовал бюджет до появления видимого текста.)

**Медианная доля `thinking / total`: 0.5303** (n=7, средний элемент отсортированного ряда) — ≥
порога 0.4 из Implementation Rules P2.9 → на трёх extraction-маршрутах (`ExtractPassport`/
`ExtractRows`/`ExtractTotals`, обе строки приоритетов) проставлен `thinking_level = LOW`.
`MINIMAL` не рассматривался (правило запрещает его при любых числах).

**Бюджет вывода:** `config('ai.capabilities.gemini-3.5-flash.max_output_tokens') = 65536`
(RAG:✅ §2) — вдвое выше прежней константы `LlmRouteResolver::DEFAULT_MAX_TOKENS = 32768`.
Максимум наблюдённого `total` по фактуре — 45134, комфортно ниже потолка. `llm_routes.options.
max_tokens = 65536` проставлен на ОБЕИХ строках приоритета всех трёх extraction-маршрутов
(включая эскалационную `gemini-3.1-pro-preview`, чей собственный потолок RAG не подтвердил —
`LlmRouteResolver::assertWithinOutputCeiling()` для неё не проверяет, ключа `max_output_tokens`
в её capability-записи нет — осознанная граница D99).

**Реализация (Deliverables):**

- `config/ai.php` — `max_output_tokens` только у `gemini-3.5-flash` (65536).
- `LlmRouteResolver::toResolvedRoute()` — бюджет из `options.max_tokens`, fail fast
  (`assertWithinOutputCeiling()`) при превышении известного потолка; `thinking_level` →
  `LlmOptions::$thinkingLevel`. Попутно найден и исправлен смежный дефект: `(array) $route->options`
  на заполненной `AsCollection`-коллекции отдаёт мангл-имена protected-свойств, а не данные —
  до P2.9 не проявлялся, потому что `options` был `null` у ВСЕХ строк сидера; заменено на
  `$route->options?->all() ?? []`.
- `LlmOptions::$thinkingLevel` — новое последнее необязательное поле.
- `PrismGeminiProvider::providerOptions()` — эмитит `thinkingLevel`, когда задан (НЕ
  `thinkingBudget`).
- `PrismGeminiProvider::completeStructured()` — узкий перевод: `PrismException` с вендорской
  сигнатурой `"Gemini hit token limit with high thinking token usage."` → `LlmStructuredResponse
  (finishReason: FinishReason::MaxTokens)` с числами, разобранными regex'ом из текста исключения
  (best effort, не разобралось → нули); любой другой `PrismException` пробрасывается без
  изменений.
- `AiRoutingSeeder` — `options = ['max_tokens' => 65536, 'thinking_level' => 'LOW']` на всех
  шести extraction-строках (три purpose × два приоритета).
