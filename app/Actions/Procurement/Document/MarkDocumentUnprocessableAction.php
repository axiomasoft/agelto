<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class MarkDocumentUnprocessableAction
{
    public function __construct(private DocumentStoreRepository $documents) {}

    public function execute(Document $document, UnprocessableReason $reason, string $detail): void
    {
        DB::transaction(function () use ($document, $reason, $detail): void {
            $this->documents->update($document, [
                'status' => DocumentStatus::Unprocessable,
                'unprocessable_reason' => $reason,
                'last_error' => "unprocessable:{$reason->value}: {$detail}",
            ]);
        });
    }
}
