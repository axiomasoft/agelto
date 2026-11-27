<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/** Bounded read adapter for the retained archive; preserves physical and financial correlation IDs. */
final readonly class HistoricalRequests
{
    public function __construct(private Connection $db) {}

    /** @return list<object> */
    public function page(?string $after = null, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Historical page limit must be 1..100.');
        }

        return array_values($this->db->table('prc_request_attempts as r')->leftJoin('prc_network_demands as d', 'd.id', '=', 'r.demand_id')
            ->leftJoin('prc_outbound_operations as o', 'o.id', '=', 'd.operation_id')
            ->when($after !== null, fn ($q) => $q->where('r.request_id', '>', $after))
            ->orderBy('r.request_id')->limit($limit)->get(['r.request_id', 'r.attempt_id', 'r.demand_id',
                'r.started_at', 'r.sent_at', 'r.finished_at', 'r.outcome', 'r.bytes', 'd.operation_id', 'o.parent_attempt_id'])->all());
    }
}
