<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\RowRange;
use App\Services\Procurement\Process\PayloadReader;

final class AnalysisRangeCodec
{
    public static function restore(PayloadReader $reader): RowRange|PageRange
    {
        if (property_exists($reader->object, 'unit')) {
            $reader->keys(['start', 'end', 'unit']);
            if ($reader->string('unit') !== 'page') {
                throw new \InvalidArgumentException('Unknown analysis range unit.');
            }

            return new PageRange($reader->integer('start'), $reader->integer('end'));
        }
        $reader->keys(['start', 'end']);

        return new RowRange($reader->integer('start'), $reader->integer('end'));
    }
}
