<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Dto\Procurement\Ai\Extraction\DiscoveryCheckpoint;
use App\Dto\Procurement\Ai\Screening\ScreeningCompleted;
use App\Dto\Procurement\Ai\Screening\ScreeningProgress;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;
use App\Services\Procurement\Process\Infrastructure\StagePayloadCodec;

final class ScreeningStageCodec implements StagePayloadCodec
{
    public function stage(): StageCode
    {
        return StageCode::Screening;
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return new ScreeningProgress;
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        if (! $checkpoint instanceof ScreeningProgress) {
            throw new \InvalidArgumentException('Unexpected screening progress.');
        }

        return new VersionedPayload(CanonicalJson::encode(['reask_ordinal' => $checkpoint->reaskOrdinal,
            'result' => $checkpoint->result?->toArray(), 'blocked' => $checkpoint->blocked?->value,
            'discovery' => $checkpoint->discovery?->fields()]), 2);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        $r = $this->reader($payload);
        $r->keys($payload->schemaVersion === 1 ? ['reask_ordinal', 'result', 'blocked'] : ['reask_ordinal', 'result', 'blocked', 'discovery']);
        $blocked = $r->isNull('blocked') ? null : $r->string('blocked');

        return new ScreeningProgress($r->integer('reask_ordinal'), $r->object->result === null ? null :
            (new ScreeningContract)->restore(CanonicalJson::encode($r->object('result')->object)),
            $blocked === null ? null : BlockedReason::from($blocked), discovery: $payload->schemaVersion === 1 || $r->isNull('discovery') ? null : DiscoveryCheckpoint::restore(CanonicalJson::encode($r->object('discovery')->object)));
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof ScreeningCompleted) {
            throw new \InvalidArgumentException('Unexpected screening result.');
        }

        return new VersionedPayload(CanonicalJson::encode(['run_id' => $result->runId->value, 'work_id' => $result->sourceWorkId->value->value, 'result' => $result->result->toArray()]), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        if ($payload->schemaVersion !== 1) {
            throw new \InvalidArgumentException('Unsupported screening result codec.');
        }
        $r = $this->reader($payload);
        $r->keys(['run_id', 'work_id', 'result']);

        return new ScreeningCompleted(new ProcessUuid($r->string('run_id')), new WorkId(new ProcessUuid($r->string('work_id'))),
            (new ScreeningContract)->restore(CanonicalJson::encode($r->object('result')->object)));
    }

    private function reader(VersionedPayload $payload): PayloadReader
    {
        if (! in_array($payload->schemaVersion, [1, 2], true) || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported screening codec.');
        }

        return PayloadReader::json($payload->json);
    }
}
