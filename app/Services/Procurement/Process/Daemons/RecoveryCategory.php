<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Daemons;

use App\Services\Procurement\Process\Contracts\ReconcileCategory;

/** Именованная категория ReconcileDaemon: bounded вызов одного прохода RecoveryReconciler. */
final readonly class RecoveryCategory implements ReconcileCategory
{
    /** @param \Closure(): int $run */
    public function __construct(private string $name, private \Closure $run) {}

    public function name(): string
    {
        return $this->name;
    }

    public function reconcile(): int
    {
        return ($this->run)();
    }
}
