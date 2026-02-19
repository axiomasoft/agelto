<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class DocumentDetailObject extends Data
{
    /**
     * @param  DataCollection<int, FooterItem>  $footer
     */
    public function __construct(
        public ObjectPassport $passport,
        public HeaderSchema $header_schema,
        #[DataCollectionOf(FooterItem::class)]
        public DataCollection $footer = new DataCollection(FooterItem::class, []),
    ) {}
}
