<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Orchestration;

use App\Dto\Procurement\Ai\Extraction\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\Extraction\ExtractionCompleted;
use App\Dto\Procurement\Ai\Extraction\ExtractionProgress;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;
use App\Services\Procurement\Process\Infrastructure\StagePayloadCodec;

final class ExtractionStageCodec implements StagePayloadCodec
{
    public function stage(): StageCode
    {
        return StageCode::Extraction;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new ExtractionProgress;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof ExtractionProgress) {
            throw new \InvalidArgumentException('Unexpected extraction checkpoint.');
        }

        return new VersionedPayload(CanonicalJson::encode(['progress' => $checkpoint->checkpoint === null ? null : json_decode($checkpoint->checkpoint->json(), flags: JSON_THROW_ON_ERROR),
            'depths' => (object) $checkpoint->depths, 'reasks' => (object) $checkpoint->reasks, 'blocked' => $checkpoint->blocked?->value]), 1);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        if ($payload->schemaVersion !== 1 || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported extraction progress codec.');
        }
        $r = PayloadReader::json($payload->json);
        $r->keys(['progress', 'depths', 'reasks', 'blocked']);
        $progress = $r->object->progress;
        $blocked = $r->object->blocked;
        if ($blocked !== null && ! is_string($blocked)) {
            throw new \InvalidArgumentException('Invalid extraction blocked reason.');
        }

        return new ExtractionProgress($progress === null ? null : ExtractionCheckpoint::restore(CanonicalJson::encode($progress)),
            $this->ordinals($r->object('depths')), $this->ordinals($r->object('reasks')), $blocked === null ? null : BlockedReason::from($blocked));
    }

    /** @return array<string,int> */
    private function ordinals(PayloadReader $r): array
    {
        $result = [];
        foreach (get_object_vars($r->object) as $key => $value) {
            if (! is_string($key) || ! is_int($value)) {
                throw new \InvalidArgumentException('Invalid extraction ordinal.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof ExtractionCompleted) {
            throw new \InvalidArgumentException('Unexpected extraction result.');
        }

        return new VersionedPayload($result->checkpoint->json(), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        if ($payload->schemaVersion !== 1 || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported extraction result codec.');
        }

        return new ExtractionCompleted(ExtractionCheckpoint::restore($payload->json));
    }
}
