# Отчёт G — LLM-транспорт, кэширование провайдеров, fine-tune, feedback-loop (июль 2026)

## 1. laravel/ai vs Prism
laravel/ai: официальный first-party, анонс февраль 2026, актуально v0.8.1 (2026-06-10), ВСЁ ЕЩЁ 0.x/preview. Провайдеры: OpenAI, Anthropic, Gemini, Azure, Bedrock, Groq, xAI, DeepSeek, Mistral, Ollama, OpenRouter; Files: OpenAI/Anthropic/Gemini. Structured output: JSON Schema builder (Illuminate\Contracts\JsonSchema), anyOf, вложенность. Custom base_url: ключ url в config/ai.php per-provider (vLLM через openai-driver). Gemini cachedContents: ТОЛЬКО через generic providerOptions() (сырой payload, typed API нет). Кастомный провайдер-контракт публично не документирован. Риск миграций высокий (v0.8 за 5 мес).
Prism: v0.91.0 (окт 2025), 0.x, но ~2 года прод-использования, API устоялся. Явная Gemini-документация (files + caching typed). Custom url на провайдера, документированная driver-архитектура.
ВЫВОД: сейчас Prism за фасадом LlmProvider (typed Gemini caching/files + тривиальный OpenAI-совместимый base_url); laravel/ai пересмотреть после 1.0 (вероятный долгосрочный победитель) — контракт держать адаптер-совместимым с обоими. Расхождение: глубину typed-поддержки cachedContents в Prism сверить с prismphp.com/providers/gemini перед финальным выбором.

## 2. Кэширование у других провайдеров
- OpenAI: АВТОМАТИЧЕСКИЙ, промпты ≥1024 ток., самый длинный посчитанный префикс (шаг 128), роутинг по хэшу первых ~256 ток. + опциональный prompt_cache_key. Скидка до 90% cached input. TTL: prompt_cache_retention in_memory (5–10 мин до 1 ч) или 24h.
- Anthropic: ЯВНЫЙ cache_control {type: ephemeral}, до 4 breakpoints, минимум 1024 ток. (Sonnet/Opus) / 2048 (Haiku). Write 1.25× (5 мин TTL) или 2× (1 ч), read 0.1× (−90%). Break-even 2 чтения. TTL задавать явно.
- Gemini: implicit по умолчанию 2.5+ (Developer API скидка 75%, Vertex 90% — платформы различаются); explicit cachedContents: write по input-цене + storage ~$4.50/1M ток./час (Pro). Минимум 2048 ток. (2.5 Flash/Pro).
- vLLM: Automatic Prefix Caching default TRUE в актуальном V1 engine (проверено по docs.vllm.ai; Perplexity ошибся), бесплатно. SGLang RadixAttention — встроен.
АБСТРАКЦИЯ: три вида кэша — (1) автоматический (OpenAI/Gemini implicit/vLLM) → требуется только prefix-stable промт; (2) breakpoints в запросе (Anthropic); (3) внешний ресурс с lifecycle (Gemini cachedContents). Контракт: (а) договорённость prefix-stable всегда; (б) опциональный cache-hint («блок тяжёлый и переиспользуемый») — Gemini-адаптер → cachedContents (lifecycle внутри адаптера), Anthropic → cache_control, OpenAI → prompt_cache_key, vLLM → no-op; (в) usage.cachedTokens в ответе. Lifecycle — деталь адаптера, не домена.

## 3. Fine-tune локальной модели «смета → JSON»
- VLM (открытые веса, июль 2026): топ Qwen2.5-VL-72B (OCRBench≈888), InternVL3-78B (≈865, MIT); практичные: Qwen2.5-VL-7B (≈864, Apache-2.0), InternVL3-8B (≈830), MiniCPM-V 2.6 8B (≈852). Спец-PDF: MinerU2.5 (~1.2B). Тренд: VLM вытесняет OCR+эвристики.
- Текстовые малыши для «распознанный текст → JSON»: Qwen3-4B/8B/14B (Apache-2.0; Qwen3-4B — лучшая база для extraction-дообучения), Gemma 3 4B–12B.
- SFT vs DPO: прод-релиз — SFT; DPO вторым шагом поверх (SemEval-2026: 0.738→0.789 Macro-F1). Для extraction DPO позже (нормализация наименований).
- Объём: QLoRA SFT под одну JSON-схему — сотни примеров уже сдвигают; sweet-spot 1–3k «документ → JSON», 200–300 в отложенный eval. Fine-tune самого VLM — десятки тысяч примеров → паттерн: VLM БЕЗ дообучения (страница → полуструктура), тюнить только маленький text-LLM.
- Формат: JSONL chat-format (system: инструкция+схема; user: текст документа; assistant: gold JSON).
- Инструменты: LLaMA-Factory (широчайший охват, VLM-конфиги), Unsloth (быстрейший QLoRA для текстовых), axolotl (Qwen-VL хуже из коробки).
- Serve: vLLM structured outputs production-ready; guided_json DEPRECATED (удаление в v0.12) → новый structured_outputs-интерфейс с backend xgrammar — JSON гарантированно валиден по схеме.
ВЫВОД: целевой стек — Qwen2.5-VL-7B/MiniCPM-V 2.6 vision-слой без дообучения + Qwen3-4B/8B QLoRA-SFT на 1–3k размеченных смет + vLLM structured_outputs (xgrammar). Датасет копить уже сейчас в JSONL chat-format. Контракт покрывает через OpenAI-совместимый адаптер.

## 4. Feedback-loop из операторских правок (Google DocAI HITL, Azure DI, Label Studio, Argilla/Distilabel)
- РАЗДЕЛЯТЬ сырой вывод модели и верифицированный. Каноничная модель: extraction_run (raw_model_output, model_version, prompt_version, confidence) → human_review_session (reviewer, status, verified_flag, comments) → field_correction (field_path JSONPath, original_value, corrected_value, correction_reason enum, comment) → gold_example (IMMUTABLE snapshot gold_output + schema_version/prompt_version/model_version). Gold создаётся при verified_flag=true.
- Уровни золота: tier 1 — полностью проверено (только оно в базовый SFT); tier 2 — проверены low-confidence поля (меньший вес); tier 3 — только бизнес-валидация.
- DPO preference pairs из правок: prompt=вход, chosen=gold после правок, rejected=raw output до правок (формат Argilla/Distilabel).
- Re-run with hints: правки порождают structured hints (schema_hint: doc_type, field, hint_type keyword/proximity/regex, payload); при повторе hints + few-shot из gold этого типа подмешиваются в промпт; confidence-based routing на ревью. Авто-retrain никто не даёт — цикл «правят → агрегируем → периодический retrain» строится самим.
- Версионирование: датасет — immutable bundle (данные + schema_version + prompt_version + model_family); схемы/шаблоны в Git (в БД version_id + sha); смена схемы → новый dataset_version с миграцией старых gold; каждый training-run фиксирует version_id.
ВЫВОД для Agelto: (1) field_correction с JSONPath+reason enum вдобавок к свободному комментарию; (2) verified flag на смете + immutable gold-снапшот с версиями; (3) хранить raw output неизменным рядом с исправленным (готовые chosen/rejected); (4) корректирующие промты — versioned hints, привязанные к doc_type/полю (переиспользуются на inference и как обучающие данные); (5) экспорт tier-1 gold в JSONL — прямой вход SFT.

## Зафиксированные расхождения
1. vLLM APC «не по умолчанию» (Perplexity) — НЕВЕРНО, default True (первоисточник docs.vllm.ai).
2. Скидка Gemini caching 75% (Developer API) vs 90% (Vertex) — обе верны для своих платформ.
3. «Anthropic тихо сменил дефолт TTL март-2026» — один неофициальный источник; официально дефолт всегда 5 мин. TTL задавать явно.
4. Typed-поддержка Gemini cachedContents в Prism — сверить с prismphp.com/providers/gemini перед выбором.
