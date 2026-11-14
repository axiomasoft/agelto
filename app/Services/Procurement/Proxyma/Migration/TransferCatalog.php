<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use App\Dto\Procurement\Platform\RequestProfile;
use App\Models\Procurement\Proxyma\BindingLink;
use App\Models\Procurement\Proxyma\EndpointLink;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Models\Procurement\Proxyma\TargetLink;
use DomainException;
use Proxyma\Domain\Bindings\BindingIdentity;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\BindingRevisionRecord;

final readonly class TransferCatalog
{
    public function __construct(private CutoverJournal $journal) {}

    /** @param array<int, array{endpoint_id: string, protocol: string}> $proxyEndpoints Explicit identities, never host/label matching. */
    public function run(string $cutover, array $proxyEndpoints): void
    {
        $this->journal->step($cutover, 'catalog', function () use ($cutover, $proxyEndpoints): void {
            $inventory = $this->journal->inventory;
            $db = $inventory->db;
            $t = PackageSchema::tables();
            $tenant = ['tenant_key' => TenantKey::SYSTEM];
            $global = self::id('global');
            $globalRevision = self::id('global:revision:1');
            $this->journal->put($cutover, $t['policy_heads'], $tenant + ['id' => $global], ['scope_key' => 'global:system', 'revision' => 1, 'current_revision_id' => $globalRevision]);
            $this->journal->put($cutover, $t['policy_revisions'], $tenant + ['id' => $globalRevision], ['head_id' => $global, 'revision' => 1, 'definition' => '{"schema_version":1,"operations":{}}']);
            $endpoints = [];
            /** @var list<array{id: int, route_id: string, host: string, port: int}> $proxies */
            $proxies = $inventory->rows('prc_proxies');
            foreach ($proxies as $proxy) {
                $mapping = $proxyEndpoints[$proxy['id']] ?? throw new DomainException('Missing explicit integer proxy endpoint map.');
                $endpoint = (new Uuid($mapping['endpoint_id']))->value;
                if (! in_array($mapping['protocol'], ['http', 'https', 'socks5', 'socks5h'], true)) {
                    throw new DomainException('Unsupported explicit proxy protocol.');
                }
                $route = $db->table('prc_network_routes')->where('id', $proxy['route_id'])->sole();
                $this->journal->put($cutover, $t['endpoints'], $tenant + ['id' => $endpoint], [
                    'protocol' => $mapping['protocol'], 'host' => strtolower($proxy['host']), 'port' => $proxy['port'],
                    'credential_ref' => $route->secret_ref,
                    'credential_version' => $route->secret_ref === null ? null : $route->credential_version,
                    'revision' => $route->credential_version, 'retired_at' => null,
                ]);
                $this->journal->put($cutover, (new EndpointLink)->getTable(), ['proxy_id' => $proxy['id']], $tenant + ['endpoint_id' => $endpoint]);
                $endpoints[$proxy['route_id']] = $endpoint;
            }
            if (array_diff(array_keys($proxyEndpoints), array_column($proxies, 'id')) !== []) {
                throw new DomainException('Explicit map contains unknown proxy IDs.');
            }
            /** @var list<array{id: string, route_key: string, kind: string, enabled: bool, egress_id: string, independence_group: string, credential_version: int}> $routes */
            $routes = $inventory->rows('prc_network_routes');
            foreach ($routes as $route) {
                $this->journal->put($cutover, $t['routes'], $tenant + ['id' => $route['id']], [
                    'route_key' => $route['route_key'], 'kind' => $route['kind'],
                    'endpoint_id' => $route['kind'] === 'proxy' ? ($endpoints[$route['id']] ?? throw new DomainException('Proxy route has no endpoint.')) : null,
                    'enabled' => $route['enabled'], 'egress_key' => $route['egress_id'],
                    'independence_group' => $route['independence_group'], 'revision' => $route['credential_version'],
                ]);
            }
            /** @var list<array{id: string, profile_key: string, policy_version: int}> $profiles */
            $profiles = $inventory->rows('prc_network_route_profiles');
            foreach ($profiles as $profile) {
                $this->journal->put($cutover, $t['route_profiles'], $tenant + ['id' => $profile['id']], ['profile_key' => $profile['profile_key'],
                    'strategy_key' => 'uniform_random', 'revision' => $profile['policy_version']]);
                $this->profileVersion($cutover, $profile['id'], $profile['policy_version']);
                foreach ($db->table('prc_network_route_profile_members')->where('profile_id', $profile['id'])->orderBy('route_id')->get() as $position => $member) {
                    $this->journal->put($cutover, $t['route_profile_members'], $tenant + ['profile_id' => $profile['id'], 'route_id' => $member->route_id], ['position' => $position]);
                }
            }
            /** @var list<array{id: string, canonical_origin: string, policy_version: int, retired_at: string|null}> $targets */
            $targets = $inventory->rows('prc_source_targets');
            foreach ($targets as $target) {
                $this->journal->put($cutover, $t['targets'], $tenant + ['id' => $target['id']], ['target_key' => 'source:'.$target['id'],
                    'canonical_origin' => $target['canonical_origin'], 'destination_policy_key' => 'default',
                    'revision' => $target['policy_version'], 'retired_at' => $target['retired_at']]);
                $this->journal->put($cutover, (new TargetLink)->getTable(), ['source_target_id' => $target['id']], $tenant + ['target_id' => $target['id'], 'source_revision' => $target['policy_version']]);
            }
            // Immutable old acquisition snapshots can name older profile versions and stage overrides.
            foreach ($inventory->rows('prc_process_runs') as $run) {
                $snapshot = $run['configuration_snapshot'];
                if (! is_array($snapshot) || ! isset($snapshot['route_profile_id'], $snapshot['route_policy_version'])) {
                    continue;
                }
                $this->snapshotVersion($cutover, $snapshot);
                $stages = $snapshot['stage_route_profiles'] ?? [];
                if (! is_array($stages)) {
                    throw new DomainException('Invalid historical stage profiles.');
                }
                foreach ($stages as $stage) {
                    if (! is_array($stage)) {
                        throw new DomainException('Invalid historical stage profile.');
                    }
                    $this->snapshotVersion($cutover, $stage);
                }
            }
            $this->bindings($cutover);
        });
    }

    private static function id(string $key): string
    {
        $hash = hash('sha256', 'agelto-proxyma-transfer:v1:'.$key);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    private function bindings(string $cutover): void
    {
        $db = $this->journal->inventory->db;
        $t = PackageSchema::tables();
        $tenant = ['tenant_key' => TenantKey::SYSTEM];
        /** @var list<array{id: string, capability: string, retired_at: string|null, provider_key: string|null, platform_id: int|null, endpoint_policy: array<string, mixed>}> $targets */
        $targets = $this->journal->inventory->rows('prc_source_targets');
        foreach ($targets as $target) {
            if ($target['retired_at'] !== null) {
                continue;
            }
            [$panel, $slots] = match ($target['capability']) {
                'listing' => ['agelto.acquisition', ['listing']],
                'detail', 'status_refresh' => ['agelto.acquisition', ['detail', 'status_refresh']],
                'download' => ['agelto.document', ['download']],
                'reference_sync' => ['agelto.reference', ['reference']],
                'provider' => ['agelto.provider', ['llm']],
                'notification' => ['agelto.notification', ['notification']],
                default => throw new DomainException('Unknown target capability needs explicit migration mapping.'),
            };
            $default = $db->table('prc_network_route_profiles')->where('profile_key', config('outbound.routing.'.($target['provider_key'] === null ? 'default_profile' : 'provider_profile')))->value('id');
            if (! is_string($default)) {
                throw new DomainException('Default profile missing for migrated target.');
            }
            $subjects = [[$target['provider_key'] === null ? 'platform' : 'provider', (string) ($target['platform_id'] ?? $target['provider_key']), $default]];
            if ($target['platform_id'] !== null) {
                /** @var object{id: int, route_profile_id: string|null} $crawler */
                foreach ($db->table('prc_crawlers as c')->join('prc_registries as r', 'r.id', '=', 'c.registry_id')->where('r.platform_id', $target['platform_id'])->get(['c.id', 'c.route_profile_id']) as $crawler) {
                    $subjects[] = ['crawler', (string) $crawler->id, $crawler->route_profile_id ?? $default];
                }
            }
            $encoded = $target['endpoint_policy']['profiles'] ?? [];
            if (! is_array($encoded) || $encoded === []) {
                throw new DomainException('Missing legacy request profiles.');
            }
            $profiles = [];
            foreach ($encoded as $profile) {
                if (! is_array($profile)) {
                    throw new DomainException('Invalid legacy request profile.');
                }
                $profiles[] = RequestProfile::fromArray($profile);
            }
            $attempts = max(array_map(static fn (RequestProfile $profile): int => $profile->attempts, $profiles));
            $delays = array_fill(0, $attempts - 1, 0);
            foreach ($profiles as $profile) {
                foreach ($profile->delaysMs as $n => $delay) {
                    $delays[$n] = max($delays[$n], $delay);
                }
            }
            $values = ['retry.max_attempts' => $attempts, 'retry.delays_ms' => $delays,
                'http.max_redirects' => max(array_map(static fn (RequestProfile $profile): int => $profile->redirects, $profiles))];
            $patch = ['schema_version' => 1, 'operations' => array_map(static fn ($v) => ['op' => 'set', 'value' => $v], $values)];
            foreach ($subjects as [$type, $subject, $profile]) {
                foreach ($slots as $slot) {
                    $id = BindingIdentity::id(new SubjectRef(TenantKey::system(), $type, $subject), $panel, $slot)->value;
                    $revision = self::id('policy:'.$id);
                    $patch['operations']['route.allow_direct'] = ['op' => 'set', 'value' => $db->table('prc_network_route_profile_members as m')
                        ->join('prc_network_routes as r', 'r.id', '=', 'm.route_id')->where('m.profile_id', $profile)->where('r.kind', 'direct')->exists()];
                    $this->journal->put($cutover, $t['policy_heads'], $tenant + ['id' => $id], ['scope_key' => 'binding:'.$id, 'revision' => 1, 'current_revision_id' => $revision]);
                    $this->journal->put($cutover, $t['policy_revisions'], $tenant + ['id' => $revision], ['head_id' => $id, 'revision' => 1, 'definition' => json_encode($patch, JSON_THROW_ON_ERROR)]);
                    $this->journal->put($cutover, $t['bindings'], $tenant + ['id' => $id], ['subject_type' => $type, 'subject_id' => $subject,
                        'panel_key' => $panel, 'slot_key' => $slot, 'policy_key' => 'legacy', 'head_id' => $id, 'profile_id' => $profile, 'revision' => 1]);
                    $this->journal->put($cutover, (new BindingRevisionRecord)->getTable(), $tenant + ['binding_id' => $id, 'revision' => 1],
                        ['policy_revision_id' => $revision, 'head_id' => $id, 'profile_id' => $profile, 'policy_key' => 'legacy', 'retired' => false]);
                    if ($type === 'crawler') {
                        $this->journal->put($cutover, (new BindingLink)->getTable(), ['crawler_id' => (int) $subject, 'slot' => $slot], $tenant + ['binding_id' => $id]);
                    }
                }
            }
        }
        /** @var object{id: int, route_profile_id: string|null} $crawler */
        foreach ($db->table('prc_crawlers')->orderBy('id')->get() as $crawler) {
            $profile = $crawler->route_profile_id ?? $db->table('prc_network_route_profiles')->where('profile_key', config('outbound.routing.default_profile'))->value('id');
            if ($profile === null) {
                throw new DomainException('Crawler has no mapped profile.');
            }
            foreach (['listing' => 'agelto.acquisition', 'detail' => 'agelto.acquisition', 'status_refresh' => 'agelto.acquisition', 'download' => 'agelto.document'] as $slot => $panel) {
                $id = BindingIdentity::id(new SubjectRef(TenantKey::system(), 'crawler', (string) $crawler->id), $panel, $slot)->value;
                if (! $db->table($t['bindings'])->where('tenant_key', TenantKey::SYSTEM)->where('id', $id)->exists()) {
                    $revision = self::id('policy:'.$id);
                    $this->journal->put($cutover, $t['policy_heads'], $tenant + ['id' => $id], ['scope_key' => 'binding:'.$id, 'revision' => 1, 'current_revision_id' => $revision]);
                    $this->journal->put($cutover, $t['policy_revisions'], $tenant + ['id' => $revision], ['head_id' => $id, 'revision' => 1, 'definition' => '{"schema_version":1,"operations":{}}']);
                    $this->journal->put($cutover, $t['bindings'], $tenant + ['id' => $id], ['subject_type' => 'crawler', 'subject_id' => (string) $crawler->id,
                        'panel_key' => $panel, 'slot_key' => $slot, 'policy_key' => 'legacy', 'head_id' => $id, 'profile_id' => $profile, 'revision' => 1]);
                    $this->journal->put($cutover, (new BindingRevisionRecord)->getTable(), $tenant + ['binding_id' => $id, 'revision' => 1],
                        ['policy_revision_id' => $revision, 'head_id' => $id, 'profile_id' => $profile, 'policy_key' => 'legacy', 'retired' => false]);
                }
                $this->journal->put($cutover, (new BindingLink)->getTable(), ['crawler_id' => $crawler->id, 'slot' => $slot], $tenant + ['binding_id' => $id]);
            }
        }
    }

    private function profileVersion(string $cutover, string $id, int $version): void
    {
        if ($version < 1 || ! $this->journal->inventory->db->table(PackageSchema::tables()['route_profiles'])->where('id', $id)->exists()) {
            throw new DomainException('Unmapped historical stage profile.');
        }
        $this->journal->put($cutover, (new ProfileLink)->getTable(), ['source_profile_id' => $id, 'source_version' => $version],
            ['tenant_key' => TenantKey::SYSTEM, 'profile_id' => $id, 'profile_version' => $version]);
    }

    /** @param array<array-key, mixed> $snapshot */
    private function snapshotVersion(string $cutover, array $snapshot): void
    {
        if (! is_string($snapshot['route_profile_id'] ?? null) || ! is_int($snapshot['route_policy_version'] ?? null)) {
            throw new DomainException('Invalid historical profile identity or revision.');
        }
        $this->profileVersion($cutover, $snapshot['route_profile_id'], $snapshot['route_policy_version']);
    }
}
