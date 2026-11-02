<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Enums\Llm\ProviderSendDenial;
use App\Repositories\Llm\Request\RequestRepository;
use Illuminate\Support\Facades\DB;

/** Financial/transport execution only; domain schema parsing/publication is a separate owner. */
final readonly class StructuredCallExecutor
{
    public function __construct(private RequestLedger $ledger, private RequestRepository $requests) {}

    public function execute(AuthorizedProviderOperation $operation, ProviderWireRequest $wire, ?ProviderSendGate $gate): ProviderTransportOutcome|SendClaimResult
    {
        if ($gate === null) {
            return new SendClaimResult(null, ProviderSendDenial::Unsupported);
        }
        $claim = $gate->claimSend($operation, $wire);
        if ($claim->transport === null) {
            return $claim;
        }
        $outcome = $claim->transport->sendOnce($wire);
        // Durable raw financial capture is independent of optional network observation.
        try {
            if ($outcome->status !== null && ($body = $outcome->body()) !== null) {
                $this->ledger->capture($this->requests->get($operation->physicalCallId), $outcome->status, $body);
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
