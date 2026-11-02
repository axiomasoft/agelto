<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\ChunkReference;
use App\Dto\Procurement\Ai\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\RowRange;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\PayloadReader;

final class CoverageValidator
{
    /** Every accepted range and pending range must form exactly the immutable parent. */
    public function assertPartition(AnalysisWindowSnapshot $window, ExtractionCheckpoint $checkpoint): void
    {
        $ranges = $checkpoint->pending;
        $seen = [];
        foreach ($checkpoint->acceptedChunks as $ref) {
            if (isset($seen[$ref->id->value])) {
                throw new \InvalidArgumentException('Duplicate accepted chunk reference.');
            }
            $seen[$ref->id->value] = true;
            $ranges[] = $this->range($window, $ref);
        }
        usort($ranges, static fn (RowRange|PageRange $a, RowRange|PageRange $b): int => $a->start <=> $b->start);
        $cursor = $window->range->start;
        foreach ($ranges as $range) {
            if ($range::class !== $window->range::class || $range->start !== $cursor || $range->end > $window->range->end) {
                throw new \InvalidArgumentException('Accepted and pending coverage overlap or leave a gap.');
            }
            $cursor = $range->end;
        }
        if ($cursor !== $window->range->end) {
            throw new \InvalidArgumentException('Incomplete parent coverage.');
        }
        $stored = LedgerRow::one('SELECT count(*) AS count FROM prc_analysis_chunks WHERE window_id=?', [$window->id->value]);
        if ($stored?->int('count') !== count($seen)) {
            throw new \InvalidArgumentException('Checkpoint dropped an accepted chunk.');
        }
    }

    private function range(AnalysisWindowSnapshot $window, ChunkReference $ref): RowRange|PageRange
    {
        $row = LedgerRow::one("SELECT provenance::text AS provenance,encode(output_hash,'hex') AS hash FROM prc_analysis_chunks
            WHERE id=? AND window_id=? AND recipe_hash=decode(?,'hex')", [$ref->id->value, $window->id->value, $window->run->recipe->hash->hex]);
        if ($row === null || $row->string('hash') !== $ref->outputHash->hex) {
            throw new \InvalidArgumentException('Accepted chunk identity/hash mismatch.');
        }
        $r = PayloadReader::json($row->string('provenance'))->object('range');

        return AnalysisRangeCodec::restore($r);
    }
}
