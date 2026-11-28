<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Rules\DocumentsRule;
use DOMXPath;

/**
 * Контракт извлечения списка документов из DOM карточки закупки.
 */
interface DocumentListExtractor
{
    /**
     * @return list<array{
     *     title: string|null,
     *     url: string|null,
     *     hash: string|null,
     *     size: string|null,
     *     published_at: string|null,
     * }>
     */
    public function extract(DOMXPath $xpath, DocumentsRule $rule): array;
}
