<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Procurement\Ai\ChunkReference;
use App\Dto\Procurement\Ai\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\ExtractionCompleted;
use App\Dto\Procurement\Ai\ExtractionProgress;
use App\Dto\Procurement\Ai\ValidatedChunk;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;

/** One bounded subrange per delivery; only WorkFinalizer accepts chunks or changes state. */
final readonly class ExtractionHandler implements StageHandler
{
    public function __construct(private AnalysisRepository $analyses, private AnalysisGeneration $generation,
        private StructuredTaskValidator $validator, private ProcessClock $clock, private ValidatedOutputStore $outputs,
        private StructuredCallExecutor $executor, private AnalysisAssetRecovery $assetRecovery, private AnalysisSendPolicy $sendPolicy) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof ExtractionWindowInput) {
            throw new \InvalidArgumentException('Extraction window input required.');
        }
        try {
            $window = $this->analyses->windowFor($input);
            $snapshot = $window->run->recipe->tasks->task(TaskPurpose::Rows);
        } catch (\InvalidArgumentException) {
            return new StageBlocked(BlockedReason::InvalidRecipeConfiguration);
        }
        $progress = $execution->lease()->checkpoint->stageProgress ?? new ExtractionProgress;
        if (! $progress instanceof ExtractionProgress) {
            throw new \InvalidArgumentException('Unknown extraction progress codec.');
        }
        if ($progress->blocked !== null) {
            return new StageBlocked($progress->blocked);
        }
        $checkpoint = $progress->checkpoint ?? new ExtractionCheckpoint([$window->range], []);
        if ($checkpoint->pending === []) {
            return new StageSucceeded(new ExtractionCompleted($checkpoint));
        }
        $range = $checkpoint->pending[0];
        $key = $window->subrangeKey($range)->hex;
        $ordinal = $progress->reasks[$key] ?? 0;
        $task = WindowExtractionTask::for($window, $range);
        if ($task->schema->hash !== $snapshot->schema->hash) {
            return new StageBlocked(BlockedReason::InvalidRecipeConfiguration);
        }
        try {
            if (($asset = $this->assetRecovery->inspect($window->run, $execution)) !== null) {
                return new StagePartial($progress->withRecoveredAsset($asset), new MissingParts(['next_declared_provider_operation']));
            }
            $receipt = $this->generation->receipt($window, $range, $ordinal, $task, $execution);
        } catch (AnalysisSendDenied $denied) {
            return $this->sendPolicy->outcome($denied, $progress);
        } catch (OperationDenied $denied) {
            return match ($denied->getMessage()) {
                'provider_budget' => new StageDeferred(DeferralReason::ProviderBudget, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds'))),
                'no_route' => new StageDeferred(DeferralReason::NoRoute, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds'))),
                'provider_send_not_sent' => new StageDeferred(DeferralReason::ProviderUnavailable, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds'))),
                'provider_unavailable' => new StageDeferred(DeferralReason::ProviderUnavailable, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds'))),
                'slice_exhausted' => new StagePartial($progress, new MissingParts(['slice_exhausted'])),
                'paused' => new StageBlocked(BlockedReason::Paused),
                'handler_hold' => new StageBlocked(BlockedReason::HandlerHold),
                'provider_error_review', 'physical_attempt_budget_exhausted', 'account_unknown_hold', 'account_exposure_hold' => new StageBlocked(BlockedReason::PolicyReview),
                'provider_result_unknown' => new StageBlocked(BlockedReason::ProviderResultUnknown),
                default => new StageBlocked(BlockedReason::InvalidRecipeConfiguration),
            };
        } catch (\InvalidArgumentException) {
            return new StageBlocked(BlockedReason::InvalidRecipeConfiguration);
        }
        try {
            $response = $this->executor->decodeGeneration($receipt);
            $result = $this->validator->validate($task, $response->modelJson, $response->finishReason, unexpectedContent: $response->unexpectedContent);
        } catch (\JsonException) {
            $result = new StructuredResult(StructuredDisposition::Unsupported);
        }
        $pending = $checkpoint->pending;
        $accepted = $checkpoint->acceptedChunks;
        $splits = $checkpoint->splitCount;
        $depths = $progress->depths;
        $reasks = $progress->reasks;
        $blocked = null;
        $chunk = null;
        switch ($result->disposition) {
            case StructuredDisposition::Accepted:
                $bytes = CanonicalJson::encode($result->data?->toArray());
                $id = ListingStoreRepository::id('analysis-chunk:'.$window->run->id->value.':'.$window->windowKey.':'.$window->run->recipe->hash->hex.':'.$key.':0');
                $artifact = $this->outputs->put($bytes, $execution);
                $chunk = new ValidatedChunk($id, $window, $range, 0, $artifact, $result);
                $accepted[] = new ChunkReference($id, $chunk->outputHash);
                array_shift($pending);
                break;
            case StructuredDisposition::SplitRequired:
                $policy = $window->run->recipe->coverage;
                $depth = $depths[$key] ?? 0;
                if ($range->end - $range->start < 2 * $policy->minSpan || $splits >= $policy->maxSplits || $depth >= $policy->maxDepth) {
                    $blocked = BlockedReason::ExtractionIncomplete;
                    break;
                }
                [$left, $right] = $range->split();
                array_shift($pending);
                $pending = [$left, $right, ...$pending];
                $depths[$window->subrangeKey($left)->hex] = $depth + 1;
                $depths[$window->subrangeKey($right)->hex] = $depth + 1;
                $splits++;
                break;
            case StructuredDisposition::InvalidSchema:
            case StructuredDisposition::InvalidSemantics:
                if ($ordinal === 0) {
                    $reasks[$key] = 1;
                } else {
                    $blocked = $result->disposition === StructuredDisposition::InvalidSchema ? BlockedReason::AiResponseInvalid : BlockedReason::SemanticReviewRequired;
                }
                break;
            case StructuredDisposition::Refusal:
                $blocked = BlockedReason::ModelRefusal;
                break;
            case StructuredDisposition::Incomplete:
                $blocked = BlockedReason::ModelResponseIncomplete;
                break;
            case StructuredDisposition::Unsupported:
                $blocked = BlockedReason::UnsupportedProviderOutcome;
                break;
        }

        return new StagePartial(new ExtractionProgress(new ExtractionCheckpoint($pending, $accepted, $splits), $depths, $reasks, $blocked,
            $chunk, new ProcessUuid($receipt->call->authorization->physicalCallId), $receipt, $range), new MissingParts(['remaining_window_work']));
    }
}
