<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Read projections for existing consumer assertions; never writes a second network journal. */
final class PackageNetworkFacts
{
    public static function attempts(): Builder
    {
        $t = PackageSchema::tables();
        if (! DB::getSchemaBuilder()->hasTable($t['attempts'])) {
            return self::empty(['request_id' => 'uuid', 'request_seq' => 'bigint', 'started_at' => 'timestamptz',
                'sent_at' => 'timestamptz', 'finished_at' => 'timestamptz', 'status' => 'integer', 'outcome' => 'text',
                'bytes' => 'bigint', 'wire_bytes' => 'bigint', 'duration_ms' => 'numeric', 'route_id' => 'uuid',
                'target_id' => 'uuid', 'traffic_class' => 'text', 'demand_id' => 'uuid', 'operation_id' => 'uuid']);
        }
        $receipts = DB::table($t['observations'])->select('attempt_id')->selectRaw('MIN(observed_at) AS observed_at')->groupBy('attempt_id');
        $query = DB::table($t['attempts'].' as a')->join($t['permits'].' as p', 'p.id', '=', 'a.permit_id')
            ->join($t['demands'].' as d', 'd.id', '=', 'p.demand_id')
            ->leftJoinSub($receipts, 'receipt', 'receipt.attempt_id', '=', 'a.id')
            ->selectRaw("a.physical_attempt_id AS request_id, row_number() OVER (ORDER BY a.authorized_at, a.id) AS request_seq,
                a.authorized_at AS started_at, a.authorized_at AS sent_at, COALESCE(a.completed_at, receipt.observed_at) AS finished_at,
                (a.result_summary->>'status')::integer AS status, COALESCE(a.result_summary->>'failure', 'response') AS outcome,
                (a.result_summary->>'decoded_bytes')::bigint AS bytes, (a.result_summary->>'wire_bytes')::bigint AS wire_bytes,
                extract(epoch FROM COALESCE(a.completed_at, receipt.observed_at)-a.authorized_at)*1000 AS duration_ms,
                p.route_id, p.target_id, d.traffic_class, d.id AS demand_id, d.operation_id");

        return DB::query()->fromSub($query, 'network_facts');
    }

    public static function permits(): Builder
    {
        $t = PackageSchema::tables();
        if (! DB::getSchemaBuilder()->hasTable($t['permits'])) {
            return self::empty(['id' => 'uuid', 'demand_id' => 'uuid', 'operation_id' => 'uuid', 'route_id' => 'uuid',
                'target_id' => 'uuid', 'physical_attempt_id' => 'uuid', 'authorized_at' => 'timestamptz',
                'expires_at' => 'timestamptz', 'slot_at' => 'timestamptz', 'state' => 'text']);
        }
        $query = DB::table($t['permits'].' as p')->selectRaw("p.id, p.demand_id, p.operation_id, p.route_id, p.target_id,
            p.physical_attempt_id, p.authorized_at, p.expires_at, p.not_before AS slot_at,
            CASE p.state WHEN 'completed' THEN 'released' WHEN 'authorized' THEN 'sent'
                WHEN 'unknown_after_authorization' THEN 'sent' ELSE p.state END AS state");

        return DB::query()->fromSub($query, 'permit_facts');
    }

    public static function demands(): Builder
    {
        $table = PackageSchema::tables()['demands'];
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return self::empty(['id' => 'uuid', 'operation_id' => 'uuid', 'physical_attempt_id' => 'uuid',
                'route_id' => 'uuid', 'target_id' => 'uuid', 'traffic_class' => 'text', 'purpose' => 'text', 'state' => 'text']);
        }
        $columns = array_map(static fn (string $column): string => 'd.'.$column,
            array_values(array_diff(DB::getSchemaBuilder()->getColumnListing($table), ['traffic_class', 'purpose'])));

        // Queue purpose normal/probe обслуживает FIFO; semantic purpose хранится на operation.
        return DB::query()->fromSub(DB::table($table.' as d')
            ->join(PackageSchema::tables()['operations'].' as o', 'o.id', '=', 'd.operation_id')->select($columns)
            ->selectRaw("o.purpose, CASE WHEN o.purpose = 'diagnostic' THEN 'diagnostic' ELSE d.traffic_class END AS traffic_class"), 'demand_facts');
    }

    /** @param array<string, string> $columns */
    private static function empty(array $columns): Builder
    {
        $projection = [];
        foreach ($columns as $column => $type) {
            $projection[] = "NULL::{$type} AS {$column}";
        }

        return DB::query()->fromSub(DB::query()->selectRaw(implode(', ', $projection))->whereRaw('FALSE'), 'empty_network_facts');
    }
}
