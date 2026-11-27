<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Repositories\Procurement\Document\DocumentWorkRepository;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Str;

/** Allocates durable due cycles only. DocumentFeeder owns activation. */
final readonly class DocumentRevalidator
{
    public function __construct(private ProcessTransaction $transactions, private DocumentWorkRepository $works) {}

    public function allocate(int $documentId): ?ProcessUuid
    {
        if ($documentId < 1) {
            throw new \InvalidArgumentException('Document identity required.');
        }
        $intent = new ProcessUuid((string) Str::uuid());

        return $this->transactions->run(fn (): ?ProcessUuid => $this->works->allocateRevalidationLocked(
            documentId: $documentId,
            intentId: $intent,
        ));
    }
}
