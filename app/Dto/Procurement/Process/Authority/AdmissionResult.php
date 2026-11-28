<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\DeliveryGeneration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Outcomes\AdmissionStatus;

/** Исход admit: dispatch generation и wake message существуют только у Admitted. */
final readonly class AdmissionResult
{
    private function __construct(
        public AdmissionStatus $status,
        public ?DeliveryGeneration $dispatchGeneration,
        public ?ProcessUuid $wakeMessageId,
    ) {}

    public static function admitted(DeliveryGeneration $dispatchGeneration, ProcessUuid $wakeMessageId): self
    {
        return new self(AdmissionStatus::Admitted, $dispatchGeneration, $wakeMessageId);
    }

    public static function refused(AdmissionStatus $status): self
    {
        if ($status === AdmissionStatus::Admitted) {
            throw new \InvalidArgumentException('An admission grant requires its dispatch generation.');
        }

        return new self($status, null, null);
    }
}
