---
audience: dev
type: reference
status: living
---

# Открытые технические follow-up'ы (V2-CRAWL P3–P4)

Только для разработчиков. Список НЕ-решённых пунктов, явно зафиксированных как Pending Work/Escalation в `plans/V2-CRAWL/phases/P3.md` и `P4.md`. Если берёшься за один из пунктов — сначала перечитай соответствующий `Completion Notes`/`Known Deviations` в фазовом плане (пока он не удалён), там больше контекста.

## 1. `prc_protocols.document_version_id` не заполняется

Схема (`docs/03_Dev/database.md` §4) предполагает связь протокола с файлом-источником: `document_version_id FK prc_document_versions nullable (файл-источник)`. Но `SupplierResultsProcessor` (P3.7) её не проставляет — Pending Work P3.7 прямо фиксирует:

> `prc_protocols.document_version_id` — не заполняется: на вкладке `supplier-results` (ни в разведке, ни во фикстуре) нет ссылки на файл-источник протокола; связывание с `prc_document_versions` по номеру/дате — вероятно задача P3.8-оркестратора, имеющего доступ к обоим источникам.

P3.8 (оркестратор) эту задачу тоже не решил — Pending Work P3.8 повторяет тот же пункт как deferred за пределы V2-CRAWL. **Открытый вопрос:** как связать протокол (из `supplier-results`) с конкретной версией документа (из `documents`) — по номеру, по дате, по эвристике совпадения текста? Ни ТЗ, ни разведка `research/eis-card-recon.md` не дают ответа.

## 2. `prc_clarifications`/`prc_cancellations` не реализованы

Обе таблицы существуют в схеме (`docs/03_Dev/database.md` §4: разъяснения документации / решения об отмене), обе перечислены в Scope Included P3.7 (`SupplierResultsProcessor` должен был писать их «при наличии»). По факту — не реализованы вообще:

> `prc_clarifications`/`prc_cancellations` — не реализованы в этом срезе: Files-скоуп item'а и Required Reads не описывают структуру источника этих сущностей на вкладке (нет ни примера разметки, ни вложенного DTO в задании), фикстура/разведка их не содержат — не импровизировал несуществующий входной контракт.

Открытый вопрос из P3.7 Pending Work, не закрытый P3.8: **откуда парсить разъяснения/отмены** — из `documents` (отдельные файлы-разъяснения) или из `event-journal` (упомянуто в Intent P3.7, но источник не подтверждён). Нужна отдельная разведка (аналог `eis-card-recon.md`) прежде чем браться за реализацию.

Связанный риск в `docs/03_Dev/database.md` §12: триггер `lastDateChangeCsv` для разъяснений документации подтверждён живьём только для протокола/отмены; для разъяснения — экстраполяция без прямого наблюдения, «перепроверить при первой возможности».

## 3. Multi-day update-window для backlog-механизма (P4.2) — упрощение, не полное решение

P4.1 Pending Work прямо предупреждало:

> P4.2 остаётся спроектировать реальное многодневное update-окно (`possible_skip_window.from/to` может охватывать больше одного дня) — текущий `ArchiveWindowScanner`/`ListingCursor` хранят одиночный `day`, а не диапазон `[dateFrom,dateTo]`.

P4.2 это решил **не расширением курсора диапазоном**, а собственным day-loop поверх переиспользуемого `ArchiveWindowScanner`:

> `BacklogRescanProcessor::drainWindow()` по `WindowFilter(update, day, day)` на каждый день окна — зеркалит `ArchiveRunProcessor::run` (курсор `day` двигается только после полного дренажа `ArchiveWindowScanner::scan`, resume читает `cursor->day` СВОЕГО archive-run).

Формально пункт закрыт (P4.2 done, `Pending Work: —`), но решение — «обернуть день-циклом», не «научить курсор диапазону». Если в будущем понадобится **произвольный диапазон дат** для чего-то ещё (не только backlog-рескан) — придётся либо копировать паттерн `drainWindow`, либо реально расширять `ListingCursor`/`ArchiveWindowScanner` под диапазон. Это архитектурный компромисс, а не забытый баг — но при следующей похожей задаче стоит подумать о рефакторинге в сторону диапазона на уровне курсора, а не третьей копии day-loop.

## 4. `Procurement` не хранит `crawler_id` — нет однозначного fallback-резолва

Зафиксировано в Pending Work P3.8:

> `DetailAnalysisProcessor` не имеет fallback-метода забора (в отличие от `FreshRunProcessor::makeWithFallback`) — `Procurement` не хранит `crawler_id` (только `registry_id`), резолв «через какой именно `Crawler`-профиль» неоднозначен при нескольких профилях на реестр при необходимости — эвристика `Crawler::where('registry_id',...)->where('mode', Fresh)->first()` или явная привязка reestr→detail-method в конфиге.

Практическое следствие: `DetailAnalysisProcessor` сейчас резолвит источник **только** через `config('crawler.detail_source_method')` (единственный сконфигурированный `csv`) — без возможности переключиться на альтернативный `SourceMethod` при деградации источника, в отличие от fresh-стадии. Если на реестр когда-нибудь заведут второй активный `prc_crawlers`-профиль (например, HTML-фоллбек рядом с CSV) — сегодняшний код не сможет однозначно выбрать, какой из них использовать для детального забора конкретной закупки. Два варианта решения на будущее (оба предложены в самом плане, ни один не выбран):
- добавить `Procurement.crawler_id` (материализовать связь явно, миграция);
- явную привязку `registry → detail-method` в конфиге (без изменения схемы).

## 5. Хрупкий `participant_ordinal` — см. `crawler-gotchas.md` п.3

Не дублируем здесь описание (это technical gotcha, не unresolved item), но важно держать в уме при доработке `prc_bids`: если естественный ключ понадобится ужесточить — в схеме до сих пор нет DB unique-индекса под него (см. `crawler-gotchas.md` п.3 про `updateOrCreate` без подкрепляющего unique).

## 6. Роль `winner` в `prc_procurement_parties` не снимается при пересмотре итогов — закрыто (DOMLANG D27, P5.3)

Найдено точечным ревью P3.7–P4.2 (agelto-reviewer, 2026-07-06; исходная формулировка ошибочно называла таблицу): `SupplierResultsProcessor::storeAward()` денормализует победителя в `prc_procurement_parties` через `upsertParty` по ключу `(procurement_id, organization_id, role)`. `prc_awards.winner_org_id` корректно перезаписывается при повторном прогоне (upsert по `(procurement_id, lot_id)`), но если победитель ИЗМЕНИЛСЯ (пересмотр итогов/протест) — старая организация оставалась с ролью `winner` навсегда, новая просто добавляла вторую `winner`-запись.

Закрыто планом DOMLANG (D27, item P5.3): `ProcurementPartyStoreRepository::syncWinnerRoles(int $procurementId, array $winnerOrgIds)` удаляет строки `role = winner`, чьих `organization_id` нет в списке переданных победителей; вызывается из `SupplierResultsProcessor::storeAward()` сразу после `upsertParty(Winner)`, внутри уже открытой транзакции. Денормализация `role = winner` теперь авторитетна.
