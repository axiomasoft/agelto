<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\DiscoveryCheckpoint;
use App\Dto\Procurement\Ai\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\ScreeningProgress;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Validation\StructuredTaskValidator;

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
        $blocked = null;
        switch ($result->disposition) {
            case StructuredDisposition::Accepted:
                $data = $result->data ?? throw new \LogicException('Accepted discovery has no data.');
                foreach ($data->pages as $page) {
                    $assignments[$page->page_index] = $page->estimate_ordinal;
                }
                ksort($assignments, SORT_NUMERIC);
                array_shift($pending);
                break;
            case StructuredDisposition::SplitRequired:
                $depth = $depths[$key] ?? 0;
                $policy = $run->recipe->coverage;
                if ($range->end - $range->start < 2 * $policy->minSpan || $depth >= $policy->maxDepth || $splits >= $policy->maxSplits) {
                    $blocked = BlockedReason::ExtractionIncomplete;
                    break;
                }
                [$left, $right] = $range->split();
                array_shift($pending);
                $pending = [$left, $right, ...$pending];
                $depths[$run->discoveryInputHash($left)->hex] = $depth + 1;
                $depths[$run->discoveryInputHash($right)->hex] = $depth + 1;
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

        return new StagePartial(new ScreeningProgress($progress->reaskOrdinal, $progress->result, $blocked,
            new ProcessUuid($receipt->call->authorization->physicalCallId), $receipt,
            discovery: new DiscoveryCheckpoint($pending, $assignments, $depths, $reasks, $splits), consumedRange: $range), new MissingParts(['discovery']));
    }
}
