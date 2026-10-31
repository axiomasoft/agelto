<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\ValidatedAcquisitionResult;
use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterValidationReceipt;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Network\AdapterValidationOwnerKind;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\PayloadReader;
use App\Services\Procurement\Process\StagePayloadCodec;

/** Runtime result envelope; checkpoint progress retains the existing stage codec. */
final readonly class ValidatedAcquisitionCodec implements StagePayloadCodec
{
    public function __construct(private StagePayloadCodec $inner) {}

    public function stage(): StageCode
    {
        return $this->inner->stage();
    }

    public function initialCheckpoint(): StageCheckpoint
    {
        return $this->inner->initialCheckpoint();
    }

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload
    {
        return $this->inner->encodeCheckpoint($checkpoint);
    }

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint
    {
        return $this->inner->decodeCheckpoint($payload);
    }

    public function encodeResult(ValidatedStageResult $result): VersionedPayload
    {
        if (! $result instanceof ValidatedAcquisitionResult) {
            throw new \InvalidArgumentException('Validated acquisition result required.');
        }
        $payload = $this->inner->encodeResult($result->result);
        $r = $result->receipt;

        return new VersionedPayload(json_encode(['result' => $payload->json, 'version' => $payload->schemaVersion,
            'receipt' => ['health' => $r->healthId->value, 'epoch' => $r->epoch, 'contract' => $r->contract->toArray(),
                'input' => $r->inputHash->hex, 'observation' => $r->observationId->value, 'owner' => $r->ownerId->value,
                'owner_kind' => $r->ownerKind->value, 'requests' => array_map(fn (ProcessUuid $id): string => $id->value, $r->requestIds),
                'verdict' => $r->verdict->value]], JSON_THROW_ON_ERROR), 1);
    }

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult
    {
        if ($payload->schemaVersion !== 1 || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported acquisition validation envelope.');
        }
        $r = PayloadReader::json($payload->json);
        $r->keys(['result', 'version', 'receipt']);
        $v = $r->object('receipt');
        $v->keys(['health', 'epoch', 'contract', 'input', 'observation', 'owner', 'owner_kind', 'requests', 'verdict']);
        $c = $v->object('contract');
        $c->keys(['platform_code', 'capability', 'adapter_version', 'contract_version']);
        $receipt = new AdapterValidationReceipt(new ProcessUuid($v->string('health')), $v->integer('epoch'),
            new AdapterContractRef($c->string('platform_code'), $c->string('capability'), $c->string('adapter_version'), $c->string('contract_version')),
            new ContentHash($v->string('input')), new ProcessUuid($v->string('observation')), new ProcessUuid($v->string('owner')),
            array_map(fn (string $id): ProcessUuid => new ProcessUuid($id), $v->strings('requests')), false,
            AdapterVerdict::from($v->string('verdict')), AdapterValidationOwnerKind::from($v->string('owner_kind')));

        return new ValidatedAcquisitionResult($this->inner->decodeResult(new VersionedPayload($r->string('result'), $r->integer('version'))), $receipt);
    }
}
