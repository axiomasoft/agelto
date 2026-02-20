<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema;

use Platform\Process\DocumentAnalysis\Schema\Estimate\EstimateFooterItem;
use Platform\Process\DocumentAnalysis\Schema\Estimate\EstimateHeaderSchema;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class DocumentDetailObject extends Data
{
    /**
     * @param  DataCollection<int, EstimateFooterItem>  $footer
     */
    public function __construct(
        public ObjectPassport $passport,
        public EstimateHeaderSchema $header_schema,
        #[DataCollectionOf(EstimateFooterItem::class)]
        public DataCollection $footer = new DataCollection(EstimateFooterItem::class, []),
    ) {}
}
