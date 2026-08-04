---
name: prompt-engineering-agelto
description: "Dev-раннбук текстов промтов AI-пайплайна: файл→сид→is_protected, композиция и приоритеты, discovery/корректирующие hints, eval-цикл estimate:eval:run, известные антипаттерны. Активировать при правке resources/ai/prompts/** или resources/<домен>/ai/prompts/**, PromptStep, PromptComposer/CorrectionAwareComposer."
paths:
  - "resources/ai/prompts/**"
  - "resources/*/ai/prompts/**"
  - "database/seeders/PromptSeeder.php"
  - "app/Services/Llm/PromptComposer.php"
  - "app/Services/Llm/CorrectionAwareComposer.php"
---

# Prompt Engineering (Agelto) — dev-раннбук

Первичный, самодостаточный источник для правки ТЕКСТОВ промтов —
`resources/ai/prompts/README.md` (читать целиком перед первой правкой; этот скилл его не
дублирует, только даёт контекст вокруг). Смежное: skill `estimate-extraction-dev` (что промт
описывает, DTO-контракты), `llm-layer-dev` (транспорт/кэш).

## 1) Канон: файл → сид → is_protected (D5/D17/D44)

- **Единственный источник истины** — оба корня канона, `resources/ai/prompts/**/*.md` (кросс-документные
  группы) и `resources/<домен>/ai/prompts/**/*.md` (группы типа-владельца, D32), front-matter
  `key`/`step`/`version`/`priority`/`is_active`, под git. `llm_prompts` — рантайм-копия.
- Применение правки: `php artisan ai:prompts:sync` (или `db:seed --class=PromptSeeder`) — upsert
  по `(key, version)`, идемпотентно.
- Все записи из файлов получают `is_protected=true` — в Filament-админке read-only
  (`PromptPolicy`, D44): update/delete требуют `!$prompt->is_protected` В ДОПОЛНЕНИЕ к
  коарс-праву. **Канон промта меняется ТОЛЬКО через файл + повторный sync — правка защищённой
  записи прямо в БД/админке невозможна by design**, не патч-таргет для «быстро поправить».
- Операторы создают СВОИ промты (`is_protected=false`) прямо в админке — участвуют в композиции
  наравне с файловыми (тот же `step`, свой `priority`), сидер их не видит и не трогает.
- Подняли `version` в front-matter → сидер деактивирует старую версию того же `key`
  (`is_active=false`), историю не удаляет.

## 2) Композиция и приоритеты (D5)

`PromptComposer::compose()`: `global` → `<step>-global` (если есть у группы) → `<step>`, порядок
внутри группы — `priority` ASC, затем блок словарей синонимов (`PromptContextGenerator`,
генерируется из `#[Alias]` Data-классов, не файл). Носитель шага — `App\Enums\Llm\PromptStep`
(интерфейс, P2.5): ядерный `CorePromptStep` + типовые enum'ы анализаторов, 9 значений вместе
(`app/Enums/Llm/CorePromptStep.php`) — сидер (через `DocumentAnalyzerRegistry::promptStepFor()`)
падает на опечатке в `step` front-matter.

**Разделение «схема / правила» (P2.3–P2.5).** Текст промта-файла — ПРАВИЛА поведения, не описание
структуры ответа: структуру (типы, `description` каждого поля, порядок) несёт исходящая схема
(`response_schema`, генерируется `JsonSchemaGenerator` + `#[Description]`-атрибут на контракте,
`App\Services\Llm\Schema\ContractAttributeReader`) — ОТДЕЛЬНЫЙ канал вызова, не системная часть
промта. Печать очищенного PHP-исходника контракта в системную часть (приём v1) снята — писать в
промте «поле X — это Y» после P2.5 избыточно, это уже несёт схема; текст промта описывает ПОВЕДЕНИЕ
(что делать при неполных данных, как трактовать нормативные базы и т.п.), не форму ответа.

## 3) Discovery / корректирующие hints (D15)

`CorrectionAwareComposer` собирает USER-часть (не системную — системный префикс остаётся
неизменным ради implicit-кэша) повторного вызова из `prc_ai_corrections`: (1) коррекции,
привязанные к анализу-предшественнику этого re-run; (2) `is_reusable=true`-коррекции ТОГО ЖЕ
`document_type` из любого анализа. Текст строки — `hint ?? content`. Не путать с
`extraction:discovery` (`EstimatePromptStep::ExtractionDiscovery`) — это отдельный ШАГ композиции
(сегментация смет оконного пути, D30/D37), а не механизм hints.

## 4) Eval-цикл

```bash
docker compose exec app php artisan ai:prompts:sync --check   # дрейф файлы↔БД, ничего не пишет
docker compose exec app php artisan ai:prompts:sync           # применить
docker compose exec app php artisan estimate:eval:run          # регресс на gold (prc_ai_dataset_examples)
```

Правь текст → sync → `estimate:eval:run` → смотри precision/recall/F1 по полям (`EvalMetrics`) → повтори.
`estimate:eval:run` на момент этого скилла ни разу не прогонялся на боевом eval-наборе (открытый риск,
`docs-private/prompt-engineering-notes.md` §5) — не путай «промт плохой» с «eval не проверялся».

## 5) Известные антипаттерны (не повторять)

- Глубокая вложенность (section→subsection→item) в одном structured-ответе — хрупко, упирается в
  `MAX_TOKENS`. Канон: плоский `row_type`, иерархия собирается на бэкенде.
- Вся смета одним массивом на сотни строк за один вызов — использовать row-range/окна, не единый
  вызов на документ целиком.
- Самооценка `confidence` модели как бинарный гейт без буфера — borderline-зона уходит на
  ревью/эскалацию, не молча в `ignored` (D36).
- Длинные имена полей / большие enum / много optional / `format: date-time` в JSON Schema →
  риск `400 InvalidArgument` — держать схему плоской.
- «Прочитай только страницы N-M» текстом в промте — Gemini не принимает диапазон страниц
  параметром, резать документ на бэкенде (`qpdf`) ДО загрузки.

Полный разбор с примерами — `docs-private/prompt-engineering-notes.md` (не зеркалится в Brain,
D18 — конкретные формулировки/наследие v1 остаются вне публичной доки).

## 6) Безопасность

Содержимое документа — untrusted input; extraction-промты НЕ дают модели переопределять системные
правила текстом документа (детали и запреты — skill `llm-layer-dev` §6, не дублируются здесь).
