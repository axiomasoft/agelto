<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Infrastructure;

use App\Dto\Procurement\Process\Authority\FairnessCursor;
use App\Dto\Procurement\Process\Execution\WorkWake;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Inputs\DownloadInput;
use App\Dto\Procurement\Process\Inputs\ExtractionWindowInput;
use App\Dto\Procurement\Process\Inputs\ListingInput;
use App\Dto\Procurement\Process\Inputs\PrepareInput;
use App\Dto\Procurement\Process\Inputs\PublicationInput;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Inputs\StatusRefreshInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageFailedItem;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Outcomes\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\DeliveryGeneration;
use App\Dto\Procurement\Process\Primitives\MissingParts;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\NoProviderRecovery;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\Provider\ReceiptRef;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Enums\Procurement\Process\Outcomes\ItemFailureReason;
use App\Enums\Procurement\Process\Outcomes\ProviderOperationPurpose;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;

/** Pure, versioned boundary. No registry activation, ledger, I/O or provider interpretation. */
final class ProcessPayloadCodec
{
    /** @var array<string, StagePayloadCodec> */
    private array $stages = [];

    /** @param list<StagePayloadCodec> $stages */
    public function __construct(array $stages = [])
    {
        foreach ($stages as $codec) {
            $key = $codec->stage()->value;
            if (isset($this->stages[$key])) {
                throw new \InvalidArgumentException('Duplicate stage codec: '.$key);
            }
            $this->stages[$key] = $codec;
        }
    }

    public function encodeInput(WorkInput $input): VersionedPayload
    {
        return $this->payload(match (true) {
            $input instanceof ListingInput => ['stage' => StageCode::Listing->value, 'crawl_block_id' => $input->crawlBlockId->value, 'filter_hash' => $input->filterHash->hex, 'access_policy_hash' => $input->accessPolicyHash->hex, 'adapter_version' => $input->adapterVersion, 'cursor_version' => $input->cursorVersion],
            $input instanceof DetailInput => ['stage' => StageCode::Detail->value, 'procurement_id' => $input->procurementId, 'intent_id' => $input->intentId->value, 'expected_head_generation' => $input->expectedHeadGeneration, 'semantic_policy_hash' => $input->semanticPolicyHash->hex, 'access_policy_hash' => $input->accessPolicyHash->hex, 'adapter_version' => $input->adapterVersion],
            $input instanceof StatusRefreshInput => ['stage' => StageCode::StatusRefresh->value, 'procurement_id' => $input->procurementId, 'cycle_seq' => $input->cycleSeq, 'status_policy_hash' => $input->statusPolicyHash->hex, 'adapter_version' => $input->adapterVersion],
            $input instanceof DownloadInput => ['stage' => StageCode::Download->value, 'document_id' => $input->documentId, 'acquisition_intent_id' => $input->acquisitionIntentId->value, 'expected_document_head_generation' => $input->expectedDocumentHeadGeneration, 'access_policy_hash' => $input->accessPolicyHash->hex],
            $input instanceof PrepareInput => ['stage' => StageCode::Prepare->value, 'document_version_id' => $input->documentVersionId, 'preparation_recipe_hash' => $input->preparationRecipeHash->hex],
            $input instanceof ScreeningInput => ['stage' => StageCode::Screening->value, 'document_version_id' => $input->documentVersionId, 'representation_hash' => $input->representationHash->hex, 'screening_recipe_hash' => $input->screeningRecipeHash->hex],
            $input instanceof ExtractionWindowInput => ['stage' => StageCode::Extraction->value, 'analysis_run_id' => $input->analysisRunId->value, 'window_key' => $input->windowKey, 'recipe_hash' => $input->recipeHash->hex],
            $input instanceof PublicationInput => ['stage' => StageCode::Publication->value, 'analysis_run_id' => $input->analysisRunId->value, 'recipe_hash' => $input->recipeHash->hex, 'result_generation' => $input->resultGeneration],
            default => throw new \InvalidArgumentException('Unknown WorkInput variant.'),
        });
    }

    public function decodeInput(VersionedPayload $payload): WorkInput
    {
        $reader = $this->required($payload);
        $stage = $this->stage($reader->string('stage'));
        $reader->keys(match ($stage) {
            StageCode::Listing => ['stage', 'crawl_block_id', 'filter_hash', 'access_policy_hash', 'adapter_version', 'cursor_version'],
            StageCode::Detail => ['stage', 'procurement_id', 'intent_id', 'expected_head_generation', 'semantic_policy_hash', 'access_policy_hash', 'adapter_version'],
            StageCode::StatusRefresh => ['stage', 'procurement_id', 'cycle_seq', 'status_policy_hash', 'adapter_version'],
            StageCode::Download => ['stage', 'document_id', 'acquisition_intent_id', 'expected_document_head_generation', 'access_policy_hash'],
            StageCode::Prepare => ['stage', 'document_version_id', 'preparation_recipe_hash'],
            StageCode::Screening => ['stage', 'document_version_id', 'representation_hash', 'screening_recipe_hash'],
            StageCode::Extraction => ['stage', 'analysis_run_id', 'window_key', 'recipe_hash'],
            StageCode::Publication => ['stage', 'analysis_run_id', 'recipe_hash', 'result_generation'],
        });

        return match ($stage) {
            StageCode::Listing => new ListingInput($this->uuid($reader, 'crawl_block_id'), $this->hash($reader, 'filter_hash'), $this->hash($reader, 'access_policy_hash'), $reader->string('adapter_version'), $reader->integer('cursor_version')),
            StageCode::Detail => new DetailInput($reader->integer('procurement_id'), $this->uuid($reader, 'intent_id'), $reader->integer('expected_head_generation'), $this->hash($reader, 'semantic_policy_hash'), $this->hash($reader, 'access_policy_hash'), $reader->string('adapter_version')),
            StageCode::StatusRefresh => new StatusRefreshInput($reader->integer('procurement_id'), $reader->integer('cycle_seq'), $this->hash($reader, 'status_policy_hash'), $reader->string('adapter_version')),
            StageCode::Download => new DownloadInput($reader->integer('document_id'), $this->uuid($reader, 'acquisition_intent_id'), $reader->integer('expected_document_head_generation'), $this->hash($reader, 'access_policy_hash')),
            StageCode::Prepare => new PrepareInput($reader->integer('document_version_id'), $this->hash($reader, 'preparation_recipe_hash')),
            StageCode::Screening => new ScreeningInput($reader->integer('document_version_id'), $this->hash($reader, 'representation_hash'), $this->hash($reader, 'screening_recipe_hash')),
            StageCode::Extraction => new ExtractionWindowInput($this->uuid($reader, 'analysis_run_id'), $reader->string('window_key'), $this->hash($reader, 'recipe_hash')),
            StageCode::Publication => new PublicationInput($this->uuid($reader, 'analysis_run_id'), $this->hash($reader, 'recipe_hash'), $reader->integer('result_generation')),
        };
    }

    public function encodeCheckpoint(?WorkCheckpointEnvelope $checkpoint): VersionedPayload
    {
        if ($checkpoint === null) {
            return new VersionedPayload(null, null);
        }

        return $this->payload([
            'stage_progress' => $this->encodeProgress($checkpoint->stageProgress),
            'provider_recovery_plan' => $this->encodeRecovery($checkpoint->providerRecoveryPlan),
        ]);
    }

    public function decodeCheckpoint(VersionedPayload $payload): ?WorkCheckpointEnvelope
    {
        if ($payload->json === null) {
            return null;
        }
        $reader = $this->required($payload);
        $reader->keys(['stage_progress', 'provider_recovery_plan']);

        return new WorkCheckpointEnvelope(
            $this->decodeProgress($reader->object('stage_progress')),
            $this->decodeRecovery($reader->object('provider_recovery_plan')),
        );
    }

    public function encodeOutcome(StageOutcome $outcome): VersionedPayload
    {
        return $this->payload(match (true) {
            $outcome instanceof StageSucceeded => ['variant' => 'succeeded', 'result' => $this->encodeResult($outcome->result)],
            $outcome instanceof StagePartial => ['variant' => 'partial', 'checkpoint' => $this->encodeProgress($outcome->checkpoint), 'missing' => $outcome->missing->values],
            $outcome instanceof StageDeferred => ['variant' => 'deferred', 'reason' => $outcome->reason->value, 'retry_at' => $outcome->retryAt->value->format('Y-m-d\TH:i:s.u\Z')],
            $outcome instanceof StageBlocked => ['variant' => 'blocked', 'reason' => $outcome->reason->value],
            $outcome instanceof StageFailedItem => ['variant' => 'failed_item', 'reason' => $outcome->reason->value, 'evidence' => $this->artifact($outcome->evidence)],
            default => throw new \InvalidArgumentException('Unknown StageOutcome variant.'),
        });
    }

    public function decodeOutcome(VersionedPayload $payload): StageOutcome
    {
        $reader = $this->required($payload);
        $variant = $reader->string('variant');
        $reader->keys(match ($variant) {
            'succeeded' => ['variant', 'result'],
            'partial' => ['variant', 'checkpoint', 'missing'],
            'deferred' => ['variant', 'reason', 'retry_at'],
            'blocked' => ['variant', 'reason'],
            'failed_item' => ['variant', 'reason', 'evidence'],
            default => throw new \InvalidArgumentException('Unknown StageOutcome variant.'),
        });

        return match ($variant) {
            'succeeded' => new StageSucceeded($this->decodeResult($reader->object('result'))),
            'partial' => new StagePartial($this->decodeProgress($reader->object('checkpoint')), new MissingParts($reader->strings('missing'))),
            'deferred' => new StageDeferred(DeferralReason::tryFrom($reader->string('reason')) ?? throw new \InvalidArgumentException('Unknown deferral reason.'), $this->retryAt($reader->string('retry_at'))),
            'blocked' => new StageBlocked(BlockedReason::tryFrom($reader->string('reason')) ?? throw new \InvalidArgumentException('Unknown blocked reason.')),
            'failed_item' => new StageFailedItem(ItemFailureReason::tryFrom($reader->string('reason')) ?? throw new \InvalidArgumentException('Unknown item failure reason.'), $this->decodeArtifact($reader->object('evidence'))),
        };
    }

    /** Lane cursor v1: пустой cursor — `{}` (DDL default), иначе last_cohort + last_stage_by_cohort. */
    public function encodeFairnessCursor(FairnessCursor $cursor): VersionedPayload
    {
        if ($cursor->lastCohort === null && $cursor->lastStageByCohort === []) {
            return new VersionedPayload('{}', 1);
        }

        return $this->payload([
            'last_cohort' => $cursor->lastCohort,
            'last_stage_by_cohort' => (object) array_map(static fn (StageCode $stage): string => $stage->value, $cursor->lastStageByCohort),
        ]);
    }

    public function decodeFairnessCursor(VersionedPayload $payload): FairnessCursor
    {
        $reader = $this->required($payload);
        if ($reader->object == new \stdClass) {
            return FairnessCursor::empty();
        }
        $reader->keys(['last_cohort', 'last_stage_by_cohort']);
        $stages = [];
        foreach (get_object_vars($reader->object('last_stage_by_cohort')->object) as $cohort => $stage) {
            $stages[(string) $cohort] = $this->stage(is_string($stage) ? $stage : throw new \InvalidArgumentException('Expected cursor stage.'));
        }

        return new FairnessCursor($reader->isNull('last_cohort') ? null : $reader->string('last_cohort'), $stages);
    }

    /** Typed work_wake payload: только scalar IDs, без моделей и credentials. */
    public function encodeWorkWake(WorkId $workId, DeliveryGeneration $dispatchGeneration, ProcessUuid $messageId, StageCode $stage): VersionedPayload
    {
        return $this->payload([
            'work_id' => $workId->value->value,
            'dispatch_generation' => $dispatchGeneration->value,
            'message_id' => $messageId->value,
            'queue_schema_version' => 1,
            'stage' => $stage->value,
        ]);
    }

    /** Typed feeder_wake payload: только parent lane key, без work IDs и payload. */
    public function encodeFeederWake(string $laneKey): VersionedPayload
    {
        return $this->payload(['lane_key' => self::checkLaneKey($laneKey)]);
    }

    /** Strict обратный разбор work_wake: неизвестные ключи, stage и схема отвергаются, не угадываются. */
    public function decodeWorkWake(VersionedPayload $payload): WorkWake
    {
        $reader = $this->required($payload);
        $reader->keys(['work_id', 'dispatch_generation', 'message_id', 'queue_schema_version', 'stage']);

        return new WorkWake(
            workId: new WorkId($this->uuid($reader, 'work_id')),
            dispatchGeneration: new DeliveryGeneration($reader->integer('dispatch_generation')),
            messageId: $this->uuid($reader, 'message_id'),
            queueSchemaVersion: $reader->integer('queue_schema_version'),
            stage: $this->stage($reader->string('stage')),
        );
    }

    /** @return string канонический parent/child lane key */
    public function decodeFeederWake(VersionedPayload $payload): string
    {
        $reader = $this->required($payload);
        $reader->keys(['lane_key']);

        return self::checkLaneKey($reader->string('lane_key'));
    }

    /** Projection payload (`project:<event_id>`): только event_id, источником факта остаётся ledger event. */
    public function decodeProjection(VersionedPayload $payload): ProcessUuid
    {
        $reader = $this->required($payload);
        $reader->keys(['event_id']);

        return $this->uuid($reader, 'event_id');
    }

    private function uuid(PayloadReader $reader, string $key): ProcessUuid
    {
        return new ProcessUuid($reader->string($key));
    }

    private function hash(PayloadReader $reader, string $key): ContentHash
    {
        return new ContentHash($reader->string($key));
    }

    private static function checkLaneKey(string $laneKey): string
    {
        if (preg_match('/^[a-z_]+(\.[a-z_]+)?$/D', $laneKey) !== 1) {
            throw new \InvalidArgumentException('Feeder wake requires a canonical lane key.');
        }

        return $laneKey;
    }

    /** @param array<string, mixed> $fields */
    private function payload(array $fields): VersionedPayload
    {
        return new VersionedPayload(json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1);
    }

    private function required(VersionedPayload $payload): PayloadReader
    {
        if ($payload->schemaVersion !== 1 || $payload->json === null) {
            throw new \InvalidArgumentException('Unsupported or absent process schema version.');
        }

        return PayloadReader::json($payload->json);
    }

    private function stage(string $stage): StageCode
    {
        return StageCode::tryFrom($stage) ?? throw new \InvalidArgumentException('Unknown stage code.');
    }

    private function codec(StageCode $stage): StagePayloadCodec
    {
        return $this->stages[$stage->value] ?? throw new \InvalidArgumentException('Unregistered stage codec: '.$stage->value);
    }

    /** @return array{stage: string, payload: \stdClass, schema_version: int} */
    private function stagePayload(StageCode $stage, VersionedPayload $payload): array
    {
        if ($payload->json === null || $payload->schemaVersion === null) {
            throw new \InvalidArgumentException('Stage codec returned an absent payload.');
        }

        return ['stage' => $stage->value, 'payload' => PayloadReader::json($payload->json)->object, 'schema_version' => $payload->schemaVersion];
    }

    /** @return array{stage: string, payload: \stdClass, schema_version: int} */
    private function encodeProgress(StageCheckpoint $progress): array
    {
        return $this->stagePayload($progress->stage(), $this->codec($progress->stage())->encodeCheckpoint($progress));
    }

    private function decodeProgress(PayloadReader $reader): StageCheckpoint
    {
        [$stage, $payload] = $this->readStagePayload($reader);
        $progress = $this->codec($stage)->decodeCheckpoint($payload);
        if ($progress->stage() !== $stage) {
            throw new \InvalidArgumentException('Decoded checkpoint stage mismatch.');
        }

        return $progress;
    }

    /** @return array{stage: string, payload: \stdClass, schema_version: int} */
    private function encodeResult(ValidatedStageResult $result): array
    {
        return $this->stagePayload($result->stage(), $this->codec($result->stage())->encodeResult($result));
    }

    private function decodeResult(PayloadReader $reader): ValidatedStageResult
    {
        [$stage, $payload] = $this->readStagePayload($reader);
        $result = $this->codec($stage)->decodeResult($payload);
        if ($result->stage() !== $stage) {
            throw new \InvalidArgumentException('Decoded result stage mismatch.');
        }

        return $result;
    }

    /** @return array{StageCode, VersionedPayload} */
    private function readStagePayload(PayloadReader $reader): array
    {
        $reader->keys(['stage', 'payload', 'schema_version']);

        return [$this->stage($reader->string('stage')), new VersionedPayload(json_encode($reader->object('payload')->object, JSON_THROW_ON_ERROR), $reader->integer('schema_version'))];
    }

    private function retryAt(string $instant): RetryAt
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $instant, new \DateTimeZone('UTC'));
        if ($value === false || $value->format('Y-m-d\TH:i:s.u\Z') !== $instant) {
            throw new \InvalidArgumentException('Retry instant must be a valid canonical UTC timestamp.');
        }

        return new RetryAt($value);
    }

    /** @return array{disk: string, key: string, sha256: string} */
    private function artifact(ArtifactRef $artifact): array
    {
        return ['disk' => $artifact->disk, 'key' => $artifact->key, 'sha256' => $artifact->sha256->hex];
    }

    private function decodeArtifact(PayloadReader $reader): ArtifactRef
    {
        $reader->keys(['disk', 'key', 'sha256']);

        return new ArtifactRef($reader->string('disk'), $reader->string('key'), new ContentHash($reader->string('sha256')));
    }

    /** @return array<string, mixed> */
    private function encodeRecovery(NoProviderRecovery|ProviderRecoveryPlan $plan): array
    {
        if ($plan instanceof NoProviderRecovery) {
            return ['variant' => 'none'];
        }
        $receipt = $plan->receipt;

        return [
            'variant' => 'provider', 'mode' => $plan->mode->value,
            'logical_request_id' => $plan->logicalRequestId->value, 'physical_call_id' => $plan->physicalCallId->value,
            'purpose' => $plan->purpose->value,
            'receipt' => $receipt === null ? null : ['id' => $receipt->id->value, 'disk' => $receipt->disk, 'key' => $receipt->key, 'sha256' => $receipt->sha256->hex, 'schema_version' => $receipt->schemaVersion],
            'expected_input_hash' => $plan->expectedInputHash->hex, 'recipe_hash' => $plan->recipeHash->hex,
            'request_epoch' => $plan->requestEpoch, 'plan_version' => $plan->planVersion,
        ];
    }

    private function decodeRecovery(PayloadReader $reader): NoProviderRecovery|ProviderRecoveryPlan
    {
        $variant = $reader->string('variant');
        if ($variant === 'none') {
            $reader->keys(['variant']);

            return new NoProviderRecovery;
        }
        if ($variant !== 'provider') {
            throw new \InvalidArgumentException('Unknown provider recovery variant.');
        }
        $reader->keys(['variant', 'mode', 'logical_request_id', 'physical_call_id', 'purpose', 'receipt', 'expected_input_hash', 'recipe_hash', 'request_epoch', 'plan_version']);
        $receipt = null;
        if (! $reader->isNull('receipt')) {
            $ref = $reader->object('receipt');
            $ref->keys(['id', 'disk', 'key', 'sha256', 'schema_version']);
            $receipt = new ReceiptRef(new ProcessUuid($ref->string('id')), $ref->string('disk'), $ref->string('key'), new ContentHash($ref->string('sha256')), $ref->integer('schema_version'));
        }

        return new ProviderRecoveryPlan(
            mode: ProviderRecoveryMode::tryFrom($reader->string('mode')) ?? throw new \InvalidArgumentException('Unknown provider recovery mode.'),
            logicalRequestId: $this->uuid($reader, 'logical_request_id'),
            physicalCallId: $this->uuid($reader, 'physical_call_id'),
            purpose: ProviderOperationPurpose::tryFrom($reader->string('purpose')) ?? throw new \InvalidArgumentException('Unknown provider purpose.'),
            receipt: $receipt,
            expectedInputHash: $this->hash($reader, 'expected_input_hash'),
            recipeHash: $this->hash($reader, 'recipe_hash'),
            requestEpoch: $reader->integer('request_epoch'),
            planVersion: $reader->integer('plan_version'),
        );
    }
}
