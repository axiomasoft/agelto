<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema\Responses;

use App\Process\EstimateAnalysis\Schema\EstimatePassport;
use Spatie\LaravelData\Data;

/**
 * Элемент превью сметы в ответе EstimatePreviewResponse.
 * passport, items_count, items_count_total, page_start, page_end.
 */
final class EstimatePreview extends Data
{
    public function __construct(
        public EstimatePassport $passport,
        public int $items_count,
        public int $items_count_total,
        public int $page_start,
        public int $page_end,
    ) {}
}
