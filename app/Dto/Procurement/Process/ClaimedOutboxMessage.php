<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\OutboxKind;

/**
 * Outbox message, claim-нутый consumer под его token. Token подтверждает право
 * пометить сообщение: чужой claim после takeover не может ни mark, ни quarantine.
 */
final readonly class ClaimedOutboxMessage
{
    public function __construct(
        public ProcessUuid $messageId,
        public OutboxKind $kind,
        public VersionedPayload $payload,
        public ProcessUuid $claimToken,
        public int $attempts,
    ) {
        ProcessValue::positive($attempts);
    }
}
