<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\PreparationResult;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;

/**
 * Катч-всё стратегия (PDF/текст/прочее, не подошедшее `ArchiveStrategy`/`SpreadsheetStrategy`/
 * `ImageStrategy`, ТЗ 03 §3.3) — пропускает файл дальше как есть. Всегда последняя
 * в `config('ai.prepare.strategies')`.
 */
final readonly class PassthroughStrategy implements PreparationStrategy
{
    public function process(Document $document, DocumentVersion $version): PreparationResult
    {
        // Нет представления, отличного от оригинала — downstream использует storage_path как есть.
        // `true` — оригинал сам по себе валидный вход, цепочка на этом заканчивается.
        return PreparationResult::ReadyForScreening;
    }
}
