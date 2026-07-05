<?php

declare(strict_types=1);

namespace App\Dto\Crawler;

/**
 * Один блок листинга, как его вернул `SourceMethod::collectListing()` — сырые строки
 * (ассоциативы по заголовку CSV) + метаданные забора для `crw_fetches` и cap-детекта
 * (`02-crawling.md` §3.3–§3.4). Нормализация в снапшот закупки — вне DTO (`ListingRowMapper`, P2.5).
 */
final readonly class ListingBatch
{
    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $requestParams
     */
    public function __construct(
        public array $rows,
        public string $rawBody,
        public ?string $dedupSignature,
        public int $rowsTotal,
        public int $httpStatus,
        public int $durationMs,
        public array $requestParams,
        public ?int $proxyId = null,
    ) {}
}
