<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\Control\CatalogSyncReport;
use App\Dto\Procurement\Network\Control\NetworkChangeResult;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Authority\ScopeDefinition;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Process\Governance\ScopeKind;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Repositories\Procurement\Process\Authority\ControlRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Llm\LlmProviderFactory;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;

/**
 * Explicit idempotent `platform:sync-catalog`: запускается ПОСЛЕ migrations
 * и ДО worker activation, никогда routine boot. Отдельные короткие tx без сети: control authority
 * (ensureScopes) → configured direct route → source catalog площадки. Override/retire —
 * audited operator mutations тех же authority rows с теми же lock tiers.
 */
final readonly class SourceCatalogSync
{
    public function __construct(
        private OutboundPolicy $policy,
        private SourceCatalogRegistry $registry,
        private SourceCatalogRepository $catalog,
        private ControlRepository $controls,
        private ProcessTransaction $transaction,
        private LlmProviderFactory $providerFactory,
    ) {}

    /**
     * @param  list<string>|null  $platformCodes  null — все зарегистрированные catalogs
     * @return list<CatalogSyncReport>
     */
    public function syncRegistered(?array $platformCodes = null): array
    {
        $catalogs = $this->registry->catalogs();
        if ($platformCodes !== null) {
            $unknown = array_diff($platformCodes, array_keys($catalogs));
            if ($unknown !== []) {
                throw new \InvalidArgumentException('No source catalog registered for: '.implode(', ', $unknown));
            }
            $catalogs = array_intersect_key($catalogs, array_flip($platformCodes));
        }

        return array_values(array_map($this->sync(...), $catalogs));
    }

    public function sync(SourceTargetDefinitions $catalog): CatalogSyncReport
    {
        $platform = LedgerRow::one('SELECT id FROM prc_platforms WHERE code = ?', [$catalog->platformCode])
            ?? throw new \InvalidArgumentException('Source catalog platform is not installed: '.$catalog->platformCode);
        $this->ensureBaseTopology(self::platformScopes($catalog->platformCode));
        [$eventId, $projectionId] = [ProcessUuid::random(), ProcessUuid::random()];

        return $this->transaction->run(fn (): CatalogSyncReport => $this->catalog->syncPlatform(
            $platform->int('id'), $catalog, $this->policy, $eventId, $projectionId,
        ));
    }

    public function syncProvider(ProviderTargetDefinitions $catalog): CatalogSyncReport
    {
        $this->ensureBaseTopology([
            new ScopeDefinition(ScopeKind::System, 'system', null),
            new ScopeDefinition(ScopeKind::Stage, 'monitoring', 'system'),
            new ScopeDefinition(ScopeKind::Provider, 'provider:'.$catalog->providerKey->value, 'system'),
        ]);
        [$eventId, $projectionId] = [ProcessUuid::random(), ProcessUuid::random()];

        return $this->transaction->run(fn (): CatalogSyncReport => $this->catalog->syncProvider($catalog, $this->policy, $eventId, $projectionId));
    }

    /** @param list<ScopeDefinition> $scopes */
    private function ensureBaseTopology(array $scopes): void
    {
        $this->transaction->run(fn () => $this->controls->ensureScopes($scopes));

    }

    /**
     * @param  list<string>|null  $providerKeys
     * @return list<CatalogSyncReport>
     */
    public function syncProviders(?array $providerKeys = null): array
    {
        $catalogs = $this->registry->providers();
        if (is_string(config('services.telegram.bot_token')) && config('services.telegram.bot_token') !== '') {
            $catalogs['telegram'] = new ProviderTargetDefinitions(new ProviderKey('telegram'), [
                new SourceTargetDefinition('provider:telegram:notification', 'notification', CanonicalOrigin::fromUrl('https://api.telegram.org'), [
                    new RequestProfile('notification.telegram', 5, 10, 10, 65_536, 196_608, 0, 1, 1, [], ResponseKind::Text, 'UTF-8', 1),
                ]),
            ]);
        }
        foreach ($this->providerFactory->networkOrigins() as $key => $urls) {
            if (isset($catalogs[$key])) {
                throw new \InvalidArgumentException('Duplicate configured provider catalog: '.$key);
            }
            $origins = [];
            foreach ($urls as $url) {
                $origin = CanonicalOrigin::fromUrl($url);
                $origins[$origin->value()] = $origin;
            }
            $targets = [];
            foreach ($origins as $origin) {
                $targets[] = new SourceTargetDefinition('provider:'.$key.':'.substr($origin->hash()->hex, 0, 16), 'provider', $origin, [
                    new RequestProfile('provider.response', 5, 15, 90, 8_388_608, 25_165_824, 0, 3, 2, [200, 600], ResponseKind::Text, 'UTF-8', 1),
                    new RequestProfile('network.probe', 5, 10, 20, 2_097_152, 6_291_456, 0, 1, 1, [], ResponseKind::Text, 'UTF-8', 1),
                ], probeUrl: $origin->value().'/');
            }
            $catalogs[$key] = new ProviderTargetDefinitions(new ProviderKey($key), $targets);
        }
        if ($providerKeys !== null) {
            if (array_diff($providerKeys, array_keys($catalogs)) !== []) {
                throw new \InvalidArgumentException('Unknown provider catalog.');
            }
            $catalogs = array_intersect_key($catalogs, array_flip($providerKeys));
        }
        $reports = [];
        foreach ($catalogs as $catalog) {
            $reports[] = $this->syncProvider($catalog);
        }

        return $reports;
    }

    public function retireTarget(ProcessUuid $targetId, int $expectedVersion, ?int $actorId, string $reason): NetworkChangeResult
    {
        [$eventId, $projectionId] = [ProcessUuid::random(), ProcessUuid::random()];

        return $this->transaction->run(fn (): NetworkChangeResult => $this->catalog->retireTarget(
            $targetId, $expectedVersion, $actorId, $reason, $eventId, $projectionId,
        ));
    }

    /**
     * Control authority площадки до первой работы: acquisition и documents purpose ветви.
     *
     * @return list<ScopeDefinition>
     */
    public static function platformScopes(string $platformCode): array
    {
        return [
            new ScopeDefinition(ScopeKind::System, ScopeKind::System->value, null),
            new ScopeDefinition(ScopeKind::Stage, 'monitoring', ScopeKind::System->value),
            new ScopeDefinition(ScopeKind::Acquisition, ScopeKind::Acquisition->value, ScopeKind::System->value),
            new ScopeDefinition(ScopeKind::Documents, ScopeKind::Documents->value, ScopeKind::System->value),
            new ScopeDefinition(ScopeKind::Platform, ScopeKind::Acquisition->value.':platform:'.$platformCode, ScopeKind::Acquisition->value),
            new ScopeDefinition(ScopeKind::Platform, ScopeKind::Documents->value.':platform:'.$platformCode, ScopeKind::Documents->value),
        ];
    }
}
