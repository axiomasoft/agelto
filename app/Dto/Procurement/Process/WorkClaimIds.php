<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class WorkClaimIds
{
    public function __construct(
        public ProcessUuid $token,
        public ProcessUuid $attemptId,
        public ProcessUuid $eventId,
        public ProcessUuid $projectionMessageId,
        public ProcessUuid $wakeMessageId,
        public ProcessUuid $incidentId,
        public ProcessUuid $incidentEventId,
        public ProcessUuid $incidentProjectionMessageId,
    ) {}

    public static function allocate(): self
    {
        return new self(
            token: ProcessUuid::random(),
            attemptId: ProcessUuid::random(),
            eventId: ProcessUuid::random(),
            projectionMessageId: ProcessUuid::random(),
            wakeMessageId: ProcessUuid::random(),
            incidentId: ProcessUuid::random(),
            incidentEventId: ProcessUuid::random(),
            incidentProjectionMessageId: ProcessUuid::random(),
        );
    }
}
