<?php

declare(strict_types=1);

namespace App\Events\Procurement;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Закупка впервые достигла положительного исхода. Рождается ровно один раз на закупку и только
 * после коммита; полноту сметных данных на момент рождения не гарантирует.
 *
 * Замороженный контракт нагрузки: `docs/dev/contracts/procurement-succeeded-v1.md`.
 */
final readonly class ProcurementSucceeded
{
    use Dispatchable;

    public function __construct(
        public int $procurementId,
        public int $registryId,
        public string $registryNumber,
        public string $canonicalStatus,
        public string $reason,
        public Carbon $succeededAt,
        public bool $hasEstimate,
        public int $estimatesCount,
        public ?int $winnerOrgId,
        public ?int $awardId,
    ) {}
}
