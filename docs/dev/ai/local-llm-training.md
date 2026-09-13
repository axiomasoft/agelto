---
audience: dev
type: howto
status: living
---

# Обучение локальной LLM для извлечения смет (задел, P9.4)

> Экономика и риски перехода на локальную модель зеркалятся в Brain по решению владельца D89.
> Инструкция фиксирует стек research/G §3 (июль 2026), чтобы обучение мог выполнить исполнитель
> без контекста этой сессии. Само обучение и деплой vLLM — **вне плана EST-AI**, это инструкция
> «как», а не выполненная работа.

## 1. Когда запускать

- Вход — экспорт `ai:dataset:export --format=sft` (P9.3): JSONL chat-format, `system` — боевая
  композиция промта (`PromptComposer`), `assistant` — gold-строки, `user` — воспроизводимая ссылка
  на документ (OCR/vision-транскрипция реального текста в `user` — **не реализована**, Pending
  Work P9.3; на момент обучения либо реализовать транскрипцию, либо обучать text-LLM на уже
  распознанном тексте из другого источника).
- Достаточный объём — **перепроверить на момент обучения**: research/G §3 даёт диапазон
  1–3k примеров «документ → JSON» как sweet-spot для QLoRA SFT под одну JSON-схему (сотни уже
  сдвигают метрику); внешние источники по другим доменам называют 5k–50k при высокой вариативности
  входа — наша схема статична (D2, канон DTO), но реальная вариативность смет (форматы, качество
  сканов) может оказаться выше документной схемы.

## 2. Стек (D16, перепроверить актуальность линейки перед стартом)

| Слой | Модель (июль 2026) | Дообучение |
|:--|:--|:--|
| Vision (страница → полуструктура) | Qwen2.5-VL-7B (Apache-2.0) или MiniCPM-V 2.6 8B | БЕЗ дообучения |
| Текст (распознанный текст → JSON по нашей схеме) | Qwen3-4B/8B (Apache-2.0) | QLoRA SFT |
| Serve | vLLM | `structured_outputs` (backend `xgrammar`), НЕ deprecated `guided_json` |

Обоснование: fine-tune самого VLM требует десятков тысяч примеров (вне бюджета) — паттерн research/G
«VLM без дообучения + маленький текстовый LLM дообучен под конкретную JSON-схему» даёт нужное
качество на 1–3k примеров. Перед стартом обучения свериться, что линейка Qwen3/vLLM всё ещё
актуальна (модели и инструменты меняются быстро) — research/G зафиксирован на июль 2026.

## 3. Инструменты

- **LLaMA-Factory** — самый широкий охват (в т.ч. VLM-конфиги, если понадобится); стартовая точка,
  если нужен единый инструмент для обеих стадий.
- **Unsloth** — быстрейший QLoRA для чисто текстовых моделей (наш случай, text-LLM «текст → JSON»),
  вероятно предпочтительный выбор для узкой SFT-задачи.
- **axolotl** — хуже поддерживает Qwen-VL «из коробки» (research/G), не первый выбор.

## 4. Конфиг-скелет (Unsloth, ориентир — свериться с актуальной докой на момент запуска)

```python
from unsloth import FastLanguageModel

model, tokenizer = FastLanguageModel.from_pretrained(
    model_name="unsloth/Qwen3-4B-Instruct",  # свериться с актуальным именем/веткой на HF
    max_seq_length=8192,       # системная часть промта (схема + правила) может быть длинной
    load_in_4bit=True,         # QLoRA
)

model = FastLanguageModel.get_peft_model(
    model,
    r=16,                       # LoRA rank — отправная точка, калибровать по eval
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"],
    lora_alpha=16,
    lora_dropout=0,
)

# Датасет — JSONL из `ai:dataset:export --format=sft` (chat-format: system/user/assistant),
# грузится напрямую как HF `datasets.load_dataset("json", data_files=...)` + чат-темплейт модели.
```

Отложенный eval — 200–300 примеров, не пересекающихся с обучающей выборкой (research/G §3);
сравнивать через тот же `EvalMetrics`/`ai:eval:run`, что использует боевой пайплайн (P7.4) — метрики
сопоставимы напрямую.

## 5. Serve и подключение к домену

- `vllm serve <model> --enable-prefix-caching` — **флаг ЯВНЫЙ**, не полагаться на дефолт (источники
  расходятся: research/G «APC default True в V1 engine» vs внешний источник «выключен» — research/L
  finding 11; проверить фактическое поведение на используемой версии vLLM перед проверкой на
  боевом трафике).
- `--structured-outputs-config` / backend `xgrammar` (актуальное имя параметра — свериться с
  `vllm serve --help` на момент запуска, `guided_json` **deprecated**, удаление в v0.12).
- Подключение к домену — `prc_ai_providers` (driver=`openai_compatible`, `base_url` на
  `http://<vllm-host>:8000/v1`) + `prc_ai_routes` строка с `shadow=true` (сравнение с Gemini ДО
  переключения) или `shadow=false`/`is_active=true` как основной маршрут (переключение) — код
  домена не меняется (`App\Services\Llm\Providers\OpenAiCompatibleProvider`, P9.4).

## 6. DPO (второй шаг, после SFT в проде)

`ai:dataset:export --format=dpo` (P9.3) уже даёт `chosen`(gold)/`rejected`(raw-предшественник) пары.
Research G §3: SFT — прод-релиз, DPO вторым шагом поверх даёт измеримый прирост (пример из смежного
домена: 0.738→0.789 Macro-F1) — для нормализации наименований/формулировок, не для базовой
структуры JSON (та уже гарантирована `structured_outputs`).
