---
audience: dev
type: runbook
status: living
---

# Runbook: затор Horizon

Сначала откройте [Process recovery](process-recovery.md) и
[Process controls](process-controls.md). PostgreSQL work/attempt/outbox определяют, что ждёт и
что уже завершено; длина Redis очереди и статус Horizon не заменяют эти факты.

1. Выполните `process:status` и откройте work drilldown. Различайте ready/admitted/running,
   deferred с absolute retry и blocked с ручной причиной.
2. Проверьте readiness всех четырёх Process daemons и текущие control scopes. Operator pause,
   handler hold, storage/provider wait и unknown paid outcome имеют разные действия.
3. Для явной пассивной диагностики используйте `process:diagnostics`. Эта команда может
   вызвать существующий notification transport, поэтому запускается оператором осознанно.
   Scheduled `process:health-report` только читает состояния и причины, без уведомлений.
4. Recovery expired ownership выполняет reconcile daemon. Manual retry использует
   audited/versioned Process action и новый intent; прямой UPDATE и старые pipeline retry
   commands отсутствуют.

Horizon содержит default/mail supervisor и шесть Process supervisors. Проверяйте цепочку
slice < job timeout < supervisor timeout < retry_after < lease через StageQueueMap.
Default shutdown grace — 370 s в обоих Compose; W/C ограничиваются DB reservations,
а увеличение числа workers не увеличивает разрешённую outstanding capacity.

При неизвестном provider outcome не посылайте запрос повторно. Reconciler должен проверить
immutable receipt и original reservation; elapsed time не доказывает отсутствие оплаты.
При сохранённом receipt обработка может продолжиться без нового send. При отсутствии
проверенного факта нужен отдельный operator decision/replay, сохраняющий финансовую историю.
