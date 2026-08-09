<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL transaction-scoped locks for domain identities that may not have a row yet.
 *
 * `hashtextextended()` receives the complete identity rather than an application-side
 * truncated hash. PostgreSQL's 64-bit hash can still collide; that only serializes two
 * unrelated identities. The post-lock lookup always uses the exact registry/id pair, so a
 * collision can never merge their data or weaken correctness.
 */
final class PostgresAdvisoryLock
{
    public function acquireProcurement(int $registryId, string $registryNumber): void
    {
        DB::selectOne(
            query: 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            bindings: [sprintf('procurement:%d:%s', $registryId, $registryNumber)],
        );
    }
}
