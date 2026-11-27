<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

/** @template TExtension of object */
interface ExtensionPersister
{
    /**
     * Caller owns the fenced finalize transaction. No external I/O.
     *
     * @param  TExtension  $extension
     */
    public function persist(int $procurementId, object $extension): void;
}
