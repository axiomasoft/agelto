<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\ValidatedAcquisitionResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\Contracts\StagePublicationPreparation;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use App\Services\Procurement\Process\ProcessPayloadCodec;

/** A receipt epoch is checked in the same fenced transaction as canonical publication. */
final readonly class ValidatedAcquisitionFinalizer implements StagePublicationPreparation, StagePublicationRepository
{
    public function __construct(private StageResultRepository $inner, private AdapterHealthRepository $adapters) {}

    public function preparePublication(ValidatedStageResult $result): void
    {
        if ($this->inner instanceof StagePublicationPreparation) {
            $this->inner->preparePublication($this->validated($result)->result);
        }
    }

    public function publicationLocks(WorkInput $input, ValidatedStageResult $result): PublicationLocks
    {
        $result = $this->validated($result);

        return $this->inner instanceof StagePublicationRepository
            ? $this->inner->publicationLocks($input, $result->result)
            : new class implements PublicationLocks
            {
                public function lockIdentities(): void {}

                public function lockSubjects(): void {}
            };
    }

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        $this->inner->storeProgress($input, $progress);
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        $result = $this->validated($result);
        $payload = (new ProcessPayloadCodec)->encodeInput($input);
        if ($payload->json === null || WorkStoreRepository::inputHash($payload->json)->hex !== $result->receipt->inputHash->hex) {
            throw new \InvalidArgumentException('Validation belongs to another acquisition input.');
        }
        try {
            $this->adapters->assertReceipt($result->receipt);
        } catch (OutboundUnavailable $unavailable) {
            throw new PublicationBlocked(BlockedReason::HandlerHold, $unavailable);
        }

        return $this->inner->storeResult($input, $result->result);
    }

    public function afterSuccessors(WorkInput $input, WorkId $workId, ProcessUuid $eventId, ProcessUuid $projectionId): void
    {
        if ($this->inner instanceof StagePublicationRepository) {
            $this->inner->afterSuccessors($input, $workId, $eventId, $projectionId);
        }
    }

    private function validated(ValidatedStageResult $result): ValidatedAcquisitionResult
    {
        return $result instanceof ValidatedAcquisitionResult ? $result : throw new \InvalidArgumentException('Missing acquisition validation.');
    }
}
