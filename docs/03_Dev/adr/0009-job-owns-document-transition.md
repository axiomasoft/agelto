---
audience: dev
type: adr
status: draft
---

# ADR 0009: owner перехода `downloaded → prepared` — сам `PrepareDocumentJob`, не мост

- Status: Accepted
- Date: 2026-07-10
- Источник: `plans/archive/DOMLANG/plan.md` §5 Decision Log, D25; `findings/P5-pipeline-defects.md` §1

## Context

Скелет фазы P5 и `docs-private/risk-register.md` («Риск 1») повторяли формулировку решения
**D17** плана EST-AI: `PipelineDispatcher` обязан быть «единственным owner'ом перехода статуса»
моста в пайплайн документов, ставя документ в промежуточное состояние «поставлено в очередь».
`docs/specs/v2/02-crawling.md:159` до сих пор буквально пишет, что диспетчер «ставит их в очередь
стадии подготовки документов **и помечает статус**».

`verified:✅ 2026-07-10` (`findings/P5-pipeline-defects.md` §1) — эта формулировка не воспроизводится
кодом и опровергнута фактом: документ защищён от повторной постановки в очередь **тремя независимыми
гардами**, ни один из которых не требует промежуточного статуса:

| # | Гард | Где | Что закрывает |
|:--|:--|:--|:--|
| G1 | `ShouldBeUnique` + `uniqueId() = "{documentId}:prepare"` | `app/Jobs/Procurement/PrepareDocumentJob.php:24,35` | второй `dispatch()` не доходит до очереди — `PendingDispatch` берёт `UniqueLock` ДО пуша |
| G2 | status-guard первым выражением `handle()` | `app/Jobs/Procurement/PipelineJob.php:34-36` + `PrepareDocumentJob::statusGuardPassed()` | job, доехавший вторым, молча выходит |
| G3 | атомарный лиз | `app/Models/Procurement/Concerns/HasLease.php:24-42` | параллельная обработка одной записи двумя воркерами |

G1 запинен тестом `tests/Feature/Document/PipelineDispatcherTest.php:26`.

Настоящий остаточный дефект — обратный: `grep -rn 'uniqueFor' app/Jobs/` = 0 до этого ADR, значит
`UniqueLock::acquire()` (`vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php:32-34`) брал лок
с `$uniqueFor = 0`. На Redis (прод) `RedisLock::acquire()` при `seconds === 0` делает `SETNX` **без
`EXPIRE`** — лок вечен. Лок снимается штатно при завершении/финальном провале job'а
(`CallQueuedHandler:217-218`), но НЕ снимается, если воркер убит без PHP-shutdown (SIGKILL, OOM-killer,
рестарт контейнера). Тогда документ остаётся `downloaded` навсегда: `dispatchBacklog()` и
`pipeline:retry-failed` молча проваливают `UniqueLock::acquire()`, `pipeline:stuck-check` только
сигналит, чинить некому. На array-store (тесты) `seconds === 0` вырождается в TTL «секунда»
(`ArrayLock:38`, `??`-fallback на чтении), поэтому дефект структурно невидим текущему сюиту.

## Decision

1. **Owner перехода `downloaded → prepared` — сам `PrepareDocumentJob`** (`process()`,
   `app/Jobs/Procurement/PrepareDocumentJob.php:79`), не `PipelineDispatcher`. Мост статус документа
   не мутирует и не обязан: он лишь отбирает `status = downloaded` и диспатчит job, инвариант «один
   job на документ» держат гарды G1–G3, не промежуточный статус.
2. **`DocumentStatus::Queued` не вводится.** Он сломал бы `scopePendingRetry`/
   `PipelineStuckCheck::stuckDocuments()`/`PipelineRetryFailed` — весь recovery этих трёх опирается
   на то, что застрявший документ **остаётся** `downloaded`; крашнувшийся воркер оставлял бы документ
   в `queued` без владельца, то есть воспроизводил бы ровно тот же класс дефекта под новым именем.
3. **Остаточный дефект (вечный лок) закрывается TTL, не новым статусом:** `PipelineJob::uniqueFor()`
   возвращает `config('ai.pipeline.unique_lock_minutes', 60) * 60` секунд — общий метод для всех
   pipeline-job'ов (наследники, включая `EstimateExtractionJob` с `ShouldBeUniqueUntilProcessing`,
   получают его автоматически). Значение больше TTL лиза (600 c, `HasLease`) и не больше
   `document_stuck_minutes` (60), чтобы протухший лок самовосстанавливался не позже, чем
   `pipeline:stuck-check` о нём просигналит.
4. Этот ADR **supersedes** текстуально часть D17 (`docs/specs/v2/02-crawling.md` §8: «ставит в
   очередь и помечает статус») — правку самой спеки выполняет фаза P6 плана DOMLANG (D7).

## Рассмотренные альтернативы

- **(а) ввести `DocumentStatus::Queued`, как требовал D17 буквально** — отвергнуто: ломает
  `scopePendingRetry`/`stuckDocuments()`/`PipelineRetryFailed` (`PipelineRetryFailed:24-26` прямо
  фиксирует «новый промежуточный статус НЕ вводить»), а выигрыш нулевой — инвариант уже держат три
  гарда.
- **(б) оставить как есть (лок без TTL)** — отвергнуто: вечный лок = тот же класс v1 C1 («найдено,
  никогда не обработано»), просто причина другая (лок, а не разрыв конвейера).
- **(в) снимать лок из `pipeline:stuck-check`** — отвергнуто: команда объявлена read-only-сигнализатором
  (`docs-private/crawler-gotchas.md`), мутация статуса/локов из наблюдателя ломает её контракт.

## Consequences

- Плюс: инвариант «один job на документ» остаётся выраженным тремя структурными гардами, а не
  дополнительным статусом, за который надо было бы держать ещё одну ветвь во всех читателях
  `DocumentStatus`.
- Плюс: убитый воркер больше не запирает документ навсегда — протухший лок самовосстанавливается
  не позже `unique_lock_minutes`, следующий тик поллера/ретрая забирает документ штатно.
- Минус (принят осознанно): между смертью воркера и истечением TTL документ остаётся недоступен для
  повторной постановки — окно ограничено `unique_lock_minutes` (60 минут по умолчанию), не мгновенно.
- Факт: `docs-private/risk-register.md` («Риск 1») и `docs-private/crawler-gotchas.md` (§5)
  переписаны по этому решению (2026-07-10).

## Триггер пересмотра

Пересмотреть решение, когда появится путь, где документ теряется при **живом** локе (не по TTL-
истечению) — то есть основание, которого три гарда G1–G3 не покрывают. До этого момента TTL — 
достаточная граница.

## Смежное

- `../crawling-pipeline.md` §8 — исходная формулировка D17 (плана EST-AI).
- `docs/specs/v2/02-crawling.md` §8 — спека, которую этот ADR супersedes текстуально; правка
  выполняется фазой P6 плана DOMLANG.
- `plans/archive/DOMLANG/findings/P5-pipeline-defects.md` §1 — полная карта гардов и механики `UniqueLock`.
