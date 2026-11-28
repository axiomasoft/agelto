<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Rules\EventJournalRule;
use DOMXPath;

/**
 * Контракт извлечения и классификации событий из журнала событий закупки.
 */
interface EventTableExtractor
{
    /**
     * @return list<array{
     *     date: string|null,
     *     description: string,
     *     event_type: string|null,
     * }>
     */
    public function extract(DOMXPath $xpath, EventJournalRule $rule): array;
}
