<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use Illuminate\Database\QueryException;

/** Constructed only by owning composition; no legacy activation or retry loop here. */
final readonly class ScheduledAttemptGate implements OutboundAttemptGate
{
    public function __construct(private NetworkPermitRepository $permits, private OutboundRouteSelector $routes, private OutboundPolicy $policy) {}

    public function authorize(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, RetryRoutePreference $routes): AuthorizedHttpRequest|TransportDeferred|TransportBlocked
    {
        $ticket = null;
        $waitUntil = hrtime(true) + $this->policy->maxInlineWaitSeconds * 1_000_000_000;
        try {
            $route = $this->routes->select($plan, $routes);
            if ($route instanceof TransportDeferred || $route instanceof TransportBlocked) {
                return $route;
            }
            $registered = $this->permits->register($plan, $context, $route->selection);
            if (! $registered instanceof NetworkDemandTicket) {
                return $registered;
            }
            $ticket = $registered;
            do {
                $context->tick();
                $permit = $this->permits->reserve($ticket, $context);
                if ($permit instanceof NetworkPermitWait) {
                    if (hrtime(true) >= $waitUntil) {
                        $this->permits->abandon($ticket, $context);

                        return new TransportDeferred($permit->reason, $permit->retryAt);
                    }
                    $this->wait($permit, $context);
                }
            } while ($permit instanceof NetworkPermitWait);
            if (! $permit instanceof ScheduledNetworkPermit) {
                return $permit;
            }
            do {
                $context->tick();
                $claim = $this->permits->claimSend($permit, $context);
                if ($claim instanceof NetworkPermitWait) {
                    if (hrtime(true) >= $waitUntil) {
                        $this->permits->abandon($ticket, $context);

                        return new TransportDeferred($claim->reason, $claim->retryAt);
                    }
                    $this->wait($claim, $context);
                }
            } while ($claim instanceof NetworkPermitWait);
            if ($claim instanceof TransportBlocked || $claim instanceof TransportDeferred) {
                return $claim;
            }

            return $claim->claimed ? new AuthorizedHttpRequest($plan, $route->binding) : new TransportBlocked(NetworkBlockedReason::OwnerLost);
        } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
            if ($ticket !== null) {
                $this->permits->abandon($ticket, $context);
            }
            throw $exit;
        } catch (QueryException $exception) {
            if ($ticket !== null) {
                try {
                    $this->permits->abandon($ticket, $context);
                } catch (\Throwable) {
                }
            }
            throw DatabaseFailureClassifier::isTransient($exception) ? new DependencyUnavailable('database', $exception) : $exception;
        }
    }

    public function record(AuthorizedHttpRequest $request, HttpAttemptResult $result): TransportDeferred|TransportBlocked|null
    {
        try {
            return $this->permits->record($request->plan->physicalRequestId, $result);
        } catch (QueryException $exception) {
            throw DatabaseFailureClassifier::isTransient($exception) ? new DependencyUnavailable('database', $exception) : $exception;
        }
    }

    public function cooldown(OutboundRequestPlan $plan, RetryAt $until): void
    {
        $this->permits->cooldown($plan, $until);
    }

    public function targetForRedirect(OutboundRequestPlan $from, SafeUrl $to): ProcessUuid|TransportBlocked
    {
        $target = LedgerRow::one('SELECT next.id FROM prc_source_targets next JOIN prc_source_targets source ON source.id = ?
            WHERE next.platform_id = source.platform_id AND next.capability = source.capability AND next.canonical_origin = ? AND next.retired_at IS NULL
            ORDER BY next.id LIMIT 1', [$from->targetId->value, $to->origin->value()]);

        return $target === null ? new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied) : new ProcessUuid($target->string('id'));
    }

    private function wait(NetworkPermitWait $wait, ExecutionContext|OutboundExecutionContext $context): void
    {
        // DB-time difference -> monotonic wait. Tick <=1s, and repoll claim after each chunk;
        // another owner may set a later cooldown while sleeping. No wall-clock authority.
        $delay = LedgerRow::one('SELECT greatest(0, ceil(extract(epoch FROM (?::timestamptz - clock_timestamp())) * 1000000))::bigint AS delay', [$wait->retryAt->value->format('Y-m-d H:i:s.uP')])?->int('delay') ?? 0;
        if ($delay > 0) {
            usleep(min(1_000_000, $delay));
        }
        $context->tick();
    }
}
