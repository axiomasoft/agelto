<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema\Responses;

use App\Process\EstimateAnalysis\Schema\EstimateFooterItem;
use App\Process\EstimateAnalysis\Schema\EstimateHeader;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Шапка и итоги сметы (в ответе EstimateFetchInfoResponse).
 * estimate_id, generator, header (EstimateHeader), footer (EstimateFooterItem[]).
 */
final class EstimateFetchInfo extends Data
{
    public function __construct(
        public string $estimate_id,
        public ?string $generator = null,
        public EstimateHeader $header,
        #[DataCollectionOf(EstimateFooterItem::class)]
        public DataCollection $footer = new DataCollection(EstimateFooterItem::class, []),
    ) {}
}
