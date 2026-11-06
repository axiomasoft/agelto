<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\ExtractionCompleted;
use App\Dto\Procurement\Ai\ExtractionProgress;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Repositories\Procurement\Ai\ChunkRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Services\Procurement\Process\Contracts\StageResultRepository;

/** Invoked exclusively by the guarded WorkFinalizer/checkpoint transaction. */
final readonly class ChunkCommitter implements StageResultRepository
{
    public function __construct(private AnalysisRepository $analyses, private ChunkRepository $chunks,
        private AnalysisRequestRepository $requests, private CoverageValidator $coverage,
        private RequestRepository $ledger, private AnalysisAssetRecovery $assetRecovery) {}

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        CallerTransaction::require('Extraction chunk/progress publication');
        if (! $input instanceof ExtractionWindowInput || ! $progress instanceof ExtractionProgress) {
            throw new \InvalidArgumentException('Extraction progress binding mismatch.');
        }
        $window = $this->analyses->windowFor($input);
        if ($progress->recoveredAsset !== null) {
            if ($progress->checkpoint !== null) {
                $this->coverage->assertPartition($window, $progress->checkpoint);
            }
            $this->assetRecovery->storeLocked($input, $window->run, $progress->recoveredAsset);

            return;
        }
        if ($progress->checkpoint === null) {
            if ($progress->chunk !== null || $progress->physicalCallId !== null) {
                throw new \InvalidArgumentException('Uninitialized progress cannot consume output.');
            }

            return;
        }
        if ($progress->physicalCallId !== null) {
            $receipt = $progress->receipt ?? throw new \LogicException('Operation consumption requires verified receipt evidence.');
            if ($receipt->call->authorization->physicalCallId !== $progress->physicalCallId->value
                || $receipt->call->recipeHash !== $input->recipeHash->hex
                || $receipt->call->purpose !== OperationPurpose::Generate
                || $progress->consumedRange === null
                || $receipt->call->inputHash !== $window->subrangeInputHash($progress->consumedRange)->hex
                || $receipt->assessment->outcome !== ReceiptOutcome::Success) {
                throw new \LogicException('Receipt consumption identity/outcome mismatch.');
            }
            $this->ledger->acceptReceiptLocked($receipt);
        }
        if ($progress->chunk !== null) {
            if ($progress->chunk->window != $window) {
                throw new \InvalidArgumentException('Chunk belongs to another window.');
            }
            $this->chunks->storeLocked($progress->chunk);
        }
        $this->coverage->assertPartition($window, $progress->checkpoint);
        if ($progress->physicalCallId !== null) {
            $mapping = $this->requests->physicalForInput(
                physical: $progress->physicalCallId->value,
                run: $window->run,
                input: $input,
            );
            if ($mapping === null) {
                throw new \LogicException('Consumed operation belongs to another immutable input.');
            }
            $this->requests->consumeLocked(work: $mapping->workId, physical: $progress->physicalCallId->value);
        }
        $this->analyses->storeWindowProgressLocked(window: $window, checkpoint: $progress->checkpoint);
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Extraction completion');
        if (! $input instanceof ExtractionWindowInput || ! $result instanceof ExtractionCompleted) {
            throw new \InvalidArgumentException('Extraction result binding mismatch.');
        }
        $window = $this->analyses->windowFor($input);
        $this->coverage->assertPartition($window, $result->checkpoint);

        return $this->analyses->completeWindowLocked($window)
            ? [new PublicationInput(
                analysisRunId: $input->analysisRunId,
                recipeHash: $input->recipeHash,
                resultGeneration: $window->run->generation,
            )]
            : [];
    }
}
