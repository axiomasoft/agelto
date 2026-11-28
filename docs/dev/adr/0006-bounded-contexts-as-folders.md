---
audience: dev
type: adr
status: living
---

# ADR 0006: подомены выражаются папками, а не namespace-ренеймом в bounded contexts

- Status: Accepted
- Date: 2026-07-10
- Источник: `plans/archive/DOMLANG/plan.md` §5 Decision Log, D2; `findings/P1-audit-verification.md` §3, §1

## Context

Внешний аудит (`agelto_goszakupki_audit_consolidated.md`, Perplexity) рекомендовал разложить домен
в 7 отдельных bounded contexts с namespace-ренеймом — конкретное предложение свёрнуто к пяти именам:
`SourceIntake` / `NoticeEnrichment` / `DocumentLifecycle` / `EstimateIntelligence` / `AiOrchestration`.
Рекомендация выдана по рабочим документам EST-AI, а не по коду; аудит сам оговаривается, что «дерево
файлов кода подробно не раскрыто».

`RAG:✅ 2026-07-10 «DDD bounded contexts в Laravel modular monolith: цена и риск большого
namespace-ренейма»` (источник: W3C/DDD-практика, свод в `findings/P1-audit-verification.md` §3) даёт
порог окупаемости такого ренейма — все условия должны выполняться одновременно: ≥2 подомена с
**различными** ubiquitous language; инварианты, которые не должны протекать между ними; разные
регимы масштабирования/комплаенса; организационная структура уже отражает границы.

Проект **не проходит ни по одному критерию**: одна команда, один язык («закупка» — один
интегрированный процесс), нет разных SLA/владельцев. Подомены уже выражены папками
(`app/Models/Procurement/{Ai,Crawler,Document,Estimate,Platform}/`,
`app/Services/Procurement/{Prepare,Screening,Extraction,Detail,Eis,Modes}/`). В коде разделение
осей уже сделано де-факто: `PipelineDispatcher` — явный мост crawling → пайплайн документов,
`PipelineStage`/`CrawlerMode` — отдельные enum'ы, сервисы именованы по роли (`DocumentPreparer`,
`EstimateStitcher`, `ExtractionOrchestrator`), а не как универсальный `Service`-контейнер.

## Decision

Подомены остаются выраженными **структурой каталогов** внутри плоского `prc_`-мегадомена (ADR-0001).
Namespace-ренейм в bounded contexts (5 имён выше) **отклонён**: порог окупаемости не пройден.
Это решение об отказе, а не отложенное «пока не делаем» — при текущих организационных и языковых
условиях ренейм отрицательно окупается.

Если триггер пересмотра сработает, способ исполнения — **strangler, никогда big-bang**:
фасад-алиасы для старых FQN → поэтапная миграция `use`-statements по подомену → hard rename с
удалением алиасов. `RAG:✅ 2026-07-10` прямо предупреждает, что big-bang ломает Eloquent morph
maps, policy mapping, Filament Resource discovery, route-model binding и factories — их правка
должна идти под контрактом на шве, а не одним разрушающим коммитом.

## Рассмотренные альтернативы

Перенесены из D-лога (`plan.md` §5, D2) дословно:

- **(а) big-bang ренейм всех 7 контекстов** — отвергнут (341 PHP-файл, 99 тестов, morph maps,
  Filament discovery).
- **(б) strangler одного подомена через фасад-алиасы** — отвергнут как преждевременный, но записан
  в этом ADR как способ исполнения, если триггер сработает.

## Consequences

- Плюс: не тронуты 341 PHP-файл, 99 тестов, morph maps, policy mapping, Filament Resource discovery,
  route-model binding и factories — ренейм без доказанной доменной границы был бы налогом без выигрыша.
- Плюс: навигация по подомену не потеряна — она несётся структурой папок `Models/` и `Services/`,
  которую и рекомендует держать `laravel-structure` для плоского домена.
- Минус (принят осознанно): один интегрированный `Procurement`-домен без жёстких межконтекстных
  границ — если в будущем появится второй ubiquitous language, инварианты придётся выделять
  strangler'ом, а не бесплатно на старте.

## Триггер пересмотра

Пересмотреть решение, когда наступит **проверяемое событие**: появилась вторая площадка-источник с
иным ubiquitous language (термины/жизненный цикл закупки существенно расходятся с 44-ФЗ/223-ФЗ) **ИЛИ**
у подомена появился второй владелец — отдельная команда/сервис с собственным SLA и релизным циклом.
До наступления одного из этих двух событий вопрос закрыт.

## Смежное

- [`0001-prc-megadomain.md`](0001-prc-megadomain.md) — таксономия `prc_`-мегадомена, поверх которой
  живут папки-подомены.
- `../architecture.md` — раскладка `app/**` по слоям и три домена.
- `../../../plans/archive/DOMLANG/findings/P1-audit-verification.md` §3 — RAG-свод по порогу окупаемости.
