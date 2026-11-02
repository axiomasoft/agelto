<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\ExtractionOutput;
use App\Dto\Procurement\Ai\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\PageExtractionOutput;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\SendClaimResult;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Procurement\Proxy\ProxyResolver;

/** One explicit operation per subrange/reask; recovery never enters the authorization branch. */
final readonly class AnalysisGeneration
{
    public function __construct(private AnalysisRequestRepository $mapping, private RequestRepository $requests,
        private ReceiptStore $receipts, private AnalysisCallCoordinator $coordinator, private StructuredCallExecutor $executor,
        private AnalysisProviderConnections $connections, private SourceCatalogRepository $targets, private ProxyResolver $routes, private AnalysisRepresentation $representation, private FinancialReservationRepository $finance) {}

    /** @param StructuredTask<ExtractionOutput|PageExtractionOutput> $task */
    public function receipt(AnalysisWindowSnapshot $window, RowRange|PageRange $range, int $ordinal, StructuredTask $task, ExecutionContext $context): VerifiedReceipt
    {
        return $this->operation($window->run, $window->subrangeInputHash($range), $ordinal, $task, $context,
            ['range' => $range->fields(), 'estimate_ordinal' => $window->estimateOrdinal, 'anchors' => $window->sourceAnchors,
                ...($window->range instanceof PageRange ? ['context_range' => $window->range->fields()] : [])]);
    }

    /** @param StructuredTask<ScreeningResult> $task */
    public function screeningReceipt(AnalysisRunSnapshot $run, int $ordinal, StructuredTask $task, ExecutionContext $context): VerifiedReceipt
    {
        return $this->operation($run, $run->screeningInputHash(), $ordinal, $task, $context, []);
    }

    /** @param StructuredTask<PageDiscoveryOutput> $task */
    public function discoveryReceipt(AnalysisRunSnapshot $run, PageRange $range, int $ordinal, StructuredTask $task, ExecutionContext $context): VerifiedReceipt
    {
        return $this->operation($run, $run->discoveryInputHash($range), $ordinal, $task, $context, ['range' => $range->fields()]);
    }

    /**
     * @template T of \Spatie\LaravelData\Data
     *
     * @param  StructuredTask<T>  $task
     * @param  array<string,mixed>  $fields
     */
    private function operation(AnalysisRunSnapshot $run, ContentHash $inputHash, int $ordinal, StructuredTask $task, ExecutionContext $context, array $fields): VerifiedReceipt
    {
        $lease = $context->lease();
        $pinned = $lease->checkpoint?->providerRecoveryPlan;
        $mapping = $this->mapping->active($lease->workId);
        if ($pinned instanceof ProviderRecoveryPlan && $pinned->mode !== ProviderRecoveryMode::ResumeFromReceipt
            && $pinned->mode !== ProviderRecoveryMode::KnownRetryEligible) {
            throw new OperationDenied('non_generation_recovery');
        }
        if ($mapping !== null) {
            $call = $this->requests->get($mapping->string('physical_call_id'));
            if ($mapping->string('analysis_run_id') !== $run->id->value || $mapping->string('input_hex') !== $lease->inputHash->hex
                || $call->recipeHash !== $run->recipe->hash->hex || $call->context != $run->recipe->llm
                || $call->inputHash !== $inputHash->hex || $call->purpose !== OperationPurpose::Generate) {
                throw new OperationDenied('provider_receipt_identity_drift');
            }
            $receipt = $this->receipts->inspect($call);
            if ($receipt !== null && $receipt->assessment->outcome === ReceiptOutcome::Success) {
                if ($pinned instanceof ProviderRecoveryPlan && ($pinned->physicalCallId->value !== $call->authorization->physicalCallId
                    || $pinned->logicalRequestId->value !== $call->authorization->logicalRequestId || $pinned->requestEpoch !== $call->authorization->requestEpoch
                    || $pinned->receipt?->sha256->hex !== $receipt->hash || $pinned->receipt->id->value !== $call->receiptId
                    || $pinned->receipt->disk !== $call->receiptDisk || $pinned->receipt->key !== $call->receiptUploadKey)) {
                    throw new OperationDenied('pinned_receipt_drift');
                }

                return $receipt;
            }
            if ($call->claimed && ! in_array($call->outcome, ['not_sent', 'known_nonbillable_cache_miss', 'known_retryable_nonbillable'], true)) {
                throw new OperationDenied('provider_result_unknown');
            }
        } elseif ($pinned instanceof ProviderRecoveryPlan) {
            throw new OperationDenied('pinned_mapping_missing');
        }
        $connection = $this->connections->for($run->recipe->llm);
        $text = $this->representation->text($run, $context);
        if ($run->source !== null) {
            $range = $task->purpose === TaskPurpose::Rows ? ($fields['context_range'] ?? $fields['range'] ?? null) : null;
            if ($range !== null && (! is_array($range) || ! is_int($range['start'] ?? null) || ! is_int($range['end'] ?? null))) {
                throw new OperationDenied('analysis_range_codec_drift');
            }
            $text = $this->representation->anchored($text, $range === null ? null : new PageRange($range['start'], $range['end']));
        }
        $user = CanonicalJson::encode([...$fields, 'semantic_reask_ordinal' => $ordinal, 'untrusted_document' => $text]);
        $plan = new ProviderOperationPlan(new RequestIdentity($run->recipe->hash->hex, $inputHash->hex,
            OperationPurpose::Generate, $ordinal, $run->id->value), $run->recipe->llm, $connection->pricing,
            $lease->workId->value->value, generation: new ProviderGenerationInput($task->prompt, $user, (new ProviderSchemaCompiler)->compile($task->schema), purpose: $task->purpose));
        $wire = $this->executor->compileGeneration($plan, $connection->origin);
        $target = $this->targets->targetForCall(null, $plan->context->provider, 'provider', $connection->origin)
            ?? throw new OperationDenied('provider_target_missing');
        $period = $connection->periodId !== '' ? $connection->periodId : $this->finance->currentPeriod($plan->context->account, $plan->pricing->currency);
        $operation = $this->coordinator->authorize($lease, $run->id, $plan, $period, $wire);
        $gate = app()->make(AnalysisProviderSendGate::class, ['context' => $context, 'runId' => $run->id,
            'target' => new ProcessUuid($target->string('id')), 'profile' => $connection->profile,
            'urlPolicy' => $connection->urlPolicy,
            'routes' => $this->routes->outboundRoutes($this->routes->profile(provider: true)), 'apiKey' => $connection->credential()]);
        $executionResult = $this->executor->execute($operation, $wire, $gate);
        $call = $this->requests->get($operation->physicalCallId);
        $receipt = $this->receipts->inspect($call);
        if ($receipt === null || $receipt->assessment->outcome !== ReceiptOutcome::Success) {
            if (in_array($call->outcome, ['not_sent', 'known_nonbillable_cache_miss', 'known_retryable_nonbillable'], true)) {
                $control = $this->coordinator->pinKnownRetry($lease, $operation);
                if ($control !== null) {
                    throw new OperationDenied($control);
                }
                if ($executionResult instanceof SendClaimResult) {
                    throw new AnalysisSendDenied($executionResult);
                }
                throw new OperationDenied('provider_send_not_sent');
            }
            if ($receipt?->assessment->outcome === ReceiptOutcome::BilledError) {
                throw new OperationDenied('provider_error_review');
            }
            throw new OperationDenied('provider_result_unknown');
        }

        return $receipt;
    }
}
