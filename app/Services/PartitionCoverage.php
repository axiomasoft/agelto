<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Coverage is derived from PostgreSQL bounds, independent of partman naming. */
final readonly class PartitionCoverage
{
    public function ensure(string $table, Carbon $from, Carbon $to): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('Invalid partition parent identifier.');
        }
        DB::transaction(function () use ($table, $from, $to): void {
            DB::statement("LOCK TABLE {$table} IN SHARE UPDATE EXCLUSIVE MODE");
            $rows = DB::select('SELECT pg_get_expr(c.relpartbound, c.oid) AS bounds FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = to_regclass(?)', [$table]);
            $intervals = [];
            foreach ($rows as $row) {
                $bounds = is_object($row) ? ($row->bounds ?? null) : null;
                if (! is_string($bounds) || $bounds === 'DEFAULT') {
                    continue;
                }
                if (preg_match("/FROM \\('([^']+)'\\) TO \\('([^']+)'\\)/", $bounds, $match) !== 1) {
                    throw new RuntimeException("Unsupported partition bounds for {$table}.");
                }
                $intervals[] = [Carbon::parse($match[1])->utc(), Carbon::parse($match[2])->utc()];
            }
            usort($intervals, static fn (array $a, array $b): int => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());
            $cursor = $from->copy()->utc();
            $to = $to->copy()->utc();
            foreach ($intervals as [$lower, $upper]) {
                if ($upper->lte($cursor) || $lower->gte($to)) {
                    continue;
                }
                if ($lower->gt($cursor)) {
                    $this->createGap(table: $table, from: $cursor, to: $lower);
                }
                $cursor = $upper->copy();
            }
            if ($cursor->lt($to)) {
                $this->createGap(table: $table, from: $cursor, to: $to);
            }
        });
    }

    private function createGap(string $table, Carbon $from, Carbon $to): void
    {
        // Split on month boundaries for retention. A partial month uses a deterministic suffix.
        while ($from->lt($to)) {
            $end = $from->copy()->startOfMonth()->addMonth();
            if ($end->gt($to)) {
                $end = $to->copy();
            }
            $suffix = $from->isStartOfDay() && $from->day === 1 && $end->day === 1
                ? $from->format('Y_m') : 'r_'.substr(hash('sha256', $from->toIso8601String().$end->toIso8601String()), 0, 12);
            $partition = $table.'_'.$suffix;
            // Deliberately no IF NOT EXISTS: an unrelated same-name table is a configuration error.
            DB::statement("CREATE TABLE {$partition} PARTITION OF {$table} FOR VALUES FROM ('{$from->toIso8601String()}') TO ('{$end->toIso8601String()}')");
            $from = $end;
        }
    }
}
