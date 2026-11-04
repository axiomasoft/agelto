<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;

/**
 * Reserves capacity, waits, claims the fully fenced send and records factual receipt/release.
 * A grant is not an authorization; claimSend revalidates the complete current authority.
 * Each call obtains a NEW permit and request intent; all I/O occurs after its tx commits.
 */
interface OutboundAttemptGate
{
    public function authorize(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, RetryRoutePreference $routes): AuthorizedHttpRequest|TransportDeferred|TransportBlocked;

    /** Receipt/observation/release and any 429 decision commit atomically; return the durable quota decision. */
    public function record(AuthorizedHttpRequest $request, HttpAttemptResult $result): TransportDeferred|TransportBlocked|null;

    /** Cross-origin hops must use the catalog target/budget of their new origin. */
    public function targetForRedirect(OutboundRequestPlan $from, SafeUrl $to): ProcessUuid|TransportBlocked;

    /** Origin-wide cooldown, revalidated for already reserved permits as well. */
    public function cooldown(OutboundRequestPlan $plan, RetryAt $until): void;
}
