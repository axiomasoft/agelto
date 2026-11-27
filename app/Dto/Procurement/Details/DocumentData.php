<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

/**
 * Результат разбора одной строки вкладки `documents` карточки закупки ЕИС
 * (`DocumentsParser::parse()`). Плоские поля 1:1 к `prc_documents`
 * (`title`/`kind`/`source_url`/`external_id`) — без файла, файл качает `DocumentDownloader`.
 */
final readonly class DocumentData
{
    public function __construct(
        public string $title,
        public ?string $kind,
        public ?string $sourceUrl,
        public ?string $externalId,
    ) {}
}
