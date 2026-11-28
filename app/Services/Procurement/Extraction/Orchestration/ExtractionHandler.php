<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Orchestration;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Procurement\Ai\Extraction\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\Extraction\ExtractionCompleted;
use App\Dto\Procurement\Ai\Extraction\ExtractionProgress;
use App\Dto\Procurement\Ai\Extraction\ValidatedChunk;
use App\Dto\Procurement\Ai\Planning\ChunkReference;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\ExtractionWindowInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\MissingParts;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\Pipeline\ValidatedOutputStore;
use App\Services\Procurement\Extraction\Pipeline\WindowExtractionTask;
use App\Services\Procurement\Extraction\Provider\AnalysisAssetRecovery;
use App\Services\Procurement\Extraction\Provider\AnalysisSendPolicy;
use App\Services\Procurement\Process\Contracts\StageHandler;

/** One bounded subrange per delivery; only WorkFinalizer accepts chunks or changes state. */
final readonly class ExtractionHandler implements StageHandler
{
    public function __construct(private AnalysisRepository $analyses, private AnalysisGeneration $generation,
        private StructuredTaskValidator $validator, private ValidatedOutputStore $outputs,
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
            return $this->sendPolicy->deniedOutcome($denied, $progress);
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
        $chunk = null;
        $blocked = match ($result->disposition) {
            StructuredDisposition::Accepted => (function () use ($result, $window, $key, $execution, &$accepted, &$pending, &$chunk): null {
                $bytes = CanonicalJson::encode($result->data?->toArray());
                $id = ProcessUuid::derived('analysis-chunk:'.$window->run->id->value.':'.$window->windowKey.':'.$window->run->recipe->hash->hex.':'.$key.':0');
                $artifact = $this->outputs->put($bytes, $execution);
                $chunk = new ValidatedChunk($id, $window, $range = $pending[0], 0, $artifact, $result);
                $accepted[] = new ChunkReference($id, $chunk->outputHash);
                array_shift($pending);

                return null;
            })(),
            StructuredDisposition::SplitRequired => (function () use ($window, $range, $key, &$depths, &$pending, &$splits): ?BlockedReason {
                $policy = $window->run->recipe->coverage;
                $depth = $depths[$key] ?? 0;
                if ($range->end - $range->start < 2 * $policy->minSpan || $splits >= $policy->maxSplits || $depth >= $policy->maxDepth) {
                    return BlockedReason::ExtractionIncomplete;
                }
                [$left, $right] = $range->split();
                array_shift($pending);
                $pending = [$left, $right, ...$pending];
                $depths[$window->subrangeKey($left)->hex] = $depth + 1;
                $depths[$window->subrangeKey($right)->hex] = $depth + 1;
                $splits++;

                return null;
            })(),
            StructuredDisposition::InvalidSchema, StructuredDisposition::InvalidSemantics => $ordinal === 0
                ? (static function () use (&$reasks, $key): null {
                    $reasks[$key] = 1;

                    return null;
                })()
                : ($result->disposition === StructuredDisposition::InvalidSchema ? BlockedReason::AiResponseInvalid : BlockedReason::SemanticReviewRequired),
            StructuredDisposition::Refusal => BlockedReason::ModelRefusal,
            StructuredDisposition::Incomplete => BlockedReason::ModelResponseIncomplete,
            StructuredDisposition::Unsupported => BlockedReason::UnsupportedProviderOutcome,
        };

        return new StagePartial(new ExtractionProgress(new ExtractionCheckpoint($pending, $accepted, $splits), $depths, $reasks, $blocked,
            $chunk, new ProcessUuid($receipt->call->authorization->physicalCallId), $receipt, $range), new MissingParts(['remaining_window_work']));
    }
}
