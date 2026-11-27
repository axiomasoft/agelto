<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use Illuminate\Support\Str;

/** Allocate before a retryable transaction; no external identity changes during SQL retry. */
final readonly class ProviderOperationIds
{
    public function __construct(
        public string $logicalRequestId,
        public string $physicalCallId,
        public string $reservationId,
        public string $authorizationToken,
        public string $receiptId,
    ) {
        foreach ([$logicalRequestId, $physicalCallId, $reservationId, $authorizationToken, $receiptId] as $id) {
            if (! Str::isUuid($id)) {
                throw new \InvalidArgumentException('Invalid provider operation UUID.');
            }
        }
    }

    public static function allocate(): self
    {
        return new self((string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid());
    }
}
