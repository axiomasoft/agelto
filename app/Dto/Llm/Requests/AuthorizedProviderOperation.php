<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

/** Provisional financial authorization. P3.10's joint gate alone may grant transport. */
final readonly class AuthorizedProviderOperation
{
    public function __construct(
        public string $logicalRequestId,
        public string $physicalCallId,
        public int $requestEpoch,
        public string $sendAuthorizationToken,
        public string $ownerAttemptId,
        public int $ownerWorkGeneration,
        public string $reservationId,
    ) {}
}
