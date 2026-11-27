<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Caller;

use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Http\HttpRequestPayload;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportOutcome;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network as E;
use App\Services\Procurement\Network\LegacyBridge;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;
use Illuminate\Support\Facades\DB;

/** Notification effect имеет собственного durable owner и не присваивает LLM financial authority. */
final readonly class TelegramOutbound
{
    public function __construct(private ?UrlPolicy $urls = null) {}

    public function send(SafeUrl $url, HttpRequestPayload $payload): bool
    {
        $source = DB::table('prc_source_targets')->where('provider_key', 'telegram')->where('capability', 'notification')
            ->where('canonical_origin', $url->origin->value())->whereNull('retired_at')->sole();
        if (! is_string($source->endpoint_policy) || ! is_string($source->id)) {
            throw new \LogicException('Invalid notification target projection.');
        }
        $sourceId = $source->id;
        $endpoint = json_decode($source->endpoint_policy, true, flags: JSON_THROW_ON_ERROR);
        $profiles = is_array($endpoint) ? ($endpoint['profiles'] ?? null) : null;
        if (! is_array($profiles) || ! isset($profiles[0]) || ! is_array($profiles[0])) {
            throw new \DomainException('Missing notification endpoint profile.');
        }
        $profile = RequestProfile::fromArray($profiles[0]);
        $routes = app(ProfileResolver::class)->profile();
        $result = app(LegacyBridge::class)->run('notification.telegram', null, ['system', 'monitoring', 'provider:telegram'], 60,
            ContentHash::of(json_encode($profile->toArray(), JSON_THROW_ON_ERROR)),
            function ($context) use ($sourceId, $profile, $url, $payload, $routes): TransportOutcome {
                $plan = new OutboundRequestPlan(ProcessUuid::random(), new ProcessUuid($sourceId), 'POST', $url,
                    $profile, E\TrafficClass::Diagnostic, E\IdempotencyClass::AmbiguousWrite,
                    ContentHash::of($payload->body ?? ''), 1);

                return app(CallerOutbound::class)->execute($plan, $context,
                    app()->makeWith(CallerGate::class, ['routes' => app(ProfileResolver::class)->outboundRoutes($routes)]),
                    $this->urls ?? new UrlPolicy([$url->origin]), new TransferBudget($profile->maxEntityBytes, $profile->maxEntityBytes), $payload);
            }, providerKey: new ProviderKey('telegram'), kind: E\OutboundOperationKind::Diagnostic);

        return $result instanceof TransportSucceeded && $result->response->status >= 200 && $result->response->status < 300;
    }
}
