<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Orchestration;

use App\Dto\Procurement\Acquisition\Fetch\FetchItemRejected;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Http\DecodedTextBody;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportOutcome;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Platform\Diagnostics\ResponseAssessment;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Enums\Procurement\Network\TrafficClass;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Proxyma\Caller\CallerGate;
use App\Services\Procurement\Proxyma\Caller\CallerOutbound;

/** Normal physical attempts belong to Process; only an explicit canary has a probe owner. */
final class AcquisitionPlatformOutbound implements PlatformOutbound
{
    /** @var array<string,TransportSucceeded> Bounded by this owner's TransferBudget. */
    private array $responses = [];

    public function __construct(private CallerOutbound $executor, private CallerGate $gate,
        private UrlPolicy $urls, private TransferBudget $budget, private ExecutionContext|OutboundExecutionContext $work,
        private ExecutionContext|OutboundExecutionContext $owner, private AcquisitionArtifacts $artifacts) {}

    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): TransportOutcome
    {
        if ($context !== $this->work || $plan->adapterClaim !== null) {
            throw new \LogicException('Acquisition cannot replace its execution owner.');
        }
        $context->tick();
        $claim = $this->owner->adapterClaim() ?? throw new \LogicException('Missing adapter admission.');
        $traffic = $context instanceof OutboundExecutionContext
            ? TrafficClass::Diagnostic : $plan->trafficClass;
        $bound = new OutboundRequestPlan($plan->physicalRequestId, $plan->targetId, $plan->method, $plan->url,
            $plan->profile, $traffic, $plan->idempotency, $plan->bodyHash, $plan->sendLimit, $claim);
        $result = $this->executor->execute($bound, $this->owner, $this->gate, $this->urls, $this->budget);
        $this->artifacts->capture($result, $plan->trafficClass);
        if ($result instanceof TransportSucceeded) {
            $this->owner->recordSuccessfulRequest($result->physicalRequestId ?? throw new \LogicException('Missing physical receipt.'));
            if ($result->response->body instanceof DecodedTextBody) {
                $this->responses[hash('sha256', $result->response->body->utf8)] = $result;
            }
        }

        return $result;
    }

    public function rejection(FetchItemRejected $rejection): FetchItemRejected
    {
        if ($rejection->assessment->artifact !== null) {
            return $rejection;
        }
        $assessment = $rejection->assessment;
        $artifact = $rejection->inputEvidence === null
            ? $this->rejectedResponse($assessment)
            : $this->artifacts->rejectedInput($rejection->inputEvidence);

        return new FetchItemRejected($rejection->reason,
            new ResponseAssessment($assessment->completeness, $assessment->reason,
                $assessment->bodyHash, $assessment->confidence, $artifact, $assessment->failureScope));
    }

    private function rejectedResponse(ResponseAssessment $assessment): ArtifactRef
    {
        $response = $this->responses[$assessment->bodyHash->hex] ?? throw new \LogicException('Assessment lacks a physical response.');
        $body = $response->response->body;
        if (! $body instanceof DecodedTextBody) {
            throw new \LogicException('Item assessment requires bounded text.');
        }

        return $this->artifacts->rejected($response->physicalRequestId ?? throw new \LogicException('Missing physical identity.'), $body);
    }
}
