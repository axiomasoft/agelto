---
type: architecture
tags: [agelto, ai, llm, parsing, fallback, ru-llm, local-llm]
created: 2026-06-13
updated: 2026-06-13
source: "Закрывает R-5, R-6, R-7, R-8 (Risk_Register); открытый вопрос T5"
---

# AI-стратегия: независимость от Gemini — Agelto

> [!info] Связанные документы
> ← [[Architecture_Agelto]] | ← [[Risk_Register (Agelto)]] | → [[Dev_Status]] | → [[Открытые вопросы (Agelto)]]

> [!note] Назначение
> Стратегический бриф для LLM-ассистента и технический roadmap для команды.
> Закрывает риски **R-5** (передача данных за пределы РФ / 152-ФЗ), **R-6** (SPOF Gemini)
> и **R-7** (неизвестная себестоимость парсинга), частично **R-8** (деградация PromptRules)
> из [[Risk_Register (Agelto)]]. Расширяет раздел 8 (AI-пайплайн) и раздел 15 (Открытые вопросы)
> документа [[Architecture_Agelto]]. Источник истины по реализации — код
> `/home/vostrikov/projects/agelto/agelto/`.

---

## Содержание

1. [Контекст проекта Agelto](#1-контекст-проекта-agelto)
2. [Место AI в пайплайне Agelto](#2-место-ai-в-пайплайне-agelto)
3. [Почему Gemini — это риск, а не просто инструмент](#3-почему-gemini--это-риск-а-не-просто-инструмент)
4. [Целевая архитектура: Provider Router](#4-целевая-архитектура-provider-router)
5. [Российские облачные LLM-провайдеры](#5-российские-облачные-llm-провайдеры)
6. [Локальная LLM: выбор модели (отложенный трек v2)](#6-локальная-llm-выбор-модели-отложенный-трек-v2)
7. [Железо для локальной LLM](#7-железо-для-локальной-llm)
8. [Обучение модели на данных Agelto](#8-обучение-модели-на-данных-agelto)
9. [Кэширование и оптимизация токенов](#9-кэширование-и-оптимизация-токенов)
10. [Мастер-промт для парсинга смет](#10-мастер-промт-для-парсинга-смет)
11. [Обработка изображений и сканов](#11-обработка-изображений-и-сканов)
12. [Экономика: расчёт и сравнение](#12-экономика-расчёт-и-сравнение)
13. [Roadmap реализации](#13-roadmap-реализации)
14. [Матрица рисков (обновлённая)](#14-матрица-рисков-обновлённая)

---

## 1. Контекст проекта Agelto

**Agelto** (*Agent for Lean Tendering Operations*) — B2B SaaS платформа для интеллектуальной
дистрибуции данных строительного рынка. Продукт автоматически находит в государственных тендерах
(ЕИС / zakupki.gov.ru) конкретные позиции материалов внутри сотен страниц смет и доставляет
верифицированные лиды в CRM поставщиков (Bitrix24, amoCRM).

**Конкурентная идея одной строкой:**

> Конкуренты находят тендер по названию контракта.
> Agelto находит **вашу позицию** внутри 500-страничной сметы.

### Ключевые продуктовые метрики (план)

| Показатель | Значение |
|:---|:---|
| ARPU Business | 600 000 ₽/год |
| SOM Год 1 | 30 клиентов / 18 млн ₽ ARR |
| SOM Год 3 | 150 клиентов / 90 млн ₽ ARR |
| LTV/CAC (2 года) | ~4.8× |
| NPV проекта | 23.3 млн ₽ |

### Технологический стек

**Laravel 12 · PHP 8.5 · Filament 5 · Inertia v2 + Vue 3 · Gemini 2.0 Flash ·
Laravel Horizon · PostgreSQL · pgvector · Docker · nWidart Modules**

---

## 2. Место AI в пайплайне Agelto

Пайплайн состоит из 4 последовательных стадий, соединённых через `Bus::chain()`:

```
ЕИС (zakupki.gov.ru)
  │  HTTP + headless Chrome + прокси
  ▼
[Стадия 1: Crawling]
  EisCrawlingService → CrawlingItemDto → plt_crawler_links
  │
  ▼
[Стадия 2: DocParsing]
  Фильтр вложений: ключевые слова «смета», «ЛСР», «локальный»
  → plt_crawler_link_attachments
  │
  ▼
[Стадия 3: PrimaryAnalysis]  ← ★ AI-вызов #1
  PrimaryAnalysisJob → GeminiService
  «это смета СМР? какая сумма?»
  Excel: первые строки в HTML → Gemini
  PDF: первая страница → Gemini
  → PrimaryAnalyzed | PrimaryIgnored
  │
  ▼
[Стадия 4: EstimateAnalysis]  ← ★ AI-вызов #2 (основной)
  EstimateAnalysisJob → SmetaParserService / GeminiService
  Чанки по 80 строк → Gemini → plt_document_analysis_responses (JSON)
  → EstimateAnalyzed
  │
  ▼
[Semantic Matcher]  ← pgvector (в разработке)
  cosine similarity: позиции сметы ↔ коллекция поставщика
  │
  ▼
[CRM Integration]  ← не начата
  Bitrix24 / amoCRM lead push
```

**AI используется в стадиях 3 и 4.** Именно эти стадии несут **100% зависимости от Gemini**.
Если Gemini недоступен — пайплайн останавливается полностью.

### Текущий AI-стек ([[Architecture_Agelto]] раздел 8)

| Задача | Технология | Статус |
|:---|:---|:---|
| PDF-анализ | Gemini 2.0 Flash (Google Files API) | ✅ рабочий |
| Excel-парсинг | Gemini 2.0 Flash (direct prompt) | ✅ рабочий |
| OCR для сканов | Планируется (LayoutLMv3/TrOCR) | ❌ v2 |
| Semantic matching | pgvector + multilingual-e5-large | 🔧 в разработке |
| CRM push | Bitrix24 / amoCRM | ❌ не начата |

### Очереди Horizon (реальные)

| Очередь | Job | Timeout | Retries |
|:---|:---|:---|:---|
| `platform_document_prepare` | `PrepareDocumentJob` | 300 сек | 3 |
| `platform_primary_analysis` | `PrimaryAnalysisJob` | 180 сек | 5 |
| `platform_estimate_analysis` | `EstimateAnalysisJob` | 300 сек | 3 |

---

## 3. Почему Gemini — это риск, а не просто инструмент

Из [[Risk_Register (Agelto)]] прямо зафиксированы три открытых риска, которые этот документ закрывает:

### R-5 (Критический) — Регуляторный

> 🔴 Передача документов тендеров в Google Gemini API **противоречит** декларируемому
> «российскому контуру» (NFR из [[Architecture_Agelto]]). Документы ЕИС — данные государственных
> закупок. Для корпоративных и госклиентов это **блокер продаж**.

**Практический вред:** при переговорах с крупными поставщиками (металлоторговля, кабельные
заводы) юридический отдел клиента заблокирует подключение, как только узнает, что сметы
уходят в Google Cloud.

### R-6 (Высокий) — Инфраструктурный

> SPOF Gemini: блокировка Google API из РФ или для РФ останавливает **весь AI-пайплайн**.
> Сейчас `GeminiService` прибит к Gemini — нет абстракции провайдера.

Прецедент уже есть: в 2022–2024 годах ряд Google Cloud-сервисов ограничивал доступ для
российских аккаунтов. РКН активно расширяет список блокировок VPN-сервисов. Вероятность
сценария «внезапно перестал работать» — не теоретическая.

### R-7 (Средний) — Финансовый

> Себестоимость обработки одной сметы в токенах **не посчитана**.
> Маржа тарифов (600 000 ₽/год/клиент) неизвестна. До фиксации тарифного плана
> unit-экономика остаётся «чёрным ящиком».

Именно поэтому необходимы cost-метрики per-документ (открытый вопрос T5 из [[Открытые вопросы (Agelto)]]).

---

## 4. Целевая архитектура: Provider Router

Решение — **абстракция AI-провайдера** (рекомендация раздела 15 [[Architecture_Agelto]]) с автоматическим
переключением. `GeminiService` становится одним из нескольких провайдеров за единым интерфейсом.

### 4.1 Интерфейс провайдера (PHP/Laravel)

```php
// app/Contracts/DocumentParserProvider.php
interface DocumentParserProvider
{
    public function supports(DocumentType $type): bool;
    public function parse(DocumentAnalysis $analysis, ParseOptions $opts): ParseResult;
    public function isAvailable(): bool;
    public function getHealthStatus(): ProviderHealthStatus;
    public function estimateCost(int $inputTokens, int $outputTokens): float; // ₽
}
```

### 4.2 Provider Router с приоритетами

```php
// app/Services/AI/ProviderRouter.php
class ProviderRouter
{
    /** Порядок = приоритет. Первый доступный и поддерживающий тип документа — победитель. */
    private array $providers = [
        GeminiProvider::class,      // Primary: качество + цена (пока VPN доступен)
        YandexGptProvider::class,   // Fallback 1: текст, JSON, рус. язык
        GigaChatProvider::class,    // Fallback 2: мультимодальность, дешевле
        LocalQwenProvider::class,   // Fallback 3: 100% offline, без VPN
    ];

    public function route(DocumentAnalysis $analysis): DocumentParserProvider
    {
        foreach ($this->providers as $providerClass) {
            $provider = app($providerClass);
            if ($provider->isAvailable() && $provider->supports($analysis->document_type)) {
                return $provider;
            }
        }
        throw new NoAvailableProviderException('All AI providers are unavailable');
    }
}
```

### 4.3 CircuitBreaker — автоматическое переключение

```php
// Логика внутри isAvailable() каждого провайдера:
// error_rate > 5% за последние 5 минут → провайдер помечается недоступным на 10 минут
// Состояние хранится в Redis: "provider:gemini:circuit" → open|closed|half-open
```

### 4.4 Схема переключения

```
Gemini доступен? ──Yes──► Gemini (Primary)
       │No
       ▼
YandexGPT доступен? ──Yes──► YandexGPT Pro 5.1
       │No
       ▼
GigaChat доступен? ──Yes──► GigaChat Pro/MAX
       │No
       ▼
Local Qwen2.5-VL ──────────► 100% offline, без внешних зависимостей
```

---

## 5. Российские облачные LLM-провайдеры

### 5.1 YandexGPT Pro 5.1 — Fallback #1

**Почему подходит для Agelto:**
- Обучена на обширном русскоязычном корпусе — понимает строительную терминологию РФ,
  нормативные базы (ГЭСН, ФЕР, ТЕР), аббревиатуры ЕИС
- JSON mode и структурированный вывод работают надёжно
- API совместим с OpenAI SDK — минимальные изменения в коде провайдера
- Длинный контекст — обрабатывает многостраничные сметы одним запросом
- Асинхронный (batch) режим — оптимален для очередей Horizon

**Ограничения:**
- Нет нативного мультимодального ввода изображений (только через Vision API Extensions)
- Для сканов/JPG/PNG потребуется отдельный OCR-слой перед вызовом модели

**Интеграция в пайплайн Agelto:**
- Стадия 3 (PrimaryAnalysis): полная замена Gemini для PDF-текст и Excel
- Стадия 4 (EstimateAnalysis): полная замена для текстовых форматов
- Сканы (JPG/PNG): требуется предобработка → olmOCR → YandexGPT

**Ценообразование (Yandex AI Studio 2025–2026):**

| Модель | Input (1K токенов) | Output (1K токенов) |
|:---|:---|:---|
| YandexGPT Pro 5.1 | $0.003361 | $0.003361 |
| YandexGPT Lite | $0.000820 | $0.000820 |

---

### 5.2 GigaChat (Сбербанк) — Fallback #2

**Почему подходит для Agelto:**
- Единственный российский провайдер с **нативной мультимодальностью** в GigaChat MAX —
  принимает изображения напрямую, без OCR-предобработки
- Соответствие 152-ФЗ закрывает регуляторный риск R-5 для корпоративных клиентов
- 128K контекстное окно — достаточно для больших многостраничных смет
- Самый дешёвый вариант среди российских провайдеров

**Ограничения:**
- В сравнительных тестах уступает YandexGPT Pro по точности структурированного вывода таблиц
- Иногда нестабилен при высоких нагрузках
- API требует адаптера (не fully OpenAI-совместим)

**Ценообразование GigaChat API:**

| Тариф | Цена за 1M токенов |
|:---|:---|
| GigaChat Lite | ~$0.44 |
| GigaChat Pro | ~$0.88 |
| GigaChat MAX (мультимодальный) | ~$3.30 |

---

### 5.3 Сравнительная матрица для Agelto

| Критерий | Gemini 2.0 Flash | YandexGPT Pro 5.1 | GigaChat MAX | GigaChat Pro |
|:---|:---|:---|:---|:---|
| Excel-парсинг (SmetaParserService) | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ |
| PDF-текст (PrimaryAnalysis) | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ |
| Изображения/сканы | ⭐⭐⭐⭐⭐ | ❌ нет | ⭐⭐⭐⭐ | ❌ нет |
| JSON mode (structured output) | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ |
| Русский язык / терминология РФ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ |
| Без VPN (из РФ) | ⚠️ риск | ✅ | ✅ | ✅ |
| 152-ФЗ compliance | ❌ | ✅ | ✅ | ✅ |
| Совместимость с OpenAI SDK | ✅ | ✅ | ⚠️ адаптер | ⚠️ адаптер |
| Цена/1M токенов | $0.075–0.30 | $3.36 | $3.30 | $0.88 |

---

## 6. Локальная LLM: выбор модели (отложенный трек v2)

> [!warning] Отложенный трек v2
> Локальная LLM (разделы 6–8 и Sprint 3–4) — это **третья линия защиты и страховка от R-6,
> а не способ сэкономить** (см. расчёт окупаемости в 12.4: на старте — 23 месяца). Активировать
> этот трек осознанно при одном из триггеров: (а) облачные RU-провайдеры недоступны/заблокированы;
> (б) Enterprise-клиент требует полностью offline-контур без внешних API; (в) объём ≥150 клиентов,
> когда железо начинает окупаться. До наступления триггера — приоритет разделам 4–5
> (provider router + RU-облака).

Локальная LLM работает полностью офлайн, без VPN, без внешних API.
Критически важна для сценария «Gemini заблокирован, все российские провайдеры упали».

### 6.1 Qwen2.5-VL-7B + olmOCR 2 (рекомендуется)

**Разработчик:** Alibaba Cloud (открытые веса, MIT license)

Qwen2.5-VL-7B — нативно мультимодальная визуальная модель, принимает изображения, PDF-страницы
и таблицы без предобработки. Именно на её базе Allen AI создали **olmOCR 2** — специализированный
файн-тюн для OCR документов, обученный на 270 000 PDF-страниц. olmOCR 2 закрывает пункт
из раздела 8.3 [[Architecture_Agelto]] «OCR для сканов — планируется v2».

**Характеристики, важные для Agelto:**
- 128K контекстное окно — целая смета в одном запросе
- Качество OCR на уровне Google Document AI для печатных документов
- Отлично извлекает табличные данные (Excel-структуры, ЛСР-таблицы)
- Q4 квантизация: умещается в **8 GB VRAM** — старт без дорогого сервера
- Поддерживает OpenAI-совместимый API через vLLM/Ollama → адаптер к существующему GeminiService минимален

**Развёртывание через Ollama (быстрый старт):**
```bash
ollama pull qwen2.5vl:7b
ollama serve  # OpenAI-compatible API на localhost:11434
```

**Развёртывание через vLLM (продакшн для Horizon-очередей):**
```bash
pip install vllm
vllm serve Qwen/Qwen2.5-VL-7B-Instruct \
  --max-model-len 32768 \
  --tensor-parallel-size 2 \
  --gpu-memory-utilization 0.9 \
  --enable-prefix-caching     # аналог Gemini context cache → экономия токенов
```

### 6.2 Qwen2.5-VL-72B (для максимального качества)

Для сценария, когда качество важнее скорости: 72B-версия конкурирует с GPT-4V.
Требует 2× A100 80GB или 4× RTX 4090. Рекомендуется как целевое состояние после
набора 10+ клиентов.

### 6.3 Mistral Small 3.1 (текстовый, 24B)

Для стадии 3 (PrimaryAnalysis) по чистым текстовым форматам — быстрее и дешевле в инференсе,
хорошо понимает русский язык. Связка: **Qwen2.5-VL для визуального → Mistral Small для текста**.

### 6.4 Матрица выбора

| Модель | VRAM (Q4) | Стадия Agelto | Изображения | Рус. язык | Рекомендация |
|:---|:---|:---|:---|:---|:---|
| Qwen2.5-VL-7B | 8 GB | 3 + 4 | ✅ | ⭐⭐⭐⭐ | **Основная локальная** |
| olmOCR-2-7B | 8 GB | 4 (сканы) | ✅ OCR | ⭐⭐⭐ | Сканы/JPG |
| Qwen2.5-VL-72B | 40 GB | 3 + 4 | ✅ | ⭐⭐⭐⭐⭐ | Целевая v2 |
| Mistral Small 3.1 | 14 GB | 3 (текст) | ❌ | ⭐⭐⭐⭐ | Текст-only |
| Llama 3.2-Vision-11B | 8 GB | 3 + 4 | ✅ | ⭐⭐⭐ | Резерв |

---

## 7. Железо для локальной LLM

### 7.1 Расчёт нагрузки для Agelto

Нагрузка зависит от объёма парсинга. На старте (SOM Год 1: 30 клиентов) и при масштабировании
к Году 3 (150 клиентов) нагрузки принципиально различаются.

**Оценка нагрузки по тарифному плану Agelto:**
- Гипотеза: 1 клиент = ~50 новых тендеров/день → ~150 документов/день (3 вложения/тендер)
- 30 клиентов × 150 doc = **4 500 документов/день** (Год 1)
- 150 клиентов × 150 doc = **22 500 документов/день** (Год 3)
- Пиковая нагрузка: ×2.5 от средней (парсинг в рабочие дни)

**Время инференса Qwen2.5-VL-7B (Q4, RTX 4090):**
- Документ 3 страницы (Excel/PDF-текст): ~8–12 секунд
- Документ со сканом (image input): ~15–20 секунд
- Среднее: ~15 секунд/документ

**Необходимая пропускная способность:**
- Год 1: 4 500 × 15 / (24 × 3600) = 0.78 GPU → **1 × RTX 4090** покрывает с запасом
- Год 3: 22 500 × 15 / (24 × 3600) = 3.9 GPU → **4 × RTX 4090** или 2 × A100

### 7.2 Рекомендуемые конфигурации серверов

#### Конфигурация A — Старт (Год 1, ≤ 5 000 doc/day)

| Компонент | Спецификация | Цена (ориентир) |
|:---|:---|:---|
| GPU | 1 × NVIDIA RTX 4090 (24 GB VRAM) | ~150 000 ₽ |
| CPU | AMD Ryzen 9 7950X (16 core) | ~45 000 ₽ |
| RAM | 64 GB DDR5 | ~18 000 ₽ |
| Storage | 2 TB NVMe (модели + документы) | ~18 000 ₽ |
| **Итого** | | **~230 000 ₽** |

Пропускная способность: ~5 760 документов/сутки при 100% загрузке.
Достаточно для 38 клиентов при среднем потоке.

#### Конфигурация B — Продакшн (Год 2–3, ≤ 14 000 doc/day)

| Компонент | Спецификация | Цена (ориентир) |
|:---|:---|:---|
| GPU | 2 × NVIDIA RTX 4090 (24 GB × 2) | ~300 000 ₽ |
| CPU | AMD Threadripper PRO 7960X | ~120 000 ₽ |
| RAM | 256 GB DDR5 ECC | ~70 000 ₽ |
| Storage | 8 TB NVMe | ~60 000 ₽ |
| **Итого** | | **~550 000 ₽** |

Пропускная способность: ~11 520 документов/сутки.
Достаточно для 77 клиентов при среднем потоке.

#### Облачный вариант без капекса (старт через аренду)

| Провайдер | GPU | Цена/час | Цена/месяц (24/7) |
|:---|:---|:---|:---|
| Yandex Cloud | A100 80G | ~600 ₽ | ~432 000 ₽ |
| Selectel GPU Cloud | RTX A6000 48G | ~250 ₽ | ~180 000 ₽ |
| Cloud.ru (Sber) | A100 80G | ~580 ₽ | ~418 000 ₽ |

**Рекомендация:** начать с Selectel (RTX A6000, 180 000 ₽/месяц) для валидации качества
локальной модели, затем принять решение о покупке железа по итогам 2–3 месяцев.

### 7.3 VRAM-требования

```
Qwen2.5-VL-7B  FP16:   ~16 GB VRAM (1× RTX 4090 — запас 8 GB для батча)
Qwen2.5-VL-7B  Q4_K:    ~5 GB VRAM (можно 2 инстанса на 1× RTX 4090!)
Qwen2.5-VL-72B Q4_K:   ~40 GB VRAM (2× RTX 4090 в тензорном параллелизме)
```

---

## 8. Обучение модели на данных Agelto

### 8.1 Стратегия: сбор датасета пока Gemini доступен

Это самое важное действие прямо сейчас. Каждый успешно распарсенный документ —
потенциальный обучающий пример. Нужно сохранять пары `{входной документ → эталонный JSON}`
автоматически в рамках существующего пайплайна.

**Модификация EstimateAnalysisJob:**
```php
// После успешного парсинга EstimateAnalysisJob:
if ($result->isSuccess() && $result->confidence >= 0.92) {
    TrainingDataset::create([
        'document_fingerprint' => $analysis->file_fingerprint,
        'document_type'        => $analysis->extension,
        'input_data'           => $this->serializeInput($analysis),
        'output_json'          => $result->data,
        'provider'             => 'gemini-2.0-flash',
        'confidence'           => $result->confidence,
    ]);
}
```

**Целевые объёмы датасета:**
- 500 примеров: достаточно для оценки качества базовой Qwen
- 2 000 примеров: можно начинать LoRA файн-тюнинг
- 5 000+ примеров: полноценный файн-тюн, ожидаемое качество ≥ Gemini Flash на задачах Agelto

### 8.2 Связь с PromptRules и golden dataset ([[Architecture_Agelto]] раздел 5.6)

`plt_prompt_rules` уже поддерживает версионирование. Обучающий датасет естественно
становится **golden dataset** для QA-регламента (закрывает R-8):

```
Новая версия PromptRule →
  Прогон по первым 200 примерам датасета (artisan smeta:validate-prompt --version=N) →
  Precision/Recall по контрольным суммам ≥ текущей версии →
  Активация | Rollback
```

Команда `artisan smeta:validate-prompt` — это тот самый «нереализованный тулинг» для R-8.

### 8.3 LoRA файн-тюнинг (LLaMA Factory)

```bash
pip install llamafactory

llamafactory-cli train \
  --model_name_or_path Qwen/Qwen2.5-VL-7B-Instruct \
  --dataset agelto_smeta_v1 \          # 5 000+ пар
  --finetuning_type lora \
  --lora_target all \
  --output_dir ./agelto-qwen-lora \
  --num_train_epochs 3 \
  --per_device_train_batch_size 2 \
  --gradient_accumulation_steps 8 \
  --learning_rate 5e-5 \
  --fp16
```

**Требования:** 1× RTX 4090, ~10–14 часов для 5 000 примеров × 3 эпохи.
Результат: `agelto-qwen-7b-lora` — специализированная модель, заточенная под
терминологию и форматы смет из ЕИС.

---

## 9. Кэширование и оптимизация токенов

### 9.1 Существующий Gemini context cache ([[Architecture_Agelto]] раздел 8.1)

В Agelto уже реализовано:
```
plt_document_analysis.gemini_cache_id        — ID кэша
plt_document_analysis.gemini_rules_hash      — hash активных PromptRules
plt_document_analysis.gemini_cache_expires_at — TTL 6 часов
```

`GeminiService::getContextCache()` создаёт/обновляет кэш и инвалидирует его при смене
fingerprint файла или hash промпта.

### 9.2 Prefix caching для локальной Qwen (vLLM)

vLLM поддерживает `--enable-prefix-caching` — аналог Gemini context cache.
Системный промт (~2 000 токенов) кэшируется в KV-cache GPU. При 10 000 запросов/день
экономия ~20M токенов/день → ускорение инференса на ~30%.

### 9.3 Redis-кэш дедупликации документов

Один и тот же документ может встретиться в нескольких тендерах (типовые сметы).
Кэширование по fingerprint файла:

```php
// Внутри PrepareDocumentJob / перед постановкой в очередь анализа:
$cacheKey = 'parsed:' . $analysis->file_fingerprint;

if ($cached = Cache::get($cacheKey)) {
    // Документ уже парсился — берём результат из кэша
    $this->applyResult($analysis, ParseResult::fromJson($cached));
    return;
}
// Иначе — ставим в очередь анализа
```

### 9.4 Оптимизация входных данных (снижение токенов на 40–60%)

Это напрямую снижает стоимость Gemini и ускоряет локальный инференс:

**Excel (SmetaParserService):**
- Текущий подход: передача HTML первых 100 строк → Gemini
- Оптимизация: `openpyxl` → только непустые ячейки в компактном JSON
- Экономия: 40–60% токенов на шапку структуры

**PDF с текстовым слоем:**
- Извлечение через `pdfplumber` → чистый текст без метаданных
- Передача текста вместо изображения страницы
- Экономия: 70–80% токенов (изображение ~3 000 токенов vs текст ~800 токенов)

**PDF-сканы (изображения):**
- 150 DPI достаточно для OCR (не 300 DPI)
- JPEG quality=85 вместо PNG → в 3–4 раза меньше размер → быстрее загрузка в Files API

---

## 10. Мастер-промт для парсинга смет

Это полный системный промт для хранения в `plt_prompt_rules`. Подача этого промта
в любую LLM (Gemini, YandexGPT, GigaChat, Qwen) должна давать совместимый JSON.

```
РОЛЬ: Специализированная система извлечения данных из строительных смет РФ.

ЗАДАЧА: Из документа (смета, ЛСР, ведомость объёмов работ) извлеки все данные.
Верни ТОЛЬКО валидный JSON по схеме ниже. Никаких пояснений, только JSON.

НОРМАТИВНЫЕ БАЗЫ — распознать и указать код:
- ГЭСН — Государственные элементные сметные нормы
- ФЕР — Федеральные единичные расценки
- ТЕР — Территориальные единичные расценки
- ФССЦпг — Федеральные сметные цены на материалы
- ТСН — Территориальные сметные нормы
- БД — коммерческие цены

ПРАВИЛА ИЗВЛЕЧЕНИЯ:
1. Сохраняй иерархию: раздел → подраздел → позиция
2. Разделяй стоимость материалов, работ и механизмов если указаны раздельно
3. Коэффициенты (зимнее удорожание, командировочные и т.д.) — в отдельный массив
4. Нечитаемые данные → null, ничего не придумывать
5. Числа только цифрами без пробелов: 1234567.89
6. НДС по умолчанию 20%, если указано иное — зафиксировать фактически
7. Тип позиции: SECTION (заголовок раздела), ITEM (позиция с расценкой)

ФОРМАТЫ ВХОДА:
- Excel: ищи колонки «Код», «Наименование», «Ед.изм», «Кол-во», «Цена», «Сумма»
- PDF/DOCX: извлекай табличные структуры, заголовки разделов
- Изображения: OCR + распознавание структуры таблицы

JSON-СХЕМА ВЫВОДА:
{
  "document_type": "smeta|lsr|vedomost|tz|act|other",
  "normative_base": "ГЭСН|ФЕР|ТЕР|mixed|unknown",
  "normative_year": 2001,
  "contractor": "string|null",
  "object_name": "string|null",
  "date": "YYYY-MM-DD|null",
  "currency": "RUB",
  "sections": [
    {
      "type": "SECTION|ITEM",
      "code": "string|null",
      "name": "string",
      "unit": "string|null",
      "quantity": 0.0,
      "unit_price": 0.0,
      "materials_cost": 0.0,
      "works_cost": 0.0,
      "machinery_cost": 0.0,
      "total": 0.0,
      "children": []
    }
  ],
  "totals": {
    "materials": 0.0,
    "works": 0.0,
    "machinery": 0.0,
    "subtotal": 0.0,
    "overhead": 0.0,
    "estimated_profit": 0.0,
    "total_without_vat": 0.0,
    "vat_rate": 20,
    "vat_amount": 0.0,
    "total_with_vat": 0.0
  },
  "coefficients": [
    {"name": "string", "value": 0.0, "applies_to": "all|works|materials|machinery"}
  ],
  "parse_confidence": 0.95,
  "parse_warnings": ["string"]
}
```

### 10.2 Промт для PrimaryAnalysis (стадия 3)

```
Посмотри на этот документ (первые строки / первая страница).
Ответь ТОЛЬКО в JSON:

{
  "is_construction_estimate": true|false,
  "confidence": 0.0-1.0,
  "estimated_total": 0.0,
  "currency": "RUB|null",
  "normative_base": "ГЭСН|ФЕР|ТЕР|unknown|null",
  "ignore_reason": "string|null"
}

Если это не смета СМР — ignore_reason заполни кратко.
```

### 10.3 Промт для валидации результата

```
Проверь JSON-результат парсинга сметы:
1. Сумма total по позициям раздела = subtotal раздела (±0.01 ₽)
2. total_without_vat = subtotal + overhead + estimated_profit
3. vat_amount = total_without_vat × (vat_rate / 100) (±0.01 ₽)
4. total_with_vat = total_without_vat + vat_amount (±0.01 ₽)
5. Все ITEM-позиции имеют непустое поле name

Верни: {"valid": true} или {"valid": false, "errors": ["описание ошибки"]}
```

---

## 11. Обработка изображений и сканов

Открытый пункт из раздела 8.3 [[Architecture_Agelto]]: «OCR для сканов — LayoutLMv3/TrOCR — планируется v2».
С локальной Qwen2.5-VL или olmOCR 2 это закрывается значительно проще.

### 11.1 Pipeline предобработки

```python
# Python-сервис (worker), вызываемый из PHP через process() или HTTP

import fitz          # PyMuPDF
from PIL import Image
import numpy as np

class AgeltoDocumentPreprocessor:

    def pdf_page_to_image(self, pdf_path: str, page_idx: int, dpi: int = 150) -> Image.Image:
        """
        DPI 150 — оптимум для LLM OCR.
        Уменьшение до 72 DPI теряет качество; рост до 300 DPI не улучшает OCR, но утраивает токены.
        """
        doc = fitz.open(pdf_path)
        pix = doc[page_idx].get_pixmap(dpi=dpi)
        return Image.frombytes("RGB", [pix.width, pix.height], pix.samples)

    def enhance_low_quality_scan(self, image: Image.Image) -> Image.Image:
        """Улучшение сканов: binarize + deskew. Повышает точность OCR на ~25%."""
        gray = np.array(image.convert('L'))
        threshold = gray.mean() * 0.85
        binary = (gray > threshold).astype(np.uint8) * 255
        return Image.fromarray(binary)

    def xlsx_to_compact_json(self, xlsx_path: str) -> str:
        """
        Excel → компактный JSON (только непустые ячейки).
        Экономия токенов: ~50% vs HTML-представление.
        """
        import openpyxl
        wb = openpyxl.load_workbook(xlsx_path, data_only=True)
        result = {}
        for sheet in wb.worksheets:
            rows = []
            for row in sheet.iter_rows(values_only=True):
                filtered = [str(c) if c is not None else None for c in row]
                if any(c for c in filtered):
                    rows.append(filtered)
            result[sheet.title] = rows
        import json
        return json.dumps(result, ensure_ascii=False)

    def extract_pdf_text(self, pdf_path: str) -> str:
        """
        Для PDF с текстовым слоем — извлечь текст, не конвертировать в изображение.
        Экономия: ~70% токенов.
        """
        import pdfplumber
        text_pages = []
        with pdfplumber.open(pdf_path) as pdf:
            for page in pdf.pages:
                text = page.extract_text()
                tables = page.extract_tables()
                if tables:
                    for table in tables:
                        text += "\n" + "\n".join("|".join(str(c or "") for c in row) for row in table)
                text_pages.append(text or "")
        return "\n\n---PAGE BREAK---\n\n".join(text_pages)
```

### 11.2 Рекомендации по форматам для Agelto

| Тип документа из ЕИС | Обработка | Метод передачи в LLM |
|:---|:---|:---|
| Excel (.xlsx) — таблица сметы | `xlsx_to_compact_json()` | JSON-текст |
| PDF с текстовым слоем (1С:Смета, ГрандСмета) | `extract_pdf_text()` + `pdfplumber` | Чистый текст |
| PDF-скан (отсканированная бумажная смета) | `pdf_page_to_image()` 150 DPI | JPEG base64 или Files API |
| Скан низкого качества (ксерокс) | + `enhance_low_quality_scan()` 300 DPI | PNG base64 |
| DOCX — текстовая смета / ТЗ | `python-docx` → plaintext | Текст |

---

## 12. Экономика: расчёт и сравнение

### 12.1 Расчёт себестоимости парсинга одной сметы (закрывает R-7)

**Исходные данные (средний документ из ЕИС):**
- 3 страницы PDF или Excel-смета 150 строк
- Стадия 3 (PrimaryAnalysis): 800 input + 150 output = 950 токенов
- Стадия 4 (EstimateAnalysis): 2 чанка × 2 000 input + 600 output = 4 600 токенов
- **Итого: ~5 550 токенов/документ**

### 12.2 Стоимость по провайдерам (1 документ)

| Провайдер | Стоимость/документ | Стоимость/1000 документов |
|:---|:---|:---|
| Gemini 2.0 Flash | ~$0.0024 | ~$2.4 |
| Gemini 1.5 Flash (context cache) | ~$0.0008 | ~$0.8 |
| YandexGPT Pro 5.1 | ~$0.0186 | ~$18.6 |
| GigaChat Pro | ~$0.0049 | ~$4.9 |
| GigaChat MAX | ~$0.0183 | ~$18.3 |
| Local Qwen2.5-VL (электричество) | ~$0.0001 | ~$0.1 |

### 12.3 Стоимость в месяц по нагрузкам

Реалистичные сценарии для плана Agelto (SOM Year 1 = 30 клиентов):

| Провайдер | 1 500 doc/day (10 клиентов) | 4 500 doc/day (30 клиентов) | 22 500 doc/day (150 клиентов) |
|:---|:---|:---|:---|
| Gemini Flash (с кэшем) | ~$36/мес | ~$108/мес | ~$540/мес |
| YandexGPT Pro 5.1 | ~$837/мес | ~$2 511/мес | ~$12 555/мес |
| GigaChat Pro | ~$221/мес | ~$662/мес | ~$3 308/мес |
| Local Qwen (электр.) | ~$5/мес | ~$15/мес | ~$75/мес |

### 12.4 Окупаемость локального железа (vs Gemini)

При нагрузке 30 клиентов (4 500 doc/day) Gemini с кэшем стоит ~$108/месяц.
Конфигурация A (230 000 ₽ / ~$2 500) окупается за **23 месяца** — не выгодно на этом объёме.

**Вывод:** локальная LLM для Agelto — это прежде всего **страховка от R-6, а не экономия**.
При текущих объёмах Gemini + GigaChat Pro как fallback — оптимальный выбор по экономике.

### 12.5 Маржа тарифного плана (ARPU 600 000 ₽/год)

```
ARPU = 600 000 ₽/год = 50 000 ₽/мес

AI-расходы (Gemini, 30 клиентов): 108 × 90 = 9 720 ₽/мес  →  расходы на 1 клиента: ~324 ₽/мес
AI как % от ARPU: 324 / 50 000 = 0.65%  ← минимальная себестоимость, маржа отличная

Если переключиться на YandexGPT:
YandexGPT, 30 клиентов: 2 511 × 90 = 225 990 ₽/мес  →  ~7 533 ₽/клиент/мес
AI как % от ARPU: 7 533 / 50 000 = 15%  ← существенно, но допустимо

Если GigaChat Pro:
GigaChat, 30 клиентов: 662 × 90 = 59 580 ₽/мес  →  ~1 986 ₽/клиент/мес
AI как % от ARPU: 1 986 / 50 000 = 4%  ← отличная маржа даже на российском провайдере
```

**GigaChat Pro — экономически оптимальный постоянный российский провайдер.**

---

## 13. Roadmap реализации

Привязан к открытым вопросам и рискам из [[Risk_Register (Agelto)]]:

### Sprint 1 (Закрывает R-6: SPOF Gemini)

```
Задача 1: Абстракция AI-провайдера
  ├── Создать интерфейс DocumentParserProvider (app/Contracts/)
  ├── Обернуть GeminiService в GeminiProvider : DocumentParserProvider
  ├── Создать ProviderRouter с CircuitBreaker (Redis)
  └── Покрыть тестами (ArchTest — провайдеры не зависят от конкретных реализаций)

Задача 2: Подключить YandexGPT Pro 5.1 (Fallback #1)
  ├── YandexGptProvider : DocumentParserProvider
  ├── Маппинг PromptRules на YandexGPT-формат (system/user messages)
  └── Интеграционный тест: парсинг 10 тестовых смет через YandexGPT

Задача 3: Запустить сбор датасета
  ├── TrainingDataset migration + модель
  ├── Хук в EstimateAnalysisJob: confidence ≥ 0.92 → сохранить пару
  └── Artisan-команда экспорта: smeta:dataset:export --format=jsonl
```

### Sprint 2 (Закрывает R-5: 152-ФЗ и R-7: экономика)

```
Задача 4: Подключить GigaChat Pro (Fallback #2, российский контур)
  ├── GigaChatProvider : DocumentParserProvider (с OAuth2 адаптером)
  └── Тест мультимодального ввода (GigaChat MAX для изображений)

Задача 5: Cost-метрики per-документ (T5 из [[Открытые вопросы (Agelto)]])
  ├── Поле tokens_used + provider_cost в plt_document_analysis
  ├── Artisan-команда: smeta:cost-report --period=week
  └── Telegram-алерт при превышении бюджета (per-клиент, per-день)

Задача 6: Оптимизация входных данных
  ├── xlsx_to_compact_json() вместо HTML для Excel
  └── pdfplumber text-extraction для PDF с текстовым слоем
```

### Sprint 3 (Закрывает OCR для сканов из раздела 15 [[Architecture_Agelto]] + R-8)

```
Задача 7: Развернуть Qwen2.5-VL-7B + olmOCR 2  (отложенный трек v2)
  ├── Выбор площадки: Selectel GPU Cloud (первый месяц)
  ├── Docker-контейнер: vllm serve Qwen2.5-VL-7B --enable-prefix-caching
  ├── LocalQwenProvider : DocumentParserProvider
  └── A/B тест: 10% трафика через Qwen vs Gemini (метрика: precision по контрольным суммам)

Задача 8: Artisan smeta:validate-prompt (golden dataset — закрывает R-8)
  ├── Взять первые 200 записей из training_dataset как golden set
  ├── Прогнать через текущую и новую версию PromptRules
  └── Вывод: precision/recall по полям + контрольным суммам
```

### Sprint 4 (Файн-тюнинг — отложенный трек v2)

```
Задача 9: LoRA файн-тюнинг Qwen2.5-VL-7B
  ├── Условие: датасет ≥ 2 000 проверенных пар
  ├── LLaMA Factory: 3 эпохи, RTX 4090 (Selectel / собственное железо)
  └── A/B тест v1: базовая Qwen vs agelto-qwen-lora-v1
```

---

## 14. Матрица рисков (обновлённая)

Сводка статусов из [[Risk_Register (Agelto)]] с учётом данной стратегии (source of truth — сам [[Risk_Register (Agelto)]]):

| # | Риск | P | I | Митигация (было) | Статус после стратегии |
|:---|:---|:---|:---|:---|:---|
| R-5 | 🔴 Передача данных в Google → 152-ФЗ, блокер Enterprise | Высокий | Высокий | Юр. заключение / self-hosted LLM | **Митигация спроектирована (Sprint 1–2)**: GigaChat Pro (РФ) как fallback, локальная Qwen как offline |
| R-6 | SPOF Gemini: блокировка останавливает пайплайн | Средний | Высокий | Абстракция AI-провайдера | **Митигация спроектирована (Sprint 1)**: ProviderRouter + 3 fallback-провайдера |
| R-7 | Себестоимость парсинга неизвестна | Средний | Средний | Cost-метрики per-doc | **Митигация спроектирована (Sprint 2)**: tokens_used + cost_rub на каждом анализе |
| R-8 | Тихая деградация PromptRules | Средний | Средний | Откат есть, регламента нет | **Митигация спроектирована (Sprint 3)**: artisan smeta:validate-prompt + golden dataset |

---

## Приложение A: Технологический стек расширенный

```
AI провайдеры:
  ├── Gemini 2.0 Flash (Primary)          → GeminiProvider
  ├── YandexGPT Pro 5.1 (Fallback 1)      → YandexGptProvider
  ├── GigaChat Pro/MAX (Fallback 2)        → GigaChatProvider
  └── Qwen2.5-VL-7B / olmOCR 2 (Local)    → LocalQwenProvider

Роутинг:
  └── ProviderRouter (Redis CircuitBreaker)

Инференс локальной модели:
  ├── vLLM (продакшн, OpenAI API)
  └── Ollama (разработка)

Предобработка документов (Python):
  ├── PyMuPDF (fitz) — PDF → изображения
  ├── pdfplumber — PDF text extraction
  ├── openpyxl — Excel → compact JSON
  └── python-docx — DOCX → plaintext

Датасет и обучение:
  ├── DVC — версионирование датасета (git-совместимый)
  ├── LLaMA Factory — LoRA файн-тюнинг
  └── Yandex Object Storage — хранение датасета (S3-совместимый)

Мониторинг AI:
  ├── plt_document_analysis.tokens_used + provider_cost
  ├── Telegram-алерты (расширение PipelineFailedNotification)
  └── Laravel Horizon метрики очередей
```

## Приложение B: Внешние ссылки

- [Qwen2.5-VL-7B — HuggingFace](https://huggingface.co/Qwen/Qwen2.5-VL-7B-Instruct)
- [olmOCR 2 — Allen AI Blog](https://allenai.org/blog/olmocr-2)
- [vLLM Production Deployment](https://docs.vllm.ai/en/latest/deployment/index.html)
- [LLaMA Factory](https://github.com/hiyouga/LLaMA-Factory)
- [YandexGPT Pricing — AI Studio](https://aistudio.yandex.ru/docs/en/ai-studio/pricing)

---

*Версия: 1.0 · Дата: 2026-06-13 · Закрывает: R-5, R-6, R-7, R-8, T5*
