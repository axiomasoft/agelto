---
audience: dev
type: explanation
status: draft
---

# LLM-провайдеры

Здесь будет: контракт `LlmProvider`, адаптеры (Gemini/OpenAI-совместимый), роутинг `prc_ai_routes`,
capability-based кэширование по провайдерам (D14), модели EOL/successor (D27).

## Д6 — LLM-слой: роутинг и адаптеры

```mermaid
flowchart TB
    Caller["ScreeningProcessor / ExtractionOrchestrator"]
    Resolver["AiRouteResolver::resolve(documentType, purpose)<br/>(app/Services/Llm/AiRouteResolver.php:45)"]
    Routes[("prc_ai_routes<br/>priority ASC, activeCandidatesFor()")]
    Resolved["ResolvedRoute(provider, LlmOptions, route)"]
    Gemini["PrismGeminiProvider<br/>(app/Services/Llm/Providers/PrismGeminiProvider.php)"]
    OpenAiCompat["OpenAiCompatibleProvider<br/>(app/Services/Llm/Providers/OpenAiCompatibleProvider.php)<br/>vLLM/Ollama, stateless base_url/api_key"]
    Contract{{"LlmProvider contract<br/>(app/Services/Llm/Contracts/LlmProvider.php)"}}
    FileUpload{{"SupportsFileUpload"}}
    Cache{{"SupportsContextCaching"}}
    Usage["LlmUsage.cachedTokens<br/>(обязателен в ответе, D14)"]

    Caller --> Resolver
    Resolver --> Routes
    Routes --> Resolved
    Resolved -->|driver=gemini| Gemini
    Resolved -->|driver=openai_compatible| OpenAiCompat
    Gemini -.implements.-> Contract
    OpenAiCompat -.implements.-> Contract
    Gemini -.implements.-> FileUpload
    Gemini -.implements.-> Cache
    Contract --> Usage
```

Capability-based кэширование (D14): промт всегда prefix-stable (`PromptComposer`); опциональный
cache-hint интерпретируется адаптером — Gemini → `cachedContents` (`SupportsContextCaching`,
частично подключён — см. Known Deviations `ExtractionOrchestrator`), OpenAI/vLLM → no-op/
автоматический prefix-cache; `usage.cachedTokens` обязателен в ответе независимо от адаптера.
`shadowRoute()` (`app/Services/Llm/AiRouteResolver.php:74`) — теневой маршрут для сравнения
с локальной LLM (P9.4, `ShadowDispatch`).

Наполняется в P10.3 (развёрнутый текст: контракт, учёт стоимости, модели EOL D27).
