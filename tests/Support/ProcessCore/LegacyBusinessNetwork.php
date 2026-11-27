<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Network\AdapterValidation\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidatedInput;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationCall;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Http\HttpRequestPayload;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Authority\ScopeDefinition;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Governance\ScopeKind;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Process\Authority\ControlRepository;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Network\ResponseDecoder;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Proxyma\Catalog\ProfileBindings;
use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\Http\TransportFixtures;

/**
 * Opt-in logical-call double for legacy BUSINESS tests. It owns no transport retry, permits,
 * route selection or health; those are proved by real-wire core suites. Laravel's stray-request
 * guard remains enabled. The old Http::fake fixtures are only business response inputs here.
 */
final class LegacyBusinessNetwork implements LegacyOutbound
{
    public static function install(bool $fakeResponses = true): void
    {
        app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->ensureScopes([
            new ScopeDefinition(ScopeKind::System, 'system', null),
        ]));
        if ($fakeResponses) {
            app()->instance(LegacyOutbound::class, new self);
        }
        Platform::created(static function (Platform $platform): void {
            self::catalog($platform);
        });
        foreach (Platform::query()->where('is_active', true)->get() as $platform) {
            self::catalog($platform);
        }
    }

    public static function catalog(Platform $platform): void
    {
        HistoricalNetworkFixtures::synchronize();
        if (! $platform->is_active) {
            return;
        }
        $origin = CanonicalOrigin::fromUrl('https://example.test');
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($platform->code, [
            new SourceTargetDefinition('business.listing', 'listing', $origin, [NetworkFixtures::profile()]),
            new SourceTargetDefinition('business.detail', 'detail', $origin, [NetworkFixtures::profile()]),
        ]));
        HistoricalNetworkFixtures::synchronize();
    }

    public static function pausePlatform(int $platformId): void
    {
        $code = Platform::query()->findOrFail($platformId)->code;
        $repository = app(ControlRepository::class);
        $row = DB::table('prc_control_scopes')->where('scope_key', 'acquisition:platform:'.$code)->sole();
        app(ProcessTransaction::class)->run(fn () => $repository->setMode(
            'acquisition:platform:'.$code, ControlMode::Paused, null, 'Fixture pause',
            $row->control_version, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
    }

    /**
     * @template T
     *
     * @param  Closure(OutboundExecutionContext): AdapterInspection<T>  $inspect
     * @return AdapterValidatedInput<T>
     */
    public function validated(AdapterValidationCall $call, Closure $inspect, ?Closure $checkpoint = null): AdapterValidatedInput
    {
        $checkpoint?->__invoke();
        $context = new OutboundExecutionContext(
            TransportFixtures::context()->lease(), static fn ($handle) => $handle,
            $checkpoint, $call->deadlineSeconds, 15);
        $inspection = $inspect($context);

        return new AdapterValidatedInput($inspection->value, null);
    }

    public function request(string $callerCode, ?int $platformId, ?ProviderKey $providerKey, string $capability,
        SafeUrl $url, TrafficClass $trafficClass, ProfileBindings $routePolicy, array $controls, int $deadlineSeconds,
        string $method = 'GET', HttpRequestPayload $payload = new HttpRequestPayload,
        IdempotencyClass $idempotency = IdempotencyClass::ReadOnly, ?Closure $checkpoint = null, ?int $sendLimit = null, ?OutboundExecutionContext $context = null): Response
    {
        $checkpoint?->__invoke();
        $client = Http::withHeaders($payload->headers)->withoutRedirecting();
        $options = $payload->body === null ? [] : ['body' => $payload->body];
        $response = $client->send($method, $url->reveal(), $options);
        if ($capability === 'listing') {
            $profile = RequestProfile::fromArray(array_replace(NetworkFixtures::profile()->toArray(), ['charset_default' => 'Windows-1251']));
            $utf8 = app(ResponseDecoder::class)->decode($response->body(), $response->header('Content-Type'), $profile)->utf8;

            return new Response(new PsrResponse($response->status(), $response->headers(), $utf8));
        }

        return $response;
    }
}
