<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\PublicationCompleted;
use App\Dto\Procurement\Ai\PublicationProgress;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\PayloadReader;
use App\Services\Procurement\Process\StagePayloadCodec;

final class PublicationStageCodec implements StagePayloadCodec
{
    public function stage(): StageCode
    {
        return StageCode::Publication;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new PublicationProgress;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof PublicationProgress) {
            throw new \InvalidArgumentException('Unexpected publication progress.');
        }

        return new VersionedPayload('{}', 1);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        $this->reader($payload)->keys([]);

        return new PublicationProgress;
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof PublicationCompleted) {
            throw new \InvalidArgumentException('Unexpected publication result.');
        }

        return new VersionedPayload(CanonicalJson::encode(['run_id' => $result->runId->value, 'recipe_hash' => $result->recipeHash->hex,
            'generation' => $result->generation, 'digest' => $result->digest->hex]), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        $r = $this->reader($payload);
        $r->keys(['run_id', 'recipe_hash', 'generation', 'digest']);

        return new PublicationCompleted(new ProcessUuid($r->string('run_id')), new ContentHash($r->string('recipe_hash')),
            $r->integer('generation'), new ContentHash($r->string('digest')));
    }

    private function reader(VersionedPayload $payload): PayloadReader
    {
        if ($payload->schemaVersion !== 1 || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported publication codec.');
        }

        return PayloadReader::json($payload->json);
    }
}
