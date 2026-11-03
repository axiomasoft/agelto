<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidatedInput;
use App\Dto\Procurement\Network\AdapterValidationCall;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Services\Procurement\Network\LegacyOutboundClient;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

/** A declared synthetic single-response parser contract, with real HTTP and canonical authority. */
final class AdapterFixtures
{
    public readonly WireFixture $wire;

    public readonly PermitFixtures $network;

    public readonly AdapterContractRef $contract;

    public readonly LegacyOutboundClient $client;

    public readonly RoutePolicy $routes;

    public function __construct()
    {
        PermitFixtures::clean();
        $this->wire = new WireFixture;
        $this->network = new PermitFixtures($this->wire);
        $this->contract = new AdapterContractRef($this->network->code, 'detail', '1', '1');
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($this->network->code, [
            new SourceTargetDefinition('card', 'detail', $this->network->url->origin, [$this->network->profile], adapterContract: $this->contract),
        ]));
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 1000]);
        $this->routes = new RoutePolicy([new HttpRouteBinding($this->network->route->routeId)]);
        $this->client = app()->makeWith(LegacyOutboundClient::class, ['urls' => $this->wire->policy()]);
    }

    public function call(string $input): AdapterValidationCall
    {
        return new AdapterValidationCall('fixture.contract', DB::table('prc_platforms')->where('code', $this->network->code)->sole()->id,
            $this->contract, LedgerFixtures::hash($input), $this->wire->url('/validate'), TrafficClass::Diagnostic,
            $this->routes, ['system', 'acquisition:platform:'.$this->network->code], 120);
    }

    /** @return AdapterValidatedInput<string> */
    public function validate(string $input, AdapterVerdict $verdict = AdapterVerdict::Accepted, bool $knownDefect = false): AdapterValidatedInput
    {
        $call = $this->call($input);

        return $this->client->validated($call, function (OutboundExecutionContext $context) use ($call, $knownDefect, $verdict): AdapterInspection {
            $path = '/validate/'.$context->lease()->id->value;
            $this->wire->respond($path, $verdict->value, headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
            $response = $this->client->request($call->callerCode, $call->platformId, null, 'detail', $this->wire->url($path),
                TrafficClass::Detail, $this->routes, $call->controls, 120, context: $context);
            if (! $response->successful()) {
                throw OutboundUnavailable::dependency();
            }
            // This fixture's entire schema is exactly one of the four declared UTF-8 tokens.
            $actual = AdapterVerdict::from($response->body());

            return new AdapterInspection('mapped', $actual, 'fixture.contract',
                $actual === AdapterVerdict::ContractFailure ? LedgerFixtures::hash('fixture.schema') : null,
                $knownDefect && $actual === AdapterVerdict::ContractFailure);
        });
    }

    public function networkFailure(string $input): void
    {
        $call = $this->call($input);
        $this->client->validated($call, function (OutboundExecutionContext $context) use ($call): AdapterInspection {
            $path = '/validate/'.$context->lease()->id->value;
            $this->wire->respond($path, 'unavailable', 503);
            $this->client->request($call->callerCode, $call->platformId, null, 'detail', $this->wire->url($path),
                TrafficClass::Detail, $this->routes, $call->controls, 120, context: $context);
            throw new \LogicException('503 cannot reach pure validation.');
        });
    }

    public function close(): void
    {
        $this->wire->stop();
        PermitFixtures::clean();
    }
}
