<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Status;

use App\Dto\Procurement\Acquisition\Control\StatusProgress;
use App\Dto\Procurement\Acquisition\Control\StatusResult;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Acquisition\Detail\DetailValueCodec;
use App\Services\Procurement\Process\Infrastructure\StagePayloadCodec;

final readonly class StatusStageCodec implements StagePayloadCodec
{
    public function __construct(private DetailValueCodec $values) {}

    public function stage(): StageCode
    {
        return StageCode::StatusRefresh;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new StatusProgress;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof StatusProgress) {
            throw new \InvalidArgumentException('Status progress required.');
        }

        return new VersionedPayload($this->values->encode($checkpoint), 1);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        $value = $this->decode($payload);
        if (! $value instanceof StatusProgress) {
            throw new \InvalidArgumentException('Status progress required.');
        }

        return $value;
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof StatusResult) {
            throw new \InvalidArgumentException('Status result required.');
        }

        return new VersionedPayload($this->values->encode($result), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        $value = $this->decode($payload);
        if (! $value instanceof StatusResult) {
            throw new \InvalidArgumentException('Status result required.');
        }

        return $value;
    }

    private function decode(VersionedPayload $payload): object
    {
        if ($payload->schemaVersion !== 1 || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported status schema.');
        }

        return $this->values->decode($payload->json);
    }
}
