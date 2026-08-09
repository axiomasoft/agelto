---
audience: dev
type: reference
status: living
---

# Feature card: Screening (первичный анализ)

## Что делает

Первый AI-шаг документа: классифицирует `document_type` (смета / не смета) и оценивает `confidence`
по ПЕРВЫМ `config('ai.screening.pages')` страницам (default 4), НЕ всему документу. Реализация —
`App\Services\Procurement\Screening\ScreeningProcessor` (вызов) и `ScreeningOutcomeHandler`
(интерпретация результата → `Screened`/`Ignored`/эскалация). Резка PDF до N страниц —
`PdfPageSlicer` (`Screening/PdfPageSlicer.php`, qpdf-обёртка, D28) — выполняется на бэкенде ДО
загрузки в Files API.

## Модель, промт, схема

- Маршрут: `(document_type=any, purpose=screening) → gemini-2.5-flash-lite` (D4, `llm_routes`,
  сид `AiRoutingSeeder`).
- Промты: `resources/ai/prompts/{global,screening-global,screening-classify}/` — композиция
  Global → `screening-global` → `screening-classify`, `priority` ASC (Д3 в `../llm-providers.md`).
- Схема ответа — `App\Dto\...` Data-класс шага (генерируется в `response_schema`; словарь синонимов
  `#[Alias]` — отдельно, через `PromptContextGenerator`, «схема-как-код», D2).

## Why-решения

- **D6 — только первые N страниц, не весь документ.** В v1 это был замысел, но код слал весь
  `fileUri` (gap research/B). При 258 ток./стр. (research/E §3) 4 страницы ≈ 1k ток. input на
  документ — на порядки дешевле полного скана многостраничной закупочной документации.
- **D36 — borderline-эскалация confidence, не молчаливый `ignored`.** Зона `confidence_low`..
  `confidence_high` (`config('ai.screening.confidence_low'|'confidence_high')`, default 0.4–0.6) НЕ
  уходит сразу в `Ignored`: либо переклассификация моделью классом выше (скрининг дёшев, эскалация
  не разоряет бюджет), либо `needs_review`-пометка в `analysis.result` для выборочного операторского
  ревью (`config('ai.screening.borderline_action')`). Ниже нижней границы — как раньше,
  `unknown → Ignored`. Причина: самооценка confidence у LLM плохо калибрована, реальная смета с
  0.55 без этого правила терялась без следа (research/L finding 6).

## Failure modes

| Симптом | Куда |
|:--|:--|
| Документ повис на статусе `Prepared`, `ScreeningJob` не завершается | [`../runbooks/stuck-analysis.md`](../runbooks/stuck-analysis.md) |
| Ошибки/квоты Gemini на вызове скрининга | [`../runbooks/gemini-errors.md`](../runbooks/gemini-errors.md) |
| Массово `unknown`/`ignored` — подозрение на промт-регрессию | `pipeline:status` (всплеск unknown — сигнал, не покрывает единичный false-negative, см. D36) |

## Eval-результаты

`estimate:eval:run --task=screening` (если заведён датасет под скрининг) — Pending Work плана (открытый
вопрос: screening-gold ещё не наполнен, см. `../ai/training-loop.md`); прогонов на реальных данных
пока не было.
