<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Rules\JsonDetailRule;
use App\Dto\Procurement\Parsing\Rules\JsonListingRule;

/**
 * Контракт извлечения данных из JSON-ответов API по декларативным путям.
 */
interface JsonPayloadExtractor
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int|null,
     *     next_cursor: string|null,
     * }
     */
    public function extractListing(array $payload, JsonListingRule $rule): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     fields: array<string, mixed>,
     *     documents: list<array<string, mixed>>,
     *     events: list<array<string, mixed>>,
     * }
     */
    public function extractDetail(array $payload, JsonDetailRule $rule): array;
}
