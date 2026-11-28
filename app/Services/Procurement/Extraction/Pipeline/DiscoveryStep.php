<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Pipeline;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Extraction\DiscoveryCheckpoint;
use App\Dto\Procurement\Ai\Output\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Screening\ScreeningProgress;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Primitives\MissingParts;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\Orchestration\AnalysisGeneration;
use App\Services\Procurement\Extraction\Provider\AnalysisAssetRecovery;
use App\Services\Procurement\Extraction\Provider\AnalysisSendPolicy;

/** A bounded part of the screening work, with its own pinned task/identity/reask budget. */
final readonly class DiscoveryStep
{
    public function __construct(private AnalysisGeneration $generation, private StructuredTaskValidator $validator,
        private StructuredCallExecutor $executor, private AnalysisAssetRecovery $assets,
        private AnalysisSendPolicy $sendPolicy) {}

    public function execute(AnalysisRunSnapshot $run, ScreeningProgress $progress, ExecutionContext $execution): StageOutcome
    {
        $source = $run->source ?? throw new \InvalidArgumentException('Independent source manifest required.');
        $checkpoint = $progress->discovery ?? new DiscoveryCheckpoint([new PageRange(0, $source->pageCount)]);
        $checkpoint->assertPartition($source);
        $range = $checkpoint->pending[0] ?? throw new \LogicException('Discovery already complete.');
        $key = $run->discoveryInputHash($range)->hex;
        $ordinal = $checkpoint->reasks[$key] ?? 0;
        try {
            $snapshot = $run->recipe->tasks->task(TaskPurpose::Discovery);
            $task = new StructuredTask(PageDiscoveryOutput::class, $snapshot->prompt, TaskPurpose::Discovery,
                new DiscoverySemanticValidator($range, $checkpoint->assignments));
            if ($task->schema->hash !== $snapshot->schema->hash) {
                return new StageBlocked(BlockedReason::InvalidRecipeConfiguration);
            }
            if (($asset = $this->assets->inspect($run, $execution)) !== null) {
                return new StagePartial($progress->withRecoveredAsset($asset), new MissingParts(['discovery']));
            }
            $receipt = $this->generation->discoveryReceipt($run, $range, $ordinal, $task, $execution);
        } catch (AnalysisSendDenied $denied) {
            return $this->sendPolicy->outcome($denied, $progress);
        } catch (OperationDenied $denied) {
            return $this->sendPolicy->deniedOutcome($denied, $progress, 'discovery');
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
        $assignments = $checkpoint->assignments;
        $depths = $checkpoint->depths;
        $reasks = $checkpoint->reasks;
        $splits = $checkpoint->splitCount;
        $blocked = match ($result->disposition) {
            StructuredDisposition::Accepted => (function () use ($result, &$assignments, &$pending): null {
                $data = $result->data ?? throw new \LogicException('Accepted discovery has no data.');
                foreach ($data->pages as $page) {
                    $assignments[$page->page_index] = $page->estimate_ordinal;
                }
                ksort($assignments, SORT_NUMERIC);
                array_shift($pending);

                return null;
            })(),
            StructuredDisposition::SplitRequired => (function () use ($run, $range, $key, &$depths, &$pending, &$splits): ?BlockedReason {
                $depth = $depths[$key] ?? 0;
                $policy = $run->recipe->coverage;
                if ($range->end - $range->start < 2 * $policy->minSpan || $depth >= $policy->maxDepth || $splits >= $policy->maxSplits) {
                    return BlockedReason::ExtractionIncomplete;
                }
                [$left, $right] = $range->split();
                array_shift($pending);
                $pending = [$left, $right, ...$pending];
                $depths[$run->discoveryInputHash($left)->hex] = $depth + 1;
                $depths[$run->discoveryInputHash($right)->hex] = $depth + 1;
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

        return new StagePartial(new ScreeningProgress($progress->reaskOrdinal, $progress->result, $blocked,
            new ProcessUuid($receipt->call->authorization->physicalCallId), $receipt,
            discovery: new DiscoveryCheckpoint($pending, $assignments, $depths, $reasks, $splits), consumedRange: $range), new MissingParts(['discovery']));
    }
}
