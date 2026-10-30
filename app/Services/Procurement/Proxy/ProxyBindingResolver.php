<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;

/** Existing acquisition profiles point at canonical network route profiles. No proxy pivot owner. */
final readonly class ProxyBindingResolver
{
    public function __construct(private ProxyResolver $routes) {}

    public function forCrawler(Crawler $crawler): RoutePolicy
    {
        return $this->routes->profile($crawler->route_profile_id === null ? null : new ProcessUuid($crawler->route_profile_id));
    }

    public function forProcurement(?int $procurementId): RoutePolicy
    {
        $crawlers = Crawler::query()
            ->join('prc_procurements', 'prc_procurements.registry_id', '=', 'prc_crawlers.registry_id')
            ->where('prc_procurements.id', $procurementId)
            ->where('prc_crawlers.is_active', true)->get(['prc_crawlers.*']);
        $profiles = $crawlers->pluck('route_profile_id')->unique()->values();
        if ($profiles->count() > 1) {
            throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
        }
        $id = $profiles->first();

        return $this->routes->profile(is_string($id) ? new ProcessUuid($id) : null);
    }
}
