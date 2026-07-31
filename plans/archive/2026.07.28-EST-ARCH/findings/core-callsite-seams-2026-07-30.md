# findings — сеймы развязки двух ядерных call-site'ов сметы и ревизия формы гейта (г)

Слой 1 (`plan-protocol` §16). Разведка репозитория, снятая заходом `plan-design P2.6` 2026-07-30
после решения владельца «путь A + опциональный capability-интерфейс». Продолжает
`findings/core-arch-gate-2026-07-30.md` (тот снял ФАКТ нарушения; этот снимает ЦЕНУ развязки и
проверяет форму гейта на ложные срабатывания).

**Якорь фактов:** весь код читан на `753cc0b` (ветка `main`; после `11a9fe8` в `app/` изменений
нет — `753cc0b` несёт только плановые файлы, `git show --stat 753cc0b`). Все `файл:строка` ниже
сняты на этом коммите.

## 1. Инвентарь потребителей — оба нарушителя развязываются локально

### 1.1. `RowsChunkResponse` в ядре — РОВНО один call-site

`grep -rn "RowsChunkResponse" app/ --include=*.php` (на `753cc0b`) даёт 15 строк; из периметра
ядра §6.2 контракта — **только две, в одном файле**:

| Файл : строка | Что |
|:--|:--|
| `app/Services/Llm/Validation/StructuredResponseValidator.php:7` | `use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;` |
| `app/Services/Llm/Validation/StructuredResponseValidator.php:78` | `if (! $data instanceof RowsChunkResponse) { return null; }` |

Остальные 13 — внутри каталога анализатора (`Analyzers\Estimate\{WindowedChunkSource,
RowRangeChunkSource,ExtractionOrchestrator,RerunRangeAction,Dto\ChunkFetchResult}`), в
`app/Console/Commands/Ai/DatasetExportCommand.php` (периметр §6.2 её НЕ включает — снимает P3) и в
самом DTO. То есть развязка ядра не задевает ни одного потребителя, кроме валидатора.

**Ключевой факт для выбора сейма:** у `StructuredResponseValidator::validate()` типа документа НЕТ
и быть не может — сигнатура `validate(LlmStructuredResponse $response, string $dataClass)`
(`:27`), тип документа в неё не передаётся ни одним из вызывающих. Значит маршрутизация проверки
через `DocumentAnalyzerRegistry` в этой точке невозможна ПО ПОСТРОЕНИЮ: единственная доступная
ядру идентичность предмета — сам класс DTO. Отсюда сейм — интерфейс, реализуемый DTO ОТВЕТА, а не
метод анализатора.

**Тест-паритет уже существует.** `tests/Unit/Llm/StructuredResponseValidatorTest.php:107,123,139`
проверяют ровно эти три исхода (дубликат `row_index` → retryable; немонотонность → retryable;
валидный чанк → ok) ЧЕРЕЗ валидатор. Если сообщения об ошибке переезжают побайтово, эти три теста
остаются зелёными БЕЗ правки — это и есть машинное доказательство паритета переезда.

### 1.2. `EvalMetrics` в ядре — РОВНО один call-site, плюс скрытое знание о форме payload'а

`grep -rn "EvalMetrics" app/ --include=*.php`: из периметра §6.2 — **только
`app/Jobs/Procurement/ShadowAnalysisJob.php`** (`:18` импорт, `:78`/`:96` инъекция в сигнатуры,
`:188` параметр `compareWithPrimary()`, `:197` вызов `compare()`).

Прочие потребители периметру §6.2 НЕ принадлежат и P2.7 не задеваются:

| Потребитель | Почему вне периметра |
|:--|:--|
| `app/Console/Commands/Ai/EvalRunCommand.php:25,71` | `App\Console\Commands\Ai` намеренно вне §6.2 (снимает P3.3) |
| `app/Services/Procurement/Bench/FormatBenchRunner.php:32,85` | `Services\Procurement\Bench` намеренно вне §6.2 (снимает P3) |
| `Analyzers\Estimate\EstimateDiffReport` | сам каталог типа |
| 5 тестов | не продуктовый код |

**Третий факт, которого не было в `core-arch-gate-2026-07-30.md`:** ядро знает не только ИМЯ
сметного класса, но и ФОРМУ сметного payload'а —

```text
app/Jobs/Procurement/ShadowAnalysisJob.php:190:  $primaryEstimates = (array) ($primary->result['estimates'] ?? []);
app/Jobs/Procurement/ShadowAnalysisJob.php:191:  $shadowEstimates  = (array) ($shadowData['estimates'] ?? []);
```

Ключ `'estimates'` — предметная структура результата сметы, разбираемая ЯДЕРНЫМ job'ом. Гейт (г)
контракта его не ловит (шаблон — `\bEstimate\b` и литерал `'estimate'`, а здесь `'estimates'`),
поэтому факт называется здесь. Вывод для сейма: за интерфейс уходит ВЕСЬ разбор, то есть метод
получает СЫРЫЕ payload'ы, а не пред-извлечённые `estimates[]`.

`grep -rn "'estimates'"` по всему периметру §6.2 (60 файлов, команда — §3) даёт РОВНО эти две
строки: других мест, где ядро разбирает форму результата сметы, нет.

### 1.3. Что технически нужно для сейма сравнения — уже на месте

- `ShadowAnalysisJob` УЖЕ инъектирует `DocumentAnalyzerRegistry` (`:79`, `:97`) и УЖЕ зовёт
  `$registry->purposeFor(...)` (`:99`) — новой зависимости у job'а не появляется, `EvalMetrics`
  из сигнатур просто уходит.
- Тип документа у job'а есть: `public readonly AiDocumentType|DocumentTypeScope $documentType`
  (`:66`). `DocumentTypeScope` — сентинел «любой тип» (`app/Enums/Llm/DocumentTypeScope.php`,
  один кейс `Any`), у него анализатора нет по построению ⇒ ветка `null` обязана быть явной.
- `DocumentAnalyzerRegistry::for()` принимает РОВНО `AiDocumentType` (`:for(AiDocumentType
  $documentType): ?DocumentAnalyzer`) — на `DocumentTypeScope` вызывать нельзя, нужен
  `instanceof`-гейт.
- Анализатор резолвится контейнером (`$container->make($class)` в конструкторе реестра),
  поэтому `EstimateAnalyzer` может взять `EvalMetrics` конструкторной инъекцией: сегодня у него
  конструктора нет вовсе (`app/Services/Procurement/Analyzers/Estimate/EstimateAnalyzer.php:27`).
- `Analysis::$result` — каст `AsCollection` (`app/Models/Procurement/Ai/Analysis.php:89`), то есть
  `Illuminate\Support\Collection|null`. **`(array) $collection` НЕЛЬЗЯ** — приведение объекта к
  массиву вернёт его свойства (`items`, `escapeWhenCastingToString`), а не элементы; нужен
  `->all()`. Сегодняшний код этой ловушки не касался, потому что читал через `ArrayAccess`
  (`$primary->result['estimates']`), а не приводил целиком.
- `phpstan-baseline.neon` записей по `ShadowAnalysisJob`, `StructuredResponseValidator` и
  `EstimateAnalyzer` НЕ несёт (`grep -n` по файлу — пустой вывод), поэтому вычитающей дельты D71
  на этих файлах не ожидается.

### 1.4. Существующая идиома проекта для опционального capability-интерфейса

`ls app/Services/Llm/Contracts/` → `LlmProvider.php`, `SupportsFileUpload.php`,
`SupportsContextCaching.php`. То есть «основной порт + опциональные `Supports<Способность>`,
спрашиваемые через `instanceof`» — не изобретение, а действующий канон этого репозитория,
закреплённый вплоть до правил ревью (`.claude/rules/agelto-reviewer-overrides.md`: домен ходит
«только через `Contracts\LlmProvider` и его опциональные `SupportsFileUpload`/
`SupportsContextCaching`»). Имя нового интерфейса берётся из этой же формы.

## 2. Ревизия формы гейта (г): почему шаблон НЕ ужесточается

Проба на периметре §6.2 (60 классов, команда — §3), из каждого исходника вырезан законный
`AiDocumentType::Estimate`:

| Шаблон | Вывод на `753cc0b` | Годен как гейт |
|:--|:--|:--|
| `\bEstimate\b` \| `'estimate'` — **буква контракта §6.3(г)** | **РОВНО 2 строки** — `StructuredResponseValidator.php:7` и `ShadowAnalysisJob.php:18` | **да**: красный сейчас, пустой после P2.7 |
| `\bestimates?\b` регистронезависимо | **13 строк**: те же 2 + 8 докблочных ссылок на нормативную спеку `docs/specs/v2/08-estimate-extraction.md` (`PdfPageSlicer:12`, `ExtractionSession:13`, `ExtractionPreflight:14`, `Dto\PreflightDecision:11`, `Exceptions\MaxTokensExceededException:13`, `Schema\JsonSchemaGenerator:16`, `Schema\PromptContextGenerator:11`) + `ScreeningOutcomeHandler:229` (историческая v1-ссылка `config('platform.estimate.ignore_rules')` в докблоке; живой код читает `ai.screening.ignore_rules`, `:238`) + `ShadowAnalysisJob:40,190,191` | **нет**: 9 из 13 — законные вхождения, гейт без зелёного исхода |

Вывод: **форма (г) остаётся буквой контракта** — регистрочувствительный `\bEstimate\b` плюс
литерал `'estimate'`. Ужесточение шаблона было бы НОВЫМ ограничением поверх замороженного
контракта, то есть расфризом, а не исполнением (прямой прецедент — D49, отказавшийся расширять
периметр ради витрины), и вдобавок гейтом без достижимого зелёного (класс D89): ссылка на
нормативную спеку в докблоке ядра законна и остаётся.

Ключ `'estimates'` (§1.2) при этом не остаётся долгом: P2.7 уносит за интерфейс ВЕСЬ разбор
payload'а, поэтому обе строки исчезают вместе с импортом — гейт (г) их не видит, но и ловить их
после P2.7 не придётся.

## 3. Команды воспроизведения

Периметр ядра (60 классов на `753cc0b`; `App\Jobs\Procurement` — без подкаталога `Analyzers`,
правило вычитания §6.2):

Команды самодостаточны и НЕ пишут временных файлов (`plan-portability-v1.md`: внешний путь вида
`/tmp/**` в execution input плана — ошибка линтера).

```bash
# периметр ядра как функция шелла
core_classes() {
  for d in app/Services/Procurement/Screening app/Services/Procurement/Extraction \
           app/Services/Llm app/Services/Procurement/Contracts \
           app/Enums/Llm app/Repositories/Llm app/Models/Llm; do find "$d" -name '*.php'; done
  find app/Jobs/Procurement -maxdepth 1 -name '*.php'
}
core_classes | wc -l                                    # 60

# гейт (г) в форме контракта §6.3 — 2 строки на 753cc0b
core_classes | while read -r f; do sed 's/AiDocumentType::Estimate//g' "$f" \
  | grep -nE "\bEstimate\b|'estimate'" | sed "s|^|$f:|"; done

# проба ужесточённой формы (регистронезависимо) — 13 строк, 9 законных
core_classes | while read -r f; do sed 's/AiDocumentType::Estimate//g' "$f" \
  | grep -inE '\bestimates?\b' | sed "s|^|$f:|"; done
```

Импорты (а)/(б)/(в) грепом, без Pest (форма для `Validation` P2.7, где arch-теста ещё нет):

```bash
grep -rn 'App\\Dto\\Procurement\\Ai\\Estimate\|App\\Services\\Procurement\\Analyzers\|App\\Models\\Procurement\\Estimate' \
  app/Services/Llm/ app/Services/Procurement/Screening/ app/Services/Procurement/Extraction/ \
  app/Services/Procurement/Contracts/ app/Enums/Llm/ app/Repositories/Llm/ app/Models/Llm/ \
  app/Jobs/Procurement/*.php
```

На `753cc0b` — 2 строки (те же), после P2.7 — пусто. Форма несёт `-r` при аргументах-каталогах
(D81) и glob `app/Jobs/Procurement/*.php` вместо каталога, чтобы не спускаться в
`Analyzers/Estimate/` (правило вычитания §6.2 без `->ignoring()`, чья ловушка разобрана в
`core-arch-gate-2026-07-30.md` §2).
