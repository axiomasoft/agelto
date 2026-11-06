<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Ai\ScreeningCompleted;
use App\Dto\Procurement\Ai\ScreeningProgress;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\AnalysisAssetRecovery;
use App\Services\Procurement\Extraction\AnalysisGeneration;
use App\Services\Procurement\Extraction\AnalysisRepresentation;
use App\Services\Procurement\Extraction\AnalysisSendPolicy;
use App\Services\Procurement\Extraction\DiscoveryStep;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;

/** One bounded operation; acceptance and every state change belong to WorkFinalizer. */
final readonly class ScreeningHandler implements StageHandler
{
    public function __construct(private AnalysisRepository $analyses, private AnalysisGeneration $generation,
        private ScreeningContract $contract, private StructuredTaskValidator $validator, private ProcessClock $clock,
        private StructuredCallExecutor $executor, private AnalysisAssetRecovery $assetRecovery, private AnalysisSendPolicy $sendPolicy, private DiscoveryStep $discovery, private ScreeningPolicy $policy, private AnalysisRepresentation $representation) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! $input instanceof ScreeningInput) {
            throw new \InvalidArgumentException('Screening input required.');
        }
        try {
            $run = $this->analyses->forScreening($input);
            $snapshot = $run->recipe->tasks->task(TaskPurpose::Screening);
        } catch (\InvalidArgumentException) {
            return new StageBlocked(BlockedReason::InvalidRecipeConfiguration);
        }
        if ($run->recipe->analyzerVersion === 'estimate-pages/1' && $run->source === null) {
            try {
                $execution->tick();
                $row = LedgerRow::one('SELECT coverage_manifest::text AS coverage,coverage_manifest_schema_version AS version FROM prc_document_representations WHERE id=?', [$run->representationId]);
                if ($row === null || $row->int('version') !== 1) {
                    return new StageBlocked(BlockedReason::ExtractionIncomplete);
                }
                $source = $this->representation->source($this->representation->text($run, $execution), $run->representationHash, $row->string('coverage'));

                return new StagePartial(new ScreeningProgress(preparedSource: $source), new MissingParts(['source_manifest_committed']));
            } catch (DependencyUnavailable) {
                return new StageDeferred(DeferralReason::StorageUnavailable, new RetryAt($this->clock->dbNow()->value->modify('+30 seconds')));
            } catch (\InvalidArgumentException|OperationDenied) {
                return new StageBlocked(BlockedReason::ExtractionIncomplete);
            }
        }
        $progress = $execution->lease()->checkpoint->stageProgress ?? new ScreeningProgress;
        if (! $progress instanceof ScreeningProgress) {
            throw new \InvalidArgumentException('Unknown screening progress codec.');
        }
        if ($progress->blocked !== null) {
            return new StageBlocked($progress->blocked);
        }
        if ($progress->result !== null) {
            if ($run->source !== null && $this->policy->decide($progress->result, $run->recipe->screening)->disposition === ScreeningDisposition::Extract
                && ($progress->discovery === null || $progress->discovery->pending !== [])) {
                return $this->discovery->execute($run, $progress, $execution);
            }

            return new StageSucceeded(new ScreeningCompleted($run->id, $execution->lease()->workId, $progress->result));
        }
        $task = $this->contract->task($snapshot->prompt);
        if ($task->schema->hash !== $snapshot->schema->hash) {
            return new StageBlocked(BlockedReason::InvalidRecipeConfiguration);
        }
        try {
            if (($asset = $this->assetRecovery->inspect($run, $execution)) !== null) {
                return new StagePartial($progress->withRecoveredAsset($asset), new MissingParts(['next_declared_provider_operation']));
            }
            $receipt = $this->generation->screeningReceipt($run, $progress->reaskOrdinal, $task, $execution);
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
        $ordinal = $progress->reaskOrdinal;
        $blocked = match ($result->disposition) {
            StructuredDisposition::Accepted => null,
            StructuredDisposition::InvalidSchema => $ordinal === 0 ? null : BlockedReason::AiResponseInvalid,
            StructuredDisposition::InvalidSemantics => $ordinal === 0 ? null : BlockedReason::SemanticReviewRequired,
            StructuredDisposition::Refusal => BlockedReason::ModelRefusal,
            StructuredDisposition::Incomplete => BlockedReason::ModelResponseIncomplete,
            StructuredDisposition::Unsupported => BlockedReason::UnsupportedProviderOutcome,
            StructuredDisposition::SplitRequired => BlockedReason::ModelResponseIncomplete,
        };
        $reask = in_array($result->disposition, [StructuredDisposition::InvalidSchema, StructuredDisposition::InvalidSemantics], true) && $ordinal === 0;
        $screening = $result->data instanceof ScreeningResult ? $result->data : null;

        return new StagePartial(new ScreeningProgress($reask ? 1 : $ordinal, $screening, $blocked,
            new ProcessUuid($receipt->call->authorization->physicalCallId), $receipt), new MissingParts(['screening_finalization']));
    }
}
