<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\Projection\MetricDelta;
use App\Dto\Procurement\Process\Projection\ProjectedEvent;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;

/**
 * Registered consumer ledger events. Reducer чистый и детерминированный:
 * по одному immutable event возвращает приращения СВОИХ counter-серий и ничего не читает кроме
 * него, поэтому live apply и rebuild дают одинаковый итог. Одна серия принадлежит ровно одному
 * reducer; `key()` и каталог метрик входят в catalog_hash его state row.
 */
interface ProjectionReducer
{
    /** projector_key: стабильный идентификатор consumer, ключ state/receipts/totals. */
    public function key(): string;

    /** Версия логики reducer: смена меняет catalog_hash и требует явного rebuild. */
    public function version(): int;

    /** @return list<string> ledger entity_kind, события которых получает этот consumer */
    public function entityKinds(): array;

    /** @return list<string> MetricCatalog ключи серий, которыми владеет этот reducer */
    public function metrics(): array;

    /**
     * @return list<MetricDelta>
     *
     * @throws ProjectionSchemaError событие вне каталога: оно изолируется, а не молча пропускается
     */
    public function reduce(ProjectedEvent $event): array;
}
