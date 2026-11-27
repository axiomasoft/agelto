<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Enums\Llm\OperationPurpose;

/** Immutable pre-read, revalidated under account → period → request/call locks before writes. */
final readonly class ProviderCall
{
    /** @param numeric-string $upperBound */
    public function __construct(
        public AuthorizedProviderOperation $authorization,
        public OperationPurpose $purpose,
        public string $recipeHash,
        public string $inputHash,
        public PinnedLlmContext $context,
        public string $currency,
        public string $periodId,
        public string $upperBound,
        public string $requestState,
        public bool $claimed,
        public string $receiptId,
        public string $receiptDisk,
        public string $receiptUploadKey,
        public ?string $receiptHash,
        public ?string $outcome,
        public ?ReceiptAssessment $recordedAssessment,
        public ?string $wireFingerprint = null,
    ) {}
}
