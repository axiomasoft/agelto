<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class DocumentDetailObjectDto extends Data
{
    /**
     * @param  DataCollection<int, FooterItemDto>  $footer
     */
    public function __construct(
        public ObjectPassportDto $passport,
        public HeaderSchemaDto $header_schema,
        #[DataCollectionOf(FooterItemDto::class)]
        public DataCollection $footer = new DataCollection(FooterItemDto::class, []),
    ) {}
}
