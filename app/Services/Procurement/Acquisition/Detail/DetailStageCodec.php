<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Detail\DetailResult;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Process\Infrastructure\StagePayloadCodec;

final readonly class DetailStageCodec implements StagePayloadCodec
{
    public function __construct(private DetailValueCodec $values) {}

    public function stage(): StageCode
    {
        return StageCode::Detail;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new DetailProgress;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof DetailProgress) {
            throw new \InvalidArgumentException('Detail progress required.');
        }

        return new VersionedPayload($this->values->encode($checkpoint), 1);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        $value = $this->decode($payload);
        if (! $value instanceof DetailProgress) {
            throw new \InvalidArgumentException('Detail progress required.');
        }

        return $value;
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof DetailResult) {
            throw new \InvalidArgumentException('Detail result required.');
        }

        return new VersionedPayload($this->values->encode($result), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        $value = $this->decode($payload);
        if (! $value instanceof DetailResult) {
            throw new \InvalidArgumentException('Detail result required.');
        }

        return $value;
    }

    private function decode(VersionedPayload $payload): object
    {
        if ($payload->schemaVersion !== 1 || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported detail schema.');
        }

        return $this->values->decode($payload->json);
    }
}
