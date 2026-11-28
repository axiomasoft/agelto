<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;
use App\Services\Procurement\Process\Infrastructure\StagePayloadCodec;

/** Fixture-only codec. Never registered with application handlers or a provider. */
final readonly class FixtureStagePayloadCodec implements StagePayloadCodec
{
    public function __construct(private StageCode $stageCode = StageCode::Listing) {}

    public function stage(): StageCode
    {
        return $this->stageCode;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new FixtureStageCheckpoint(0, $this->stageCode);
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof FixtureStageCheckpoint || $checkpoint->stage() !== $this->stageCode) {
            throw new \InvalidArgumentException('Unknown fixture checkpoint.');
        }

        return new VersionedPayload(json_encode(['position' => $checkpoint->position], JSON_THROW_ON_ERROR), 1);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        $reader = $this->reader($payload);
        $reader->keys(['position']);

        return new FixtureStageCheckpoint($reader->integer('position'), $this->stageCode);
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof FixtureStageResult || $result->stage() !== $this->stageCode) {
            throw new \InvalidArgumentException('Unknown fixture result.');
        }

        return new VersionedPayload(json_encode(['disk' => $result->artifact->disk, 'key' => $result->artifact->key, 'sha256' => $result->artifact->sha256->hex], JSON_THROW_ON_ERROR), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        $reader = $this->reader($payload);
        $reader->keys(['disk', 'key', 'sha256']);

        return new FixtureStageResult(new ArtifactRef($reader->string('disk'), $reader->string('key'), new ContentHash($reader->string('sha256'))), $this->stageCode);
    }

    private function reader(VersionedPayload $payload): PayloadReader
    {
        if ($payload->json === null || $payload->schemaVersion !== 1) {
            throw new \InvalidArgumentException('Unknown fixture schema version.');
        }

        return PayloadReader::json($payload->json);
    }
}
