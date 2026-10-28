<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use Tests\Support\ProcessCore\LedgerFixtures;

/** Transport-only gate. Does not claim to prove the future DB permit/health protocol. */
final class FixtureAttemptGate implements OutboundAttemptGate
{
    /** @var list<AuthorizedHttpRequest> */
    public array $requests = [];

    /** @var list<HttpAttemptResult> */
    public array $results = [];

    public ?RetryAt $cooldown = null;

    public ?TransportBlocked $deny = null;

    public ?\Closure $afterRecord = null;

    /** @param list<HttpRouteBinding> $routes */
    public function __construct(public array $routes)
    {
        if (getenv('PROCESS_CORE_TEST') !== '1') {
            throw new \LogicException('Fixture gate is isolated-test-only.');
        }
    }

    public function authorize(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, RetryRoutePreference $routes): AuthorizedHttpRequest|TransportDeferred|TransportBlocked
    {
        $context->tick();
        if ($this->deny !== null) {
            return $this->deny;
        }
        $route = $this->routes[0];
        if ($routes->currentRoute !== null) {
            foreach ($this->routes as $candidate) {
                if ($candidate->id->value === $routes->currentRoute->value) {
                    $route = $candidate;
                }
            }
        }
        if ($routes->preferUntried) {
            foreach ($this->routes as $candidate) {
                if (! in_array($candidate->id->value, $routes->triedRouteIds, true)) {
                    $route = $candidate;
                    break;
                }
            }
        }
        $request = new AuthorizedHttpRequest($plan, $route);
        $this->requests[] = $request;

        return $request;
    }

    public function record(AuthorizedHttpRequest $request, HttpAttemptResult $result): void
    {
        $this->results[] = $result;
        $this->afterRecord?->__invoke($this);
    }

    public function targetForRedirect(OutboundRequestPlan $from, SafeUrl $to): ProcessUuid|TransportBlocked
    {
        return LedgerFixtures::uuid();
    }

    public function cooldown(OutboundRequestPlan $plan, RetryAt $until): void
    {
        $this->cooldown = $until;
    }
}
