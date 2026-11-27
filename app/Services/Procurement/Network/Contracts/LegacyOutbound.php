<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Network\AdapterValidation\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidatedInput;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationCall;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Http\HttpRequestPayload;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Services\Procurement\Proxyma\Catalog\ProfileBindings;
use Closure;
use Illuminate\Http\Client\Response;

/** Temporary logical-call boundary for surviving business owners; no transport options escape it. */
interface LegacyOutbound
{
    /**
     * @template T
     *
     * @param  Closure(OutboundExecutionContext): AdapterInspection<T>  $inspect
     * @param  (Closure(): void)|null  $checkpoint
     * @return AdapterValidatedInput<T>
     */
    public function validated(AdapterValidationCall $call, Closure $inspect, ?Closure $checkpoint = null): AdapterValidatedInput;

    /**
     * @param  list<string>  $controls
     * @param  (Closure(): void)|null  $checkpoint
     */
    public function request(string $callerCode, ?int $platformId, ?ProviderKey $providerKey, string $capability,
        SafeUrl $url, TrafficClass $trafficClass, ProfileBindings $routePolicy, array $controls, int $deadlineSeconds,
        string $method = 'GET', HttpRequestPayload $payload = new HttpRequestPayload,
        IdempotencyClass $idempotency = IdempotencyClass::ReadOnly, ?Closure $checkpoint = null, ?int $sendLimit = null, ?OutboundExecutionContext $context = null): Response;
}
