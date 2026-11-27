<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;

/** Shared helpers for prepared representation storage paths and unprocessable marking across strategies. */
final class PreparedRepresentationHelper
{
    public static function preparedPath(DocumentVersion $version, string $suffix): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.{$suffix}";
    }

    public static function preparedPathForKind(DocumentVersion $version, RepresentationKind $kind): string
    {
        return self::preparedPath($version, "{$kind->value}.{$kind->extension()}");
    }

    public static function markUnprocessable(
        MarkDocumentUnprocessableAction $action,
        Document $document,
        UnprocessableReason $reason,
        string $detail,
    ): void {
        $action->execute($document, $reason, $detail);
    }
}
