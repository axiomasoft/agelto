<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Repositories\Procurement\Process\Authority\ControlRepository;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;

/** Safe declared probes only; never selects or retries domain work. One physical probe per tick. */
final class NetworkProbeReconcileCategory implements ReconcileCategory
{
    private ?string $cursor = null;

    public function __construct(private readonly SourceCatalogRepository $catalog,
        private readonly LegacyOutboundClient $outbound, private readonly ProfileResolver $routes,
        private readonly ControlRepository $controls) {}

    public function name(): string
    {
        return 'outbound_probes';
    }

    public function reconcile(): int
    {
        $modes = $this->controls->modes(['system', 'monitoring']);
        if (count($modes) !== 2 || in_array(ControlMode::Paused, $modes, true)) {
            return 0;
        }
        $candidates = $this->catalog->probeCandidates($this->cursor);
        if ($candidates === []) {
            $this->cursor = null;
            $candidates = $this->catalog->probeCandidates(null);
        }
        foreach ($candidates as $target) {
            $this->cursor = $target->string('id');
            $providerKey = $target->nullableString('provider_key');
            $provider = $providerKey === null ? null : new ProviderKey($providerKey);
            $outcome = $this->outbound->execute(callerCode: 'network.probe', platformId: $target->nullableInt('platform_id'),
                providerKey: $provider, targetKey: $target->string('target_key'), profileCode: 'network.probe',
                url: new SafeUrl($target->string('probe_url')), trafficClass: TrafficClass::Diagnostic,
                routePolicy: $this->routes->profile(provider: $provider !== null),
                controls: $provider === null ? ['system', 'monitoring'] : ['system', 'monitoring', 'provider:'.$provider->value],
                deadlineSeconds: 90, sendLimit: 1, probeOnly: true);

            return $outcome instanceof TransportSucceeded ? 1 : 0;
        }

        return 0;
    }
}
