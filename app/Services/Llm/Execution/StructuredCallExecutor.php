<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\GenerationResponse;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ProviderSendDenial;
use App\Enums\Llm\ReceiptOutcome;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Cache\ProviderAssetManager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

/** Financial/transport execution only; domain schema parsing/publication is a separate owner. */
final readonly class StructuredCallExecutor
{
    public function __construct(
        private RequestLedger $ledger,
        private RequestRepository $requests,
        private ProviderAssetManager $assets,
        private ?ProviderCodecs $codecs = null,
    ) {}

    /** Pure compilation uses the same Llm-owned adapter as response decoding. */
    public function compileGeneration(ProviderOperationPlan $plan, string $origin): ProviderWireRequest
    {
        return $this->codecs()->for($plan->context->provider)->compileGeneration($plan, $origin);
    }

    /** The active structured transport's codec stays inside Llm; assets and errors never enter model validation. */
    public function decodeGeneration(VerifiedReceipt $receipt): GenerationResponse
    {
        if ($receipt->call->purpose !== OperationPurpose::Generate || $receipt->assessment->outcome !== ReceiptOutcome::Success) {
            throw new OperationDenied('successful_generation_receipt_required');
        }

        return $this->codecs()->for($receipt->call->context->provider)->decodeGeneration($receipt);
    }

    private function codecs(): ProviderCodecs
    {
        if ($this->codecs !== null) {
            return $this->codecs;
        }
        $container = Container::getInstance();

        return $container->bound(ProviderCodecs::class)
            ? $container->make(ProviderCodecs::class)
            : ProviderCodecs::default();
    }

    public function execute(AuthorizedProviderOperation $operation, ProviderWireRequest $wire, ?ProviderSendGate $gate): ProviderTransportOutcome|SendClaimResult
    {
        if ($gate === null) {
            return new SendClaimResult(null, ProviderSendDenial::Unsupported);
        }
        if ($wire->assetKey !== null && ! $this->requests->get($operation->physicalCallId)->claimed
            && in_array($wire->operation->identity->purpose, [OperationPurpose::FileUpload, OperationPurpose::CacheCreate, OperationPurpose::CacheRefresh], true)) {
            $this->assets->claim($wire->assetKey, $operation->physicalCallId, $wire);
        }
        $claim = $gate->claimSend($operation, $wire);
        if ($claim->transport === null) {
            return $claim;
        }
        try {
            $outcome = $claim->transport->sendOnce($wire);
        } catch (\Throwable $failure) {
            DB::transaction(fn () => $this->requests->interruptLocked($this->requests->get($operation->physicalCallId)));

            throw $failure;
        }
        // Durable raw financial capture is independent of optional network observation.
        try {
            if ($outcome->status !== null && ($body = $outcome->body()) !== null) {
                $receipt = $this->ledger->capture($this->requests->get($operation->physicalCallId), $outcome->status, $body);
                if ($wire->assetKey !== null && $receipt->assessment->outcome === ReceiptOutcome::Success) {
                    $this->assets->restore($wire->assetKey, $receipt);
                }
            } else {
                DB::transaction(fn () => $this->requests->interruptLocked($this->requests->get($operation->physicalCallId)));
            }
        } finally {
            try {
                $gate->recordTransportOutcome($operation->physicalCallId, $outcome);
            } catch (\Throwable) {
                // Never turn a recorded paid receipt into a resend because optional observation failed.
                // The joint physical ID remains durable for reconciliation.
            }
        }

        return $outcome;
    }
}
