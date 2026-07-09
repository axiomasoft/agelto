---
audience: dev
type: explanation
status: draft
---

# Промт-система

Здесь будет: файлы `resources/ai/prompts/**` → сид `PromptSeeder` → `is_protected` (D17),
composite versioning (промт + модель + схема, `rules_hash`), как писать промты (выжимка правил v1,
research/B §4).

## Д3 — композиция промта

```mermaid
sequenceDiagram
    participant Job as ScreeningJob / EstimateExtractionJob
    participant Composer as PromptComposer
    participant Repo as PromptReadRepository
    participant Schema as PromptContextGenerator
    participant Corr as CorrectionAwareComposer

    Job->>Composer: compose(step, schemaClasses)
    Composer->>Repo: activeForSteps([Global, step-global?, step])
    Repo-->>Composer: правила, priority ASC
    Composer->>Schema: generate(schemaClasses)
    Schema-->>Composer: очищенный PHP-исходник Data-контрактов<br/>(докблоки/#[Alias] как инструкции)
    Note over Composer: systemPart = правила + блок схем<br/>rulesHash = sha1(systemPart)
    Composer-->>Job: ComposedPrompt(systemPart, rulesHash, promptVersionSet)
    rect rgb(220, 235, 250)
        Note over Composer: ГРАНИЦА КЭШИРУЕМОГО ПРЕФИКСА (D8/D14)<br/>systemPart детерминирован между вызовами —<br/>implicit-кэш провайдера экономит ~90% на повторе
    end
    opt re-run анализа (D15) или reusable-коррекции по document_type
        Job->>Corr: correctionsBlock(analysis)
        Corr-->>Job: "Замечания оператора к предыдущему разбору:\n- ..."<br/>(USER-часть, НЕ входит в кэшируемый префикс)
    end
```

Композиция: `App\Services\Llm\PromptComposer::compose()` (`app/Services/Llm/PromptComposer.php:30`) —
порядок Global → step-global (`ScreeningGlobal`/`ExtractionGlobal`) → сам шаг, `priority` ASC внутри
каждого уровня (канон v1, `PromptComposer::stepsForComposition()`). Блок схем —
`App\Services\Llm\Schema\PromptContextGenerator::generate()`
(`app/Services/Llm/Schema/PromptContextGenerator.php:23`) — обёртки шага (суффикс `Response`) всегда
последними. Коррекции — `App\Services\Llm\CorrectionAwareComposer::correctionsBlock()`
(`app/Services/Llm/CorrectionAwareComposer.php:26`) — намеренно USER-часть, не системная (иначе
ломался бы стабильный префикс кэша).

Наполняется в P10.3 (развёрнутый текст: как писать промты, composite versioning).
