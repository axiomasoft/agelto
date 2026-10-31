<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\ListingCoverage;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Dto\Procurement\Process\ContentHash;
use App\Repositories\Procurement\Process\LedgerRow;

/** Rebuildable watermark projection from durable immutable assignments and fenced receipts. No second execution owner. */
final readonly class ListingCoverageRepository
{
    public function read(ContentHash $streamKey): ListingCoverage
    {
        $rows = LedgerRow::all("SELECT crawl_run_id, partition_payload->>'windowStart' AS start,
                partition_payload->>'windowEnd' AS finish,
                bool_and(terminal_proof IS NOT NULL) AND
                bool_or(terminal_proof->>'outcome'='complete') AND
                NOT bool_or(COALESCE(terminal_proof->>'outcome' IN ('observed','incomplete'),true)) AS complete,
                COALESCE(bool_or(terminal_proof->>'outcome' IN ('observed','complete')),false) AS observed,
                min(id::text) FILTER (WHERE parent_block_id IS NULL) AS root_id
            FROM prc_crawl_blocks WHERE partition_payload->>'streamKey'=?
            GROUP BY crawl_run_id, partition_payload->>'windowStart', partition_payload->>'windowEnd'
            ORDER BY start, finish", [$streamKey->hex]);
        /** @var list<array{start: string, end: string}> $complete */
        $complete = [];
        $observed = null;
        foreach ($rows as $row) {
            if ($row->bool('observed')) {
                $observed = max($observed ?? $row->string('finish'), $row->string('finish'));
            }
            if ($row->bool('complete')) {
                $start = $row->string('start');
                $end = $row->string('finish');
                $i = count($complete) - 1;
                if ($i >= 0 && $start <= (new \DateTimeImmutable($complete[$i]['end']))->modify('+1 day')->format('Y-m-d')) {
                    $complete[$i] = ['start' => $complete[$i]['start'], 'end' => max($complete[$i]['end'], $end)];
                } else {
                    $complete[] = ['start' => $start, 'end' => $end];
                }
            }
        }
        $verified = $rows !== [] && $complete !== [] && $complete[0]['start'] === $rows[0]->string('start') ? $complete[0]['end'] : null;
        $debt = [];
        $seen = [];
        foreach ($rows as $row) {
            if ($row->bool('complete') || ($root = $row->nullableString('root_id')) === null) {
                continue;
            }
            $start = $row->string('start');
            $end = $row->string('finish');
            $missing = [];
            foreach ($complete as $range) {
                if ($range['end'] < $start || $range['start'] > $end) {
                    continue;
                }
                if ($range['start'] > $start) {
                    $missing[] = [$start, (new \DateTimeImmutable($range['start']))->modify('-1 day')->format('Y-m-d')];
                }
                $start = max($start, (new \DateTimeImmutable($range['end']))->modify('+1 day')->format('Y-m-d'));
            }
            if ($start <= $end) {
                $missing[] = [$start, $end];
            }
            if ($missing === []) {
                continue;
            }
            $stored = LedgerRow::one('SELECT partition_payload::text AS payload FROM prc_crawl_blocks WHERE id=?', [$root]);
            if ($stored === null) {
                throw new \LogicException('Coverage root disappeared.');
            }
            $task = ListingTask::fromJson($stored->string('payload'));
            foreach ($missing as [$from, $through]) {
                $key = $from.':'.$through;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $debt[] = new ListingTask($task->registryId, $task->registry, $task->filter, $task->cursor, $task->rootCursor,
                    $task->streamKey, $from, $through, $task->closesAt, $task->rootBlockId,
                    maxPartitions: $task->maxPartitions);
            }
        }

        return new ListingCoverage($verified, $observed, $debt);
    }

    /** Closing reconciliation survives fresh overlap moving past the day. Immutable recovery filter is returned intact.
     * @return list<ListingTask>
     */
    public function due(ContentHash $streamKey, \DateTimeImmutable $now, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 400) {
            throw new \InvalidArgumentException('Invalid reconciliation bound.');
        }

        $due = [];
        foreach ($this->read($streamKey)->debt as $task) {
            if (new \DateTimeImmutable($task->closesAt) > $now) {
                continue;
            }
            $active = LedgerRow::one("SELECT EXISTS(SELECT 1 FROM prc_crawl_blocks b
                JOIN prc_crawl_runs c ON c.id=b.crawl_run_id JOIN prc_process_runs r ON r.id=c.process_run_id
                WHERE b.partition_payload->>'streamKey'=? AND b.partition_payload->>'windowStart'<=?
                  AND b.partition_payload->>'windowEnd'>=? AND r.discovery_outcome='collecting'
                  AND r.cancel_requested_at IS NULL) AS active", [$streamKey->hex, $task->windowEnd, $task->windowStart]);
            if ($active?->bool('active')) {
                continue;
            }
            $due[] = $task;
            if (count($due) === $limit) {
                break;
            }
        }

        return $due;
    }
}
