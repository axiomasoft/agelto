<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Dto\Procurement\Acquisition\ProcurementIdentity;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL transaction-scoped locks for domain identities that may not have a row yet.
 *
 * `hashtextextended()` receives the complete identity rather than an application-side
 * truncated hash. PostgreSQL's 64-bit hash can still collide; that only serializes two
 * unrelated identities. The post-lock lookup always uses the exact platform/type/value tuple, so a
 * collision can never merge their data or weaken correctness.
 */
final class PostgresAdvisoryLock
{
    public function acquireProcurement(ProcurementIdentity $identity): void
    {
        $this->acquireProcurements([$identity]);
    }

    /** @param list<ProcurementIdentity> $identities */
    public function acquireProcurements(array $identities): void
    {
        CallerTransaction::require('Procurement identity locks');
        $keys = [];
        foreach ($identities as $identity) {
            $key = LedgerRow::one('SELECT hashtextextended(?, 0) AS key', [$identity->lockMaterial()])?->int('key')
                ?? throw new \LogicException('Missing advisory key.');
            $keys[$key] = $key;
        }
        // SORT_NUMERIC converts to double and loses adjacent signed64 keys above 2^53.
        usort($keys, static fn (int $left, int $right): int => $left <=> $right);
        foreach ($keys as $key) {
            DB::selectOne('SELECT pg_advisory_xact_lock(?)', [$key]);
        }
    }
}
