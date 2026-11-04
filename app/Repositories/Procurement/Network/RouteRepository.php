<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkChangeResult;
use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Process\ActorKind;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Support\SecretValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writer `prc_network_routes`: configured direct egress identity, proxy routes
 * (unique FK из `prc_proxies`) и route profiles. Наличие route row
 * не даёт права слать: разрешение задаёт route profile membership. Существующую row (её
 * administrative enabled/disabled) ensure не меняет.
 */
final readonly class RouteRepository
{
    public function __construct(private TransitionRepository $transitions) {}

    public function createProxyRoute(string $key, ?string $secretRef, bool $enabled = true): string
    {
        CallerTransaction::require('Proxy route create');
        $id = Str::lower((string) Str::uuid());
        DB::insert("INSERT INTO prc_network_routes (id, route_key, kind, egress_id, enabled, secret_ref, credential_version)
            VALUES (?, ?, 'proxy', ?, ?, ?, 1)", [$id, $key, $key, $enabled, $secretRef]);

        return $id;
    }

    public function storeProxy(?Proxy $proxy,
        ProxyNodeConfiguration $configuration, ?int $actorId, string $reason,
        ProcessUuid $event, ProcessUuid $message): Proxy
    {
        CallerTransaction::require('Proxy configuration mutation');
        $system = $this->lockSystem();
        if ($proxy === null) {
            $route = $this->createProxyRoute('proxy:'.Str::uuid(), $configuration->secretRef, $configuration->enabled);
            $proxy = new Proxy;
            $proxy->forceFill(['route_id' => $route]);
        } else {
            $node = LedgerRow::one('SELECT route_id, host, port FROM prc_proxies WHERE id = ? FOR UPDATE', [$proxy->id])
                ?? throw new \InvalidArgumentException('Proxy configuration no longer exists.');
            $route = $node->string('route_id');
            if ($node->string('host') !== $configuration->host || $node->int('port') !== $configuration->port) {
                $oldRoute = $route;
                DB::update("UPDATE prc_network_routes SET enabled = false, disabled_at = clock_timestamp(), disabled_reason = 'node_reconfigured' WHERE id = ?", [$oldRoute]);
                $route = $this->createProxyRoute('proxy:'.Str::uuid(), $configuration->secretRef, $configuration->enabled);
                DB::update('UPDATE prc_network_route_profiles SET policy_version = policy_version + 1 WHERE id IN (SELECT profile_id FROM prc_network_route_profile_members WHERE route_id = ?)', [$oldRoute]);
                DB::update('UPDATE prc_network_route_profile_members SET route_id = ? WHERE route_id = ?', [$route, $oldRoute]);
                $proxy->forceFill(['route_id' => $route]);
            } else {
                DB::update('UPDATE prc_network_routes SET enabled = ?, secret_ref = ?, credential_version = credential_version + 1,
                    disabled_at = CASE WHEN ?::boolean THEN NULL ELSE clock_timestamp() END,
                    disabled_reason = CASE WHEN ?::boolean THEN NULL ELSE ? END WHERE id = ?',
                    [$configuration->enabled, $configuration->secretRef,
                        $configuration->enabled, $configuration->enabled, $reason, $route]);
            }
        }
        $proxy->fill(['service' => $configuration->service, 'host' => $configuration->host, 'port' => $configuration->port,
            'username' => $configuration->username, 'country' => $configuration->country, 'scopes' => $configuration->scopes]);
        $proxy->save();
        $this->audit($system, $actorId, 'network_proxy_configured', $reason, $route, $event, $message);

        return $proxy;
    }

    public function reactivate(ProcessUuid $routeId, int $actorId, string $reason, ProcessUuid $event, ProcessUuid $message): void
    {
        CallerTransaction::require('Route reactivation');
        $system = $this->lockSystem();
        $route = LedgerRow::one('SELECT enabled FROM prc_network_routes WHERE id = ? FOR UPDATE', [$routeId->value]);
        if ($route === null) {
            throw new \InvalidArgumentException('Route does not exist.');
        }
        if ($route->bool('enabled')) {
            return;
        }
        DB::update('UPDATE prc_network_routes SET enabled = true, disabled_at = NULL, disabled_reason = NULL WHERE id = ?', [$routeId->value]);
        $this->audit($system, $actorId, 'network_route_reactivated', $reason, $routeId->value, $event, $message);
    }

    /** @param list<string> $routeIds */
    public function updateProfile(string $key, int $expectedVersion, array $routeIds, int $actorId, string $reason,
        ProcessUuid $event, ProcessUuid $message): NetworkChangeResult
    {
        CallerTransaction::require('Route profile mutation');
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $key) !== 1 || count($routeIds) > 64 || count(array_unique($routeIds)) !== count($routeIds)) {
            throw new \InvalidArgumentException('Invalid exact route profile.');
        }
        $system = $this->lockSystem();
        $profile = LedgerRow::one('SELECT id, policy_version FROM prc_network_route_profiles WHERE profile_key = ? FOR UPDATE', [$key]);
        $version = $profile?->int('policy_version') ?? 0;
        if ($version !== $expectedVersion) {
            return new NetworkChangeResult(NetworkChangeStatus::VersionConflict, $version);
        }
        sort($routeIds, SORT_STRING);
        foreach ($routeIds as $id) {
            new ProcessUuid($id);
            if (LedgerRow::one('SELECT id FROM prc_network_routes WHERE id = ? FOR UPDATE', [$id]) === null) {
                throw new \InvalidArgumentException('Route profile member does not exist.');
            }
        }
        $id = $profile?->string('id') ?? Str::lower((string) Str::uuid());
        if ($profile === null) {
            DB::insert('INSERT INTO prc_network_route_profiles (id, profile_key, policy_version) VALUES (?, ?, 1)', [$id, $key]);
        } else {
            DB::update('UPDATE prc_network_route_profiles SET policy_version = policy_version + 1 WHERE id = ?', [$id]);
            DB::delete('DELETE FROM prc_network_route_profile_members WHERE profile_id = ?', [$id]);
        }
        foreach ($routeIds as $routeId) {
            DB::insert('INSERT INTO prc_network_route_profile_members (profile_id, route_id) VALUES (?, ?)', [$id, $routeId]);
        }
        $this->audit($system, $actorId, 'network_route_profile_changed', $reason, $id, $event, $message);

        return new NetworkChangeResult(NetworkChangeStatus::Applied, $version + 1);
    }

    private function lockSystem(): LedgerRow
    {
        return LedgerRow::one("SELECT id, desired_mode, control_version FROM prc_control_scopes WHERE scope_key = 'system' FOR UPDATE")
            ?? throw new \LogicException('Network configuration requires the installed control authority.');
    }

    private function audit(LedgerRow $system, ?int $actorId, string $code, string $reason, string $id, ProcessUuid $event, ProcessUuid $message): void
    {
        $version = $system->int('control_version') + 1;
        DB::update('UPDATE prc_control_scopes SET control_version = ? WHERE id = ?', [$version, $system->string('id')]);
        $this->transitions->recordControl($event, $message, new ProcessUuid($system->string('id')), $version,
            $system->string('desired_mode'), $system->string('desired_mode'), $code,
            $actorId === null ? ActorKind::System : ActorKind::Operator, $actorId, ['reason' => $reason, 'network_entity_id' => $id]);
    }

    public function profile(?ProcessUuid $id, string $key): ?ProcessUuid
    {
        $row = $id === null
            ? LedgerRow::one('SELECT id FROM prc_network_route_profiles WHERE profile_key = ?', [$key])
            : LedgerRow::one('SELECT id FROM prc_network_route_profiles WHERE id = ?', [$id->value]);

        return $row === null ? null : new ProcessUuid($row->string('id'));
    }

    /** @return list<HttpRouteBinding> */
    public function bindings(ProcessUuid $profile): array
    {
        $bindings = [];
        foreach (LedgerRow::all('SELECT r.id, r.kind, r.credential_version, r.secret_ref, p.host, p.port, p.username
            FROM prc_network_route_profile_members m JOIN prc_network_routes r ON r.id = m.route_id
            LEFT JOIN prc_proxies p ON p.route_id = r.id WHERE m.profile_id = ? ORDER BY r.route_key', [$profile->value]) as $row) {
            if ($row->string('kind') === 'direct') {
                $bindings[] = new HttpRouteBinding(id: new ProcessUuid($row->string('id')), credentialVersion: $row->int('credential_version'));

                continue;
            }
            $secretRef = $row->nullableString('secret_ref');
            $password = $secretRef === null ? null : SecretValue::resolve($secretRef);
            if ($row->nullableString('host') === null || $row->nullableInt('port') === null
                || ($secretRef !== null && (! is_string($password) || $password === ''))) {
                throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
            }
            $username = $row->nullableString('username');
            if (is_string($password) && str_starts_with($password, 'http://')) {
                $secret = parse_url($password);
                if (! is_array($secret) || ($secret['host'] ?? null) !== $row->string('host')
                    || ($secret['port'] ?? null) !== $row->int('port') || isset($secret['query']) || isset($secret['fragment'])) {
                    throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
                }
                $username = isset($secret['user']) ? rawurldecode($secret['user']) : null;
                $password = isset($secret['pass']) ? rawurldecode($secret['pass']) : null;
            }
            if ($password !== null && ! is_string($password)) {
                throw new \LogicException('Expected resolved proxy secret.');
            }
            $bindings[] = new HttpRouteBinding(id: new ProcessUuid($row->string('id')),
                proxy: 'http://'.$row->string('host').':'.$row->int('port'), username: $username,
                password: $password, credentialVersion: $row->int('credential_version'));
        }

        return $bindings;
    }

    /** Explicit install defaults; repeated sync preserves an operator's changed membership. */
    public function ensureProfile(string $key, ?string $initialRoute = null): void
    {
        CallerTransaction::require('Route profile ensure');
        $id = Str::lower((string) Str::uuid());
        DB::insert('INSERT INTO prc_network_route_profiles (id, profile_key, policy_version)
            VALUES (?, ?, 1) ON CONFLICT (profile_key) DO NOTHING', [$id, $key]);
        // Only the freshly generated id can receive initial members; existing profiles are untouched.
        if ($initialRoute !== null) {
            DB::insert('INSERT INTO prc_network_route_profile_members (profile_id, route_id)
                SELECT id, ?::uuid FROM prc_network_route_profiles WHERE id = ? ON CONFLICT DO NOTHING', [$initialRoute, $id]);
        }
    }

    public function ensureDirectRoute(string $egressId, ?string $independenceGroup): string
    {
        CallerTransaction::require('Direct route ensure');
        $key = 'direct:'.$egressId;
        DB::insert(
            "INSERT INTO prc_network_routes (id, route_key, kind, egress_id, independence_group, enabled, credential_version)
             VALUES (?, ?, 'direct', ?, ?, true, 1) ON CONFLICT (route_key) DO NOTHING",
            [Str::lower((string) Str::uuid()), $key, $egressId, $independenceGroup],
        );
        $row = LedgerRow::one('SELECT id, kind, egress_id FROM prc_network_routes WHERE route_key = ?', [$key])
            ?? throw new \LogicException('Direct route disappeared: routes are never deleted.');
        if ($row->string('kind') !== 'direct' || $row->string('egress_id') !== $egressId) {
            throw new \LogicException('Route identity is immutable: '.$key);
        }

        return $row->string('id');
    }
}
