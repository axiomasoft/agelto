<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\ProcessEventEnvelope;

/**
 * Optional technical sink accepted transition (observability#s03): вызывается строго после commit,
 * вне DB transaction. Успех не подтверждает работу, сбой не меняет её исход и не повторяет handler.
 */
interface TransitionSink
{
    public function publish(ProcessEventEnvelope $envelope): void;
}
