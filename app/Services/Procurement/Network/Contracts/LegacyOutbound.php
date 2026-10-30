<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidatedInput;
use App\Dto\Procurement\Network\AdapterValidationCall;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\SafeUrl;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Services\Procurement\Proxy\RoutePolicy;
use Closure;
use Illuminate\Http\Client\Response;

/** Temporary logical-call boundary for surviving business owners; no transport options escape it. */
interface LegacyOutbound
{
    /**
     * @template T
     *
     * @param  Closure(OutboundExecutionContext): AdapterInspection<T>  $inspect
     * @return AdapterValidatedInput<T>
     */
    public function validated(AdapterValidationCall $call, Closure $inspect, ?Closure $checkpoint = null): AdapterValidatedInput;

    /** @param list<string> $controls */
    public function request(string $callerCode, ?int $platformId, ?ProviderKey $providerKey, string $capability,
        SafeUrl $url, TrafficClass $trafficClass, RoutePolicy $routePolicy, array $controls, int $deadlineSeconds,
        string $method = 'GET', HttpRequestPayload $payload = new HttpRequestPayload,
        IdempotencyClass $idempotency = IdempotencyClass::ReadOnly, ?Closure $checkpoint = null, ?int $sendLimit = null, ?OutboundExecutionContext $context = null): Response;
}
