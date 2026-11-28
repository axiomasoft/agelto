<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Transport;

use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Services\Procurement\Proxyma\Caller\RuntimeCaller;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\TransportFailure;

/** Prepared provider transport повторно получает read grant только на свой live owner. */
final readonly class RuntimePhysicalTransport implements PhysicalTransport
{
    public function __construct(private PhysicalTransport $transport, private RuntimeCaller $runtime,
        private OutboundRequestPlan $plan, private ExecutionContext $context, private bool $allowPausedOwner = false) {}

    public function sendOnce(AuthorizedWireRequest $request): AttemptResult
    {
        try {
            return $this->runtime->run($this->plan, $this->context, fn (): AttemptResult => $this->transport->sendOnce($request), $this->allowPausedOwner);
        } catch (AdmissionRejected) {
            return AttemptResult::failed(TransportFailure::Cancelled);
        }
    }
}
