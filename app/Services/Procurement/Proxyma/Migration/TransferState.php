<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use App\Models\Procurement\Proxyma\CutoverJournal as Journal;
use App\Models\Procurement\Proxyma\OwnerLink;
use App\Models\Procurement\Proxyma\StateArchive;
use DomainException;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\CatalogProvisioner;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\ImportedHealthSampleRecord;

final readonly class TransferState
{
    public function __construct(private CutoverJournal $journal) {}

    public function run(string $cutover): void
    {
        $this->journal->step($cutover, 'state', function () use ($cutover): void {
            $i = $this->journal->inventory;
            $db = $i->db;
            $checkpoint = Inventory::checkpoint($db->table((new Journal)->getTable())->where('id', $cutover)->value('checkpoint'));
            if (($checkpoint['catalog'] ?? null) === null || array_filter($i->undrained()) !== []) {
                throw new DomainException('Final state transfer requires catalog and drained operations, permits and requests.');
            }
            $t = PackageSchema::tables();
            foreach ($i->rows('prc_network_budgets') as $budget) {
                $this->journal->put($cutover, $t['budgets'], ['tenant_key' => TenantKey::SYSTEM, 'id' => $budget['id']], [
                    'scope_key' => $budget['scope_key'], 'emission_interval_us' => $budget['emission_interval_us'],
                    'burst' => $budget['burst'], 'max_inflight' => $budget['max_inflight'], 'mandatory' => true,
                    'revision' => $budget['policy_version'], 'tat' => $budget['tat'], 'send_tat' => $budget['send_tat'],
                    'cooldown_until' => $budget['not_before'], 'inflight' => 0,
                ]);
            }
            $this->trafficCapacity($cutover);
            foreach (['route_health', 'route_target_health', 'target_health'] as $kind) {
                foreach ($i->rows('prc_'.$kind) as $health) {
                    if ($kind === 'route_health' && $health['reason_code'] === 'credential_hold') {
                        /** @var (object{credential_version: int, endpoint_id: string}&\stdClass)|null $route */
                        $route = $db->table('prc_network_routes as r')->join('prc_proxies as p', 'p.route_id', '=', 'r.id')
                            ->join('prc_proxyma_endpoint_links as l', 'l.proxy_id', '=', 'p.id')->where('r.id', $health['route_id'])
                            ->first(['r.credential_version', 'l.endpoint_id']);
                        if ($route === null) {
                            throw new DomainException('Unmapped credential hold.');
                        }
                        $health['credential_endpoint_id'] = $route->endpoint_id;
                        $health['credential_version'] = $route->credential_version;
                        $health['reason_code'] = 'credential_hold:'.$route->credential_version;
                    }
                    $this->journal->put($cutover, $t[$kind], ['tenant_key' => TenantKey::SYSTEM, 'id' => $health['id']], $health);
                }
            }
            (new CatalogProvisioner($db))->materialize(TenantKey::system(), []);
            // Retain the full immutable projection window/dedup facts, including stale epochs.
            $windowSeconds = max(config()->integer('outbound.health.route_target_window_seconds'), config()->integer('outbound.health.corroboration_window_seconds'));
            $checkpoint = Inventory::checkpoint($db->table((new Journal)->getTable())->where('id', $cutover)->value('checkpoint'));
            /** @var object{details: string|null, epochs: string, projection_key: string, observation_id: string, physical_id: string, stale: bool, occurred_at: string}&\stdClass $row */
            foreach ($db->table('prc_observations as o')->join('prc_observation_applications as a', 'a.observation_id', '=', 'o.observation_id')
                ->join('prc_request_attempts as r', 'r.request_id', '=', 'o.request_id')
                ->whereRaw('o.occurred_at>=?::timestamptz-make_interval(secs=>?) AND o.occurred_at<=?::timestamptz', [$checkpoint['state_as_of'], $windowSeconds, $checkpoint['state_as_of']])
                ->where('o.kind', 'network_transport')->get(['o.*', 'a.projection_key', 'r.request_id as physical_id']) as $row) {
                $details = Inventory::jsonObject($row->details ?? '{}');
                $epochs = Inventory::jsonObject($row->epochs);
                $prefix = strstr($row->projection_key, ':', true);
                $kind = match ($prefix) {
                    'prc_route_health' => 'route', 'prc_route_target_health' => 'route_target', 'prc_target_health' => 'target', default => null,
                };
                $signal = $kind === null ? null : ($details[$kind] ?? null);
                if (! in_array($kind, ['route', 'route_target', 'target'], true) || ! in_array($signal, ['success', 'failure', 'maintenance'], true)) {
                    continue;
                }
                $application = $kind.'_health:'.substr($row->projection_key, strlen($prefix) + 1);
                $this->journal->put($cutover, (new ImportedHealthSampleRecord)->getTable(),
                    ['tenant_key' => TenantKey::SYSTEM, 'observation_id' => $row->observation_id, 'application_key' => $application],
                    ['attempt_id' => $row->physical_id, 'health_epoch' => $epochs[$row->projection_key] ?? throw new DomainException('Observation epoch missing.'),
                        'signal' => $signal, 'independence_group' => $details['independence_group'] ?? null,
                        'reducer_allowed' => ! $row->stale, 'observed_at' => $row->occurred_at]);
            }
            foreach (['prc_network_demands', 'prc_network_permit_scopes', 'prc_network_permits', 'prc_adapter_health', 'prc_process_handler_holds'] as $table) {
                /** @var array{id?: string, permit_id?: string, budget_id?: string, handler_key?: string, demand_id?: string, authorized_at?: string|null, state?: string} $row */
                foreach ($i->rows($table) as $row) {
                    $id = $row['id'] ?? (($row['permit_id'] ?? '').':'.($row['budget_id'] ?? $row['handler_key'] ?? $i->checksum($row)));
                    $state = null;
                    $authorized = null;
                    if ($table === 'prc_network_permits') {
                        if (! isset($row['demand_id'], $row['state'])) {
                            throw new DomainException('Invalid historical permit.');
                        }
                        $request = $db->table('prc_request_attempts')->where('demand_id', $row['demand_id'])->first();
                        $authorized = $row['authorized_at'] ?? $request?->sent_at;
                        $finished = $request?->sent_at !== null && $request->finished_at !== null;
                        $state = $authorized !== null ? ($finished ? 'completed' : 'unknown_after_authorization')
                            : ($row['state'] === 'expired' ? 'expired_before_send' : 'cancelled_before_send');
                    }
                    $this->journal->put($cutover, (new StateArchive)->getTable(), ['source_table' => $table, 'source_key' => (string) $id],
                        ['payload' => json_encode($row, JSON_THROW_ON_ERROR), 'mapped_state' => $state,
                            'authorized_at' => $authorized]);
                }
            }
            foreach (['prc_process_attempts' => 'process_attempt', 'prc_outbound_operations' => 'outbound_operation'] as $table => $type) {
                foreach ($i->rows($table) as $owner) {
                    $this->journal->put($cutover, (new OwnerLink)->getTable(), ['tenant_key' => TenantKey::SYSTEM,
                        'owner_type' => $type, 'owner_id' => $owner['id'], 'generation' => $owner['generation']],
                        ['operation_id' => $owner['id'], 'parent_attempt_id' => $owner['parent_attempt_id'] ?? null]);
                }
            }
            if (array_filter($i->dryRun()['unmapped_ids']) !== []) {
                throw new DomainException('Unmapped owner, target, proxy or binding blocks final transfer.');
            }
        });
    }

    private function trafficCapacity(string $cutover): void
    {
        $db = $this->journal->inventory->db;
        $t = PackageSchema::tables();
        $percent = config()->integer('outbound.scheduling.status_capacity_percent');
        $checkpoint = Inventory::checkpoint($db->table((new Journal)->getTable())->where('id', $cutover)->value('checkpoint'));
        $asOf = $checkpoint['state_as_of'];
        /** @var string $value */
        foreach ($db->table('prc_source_targets')->distinct()->pluck('canonical_origin') as $value) {
            $origin = CanonicalOrigin::fromUrl($value);
            /** @var object{id: string, emission_interval_us: int, burst: int, max_inflight: int, policy_version: int, tat: string|null, send_tat: string|null, not_before: string|null}&\stdClass $parent */
            $parent = $db->table('prc_network_budgets')->where('scope_key', $origin->budgetScopeKey())->sole();
            $scope = TrafficSharePolicy::scope($origin, 'status_refresh');
            $hash = hash('sha256', 'agelto-traffic:'.$scope);
            $id = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
            new Uuid($id);
            $limit = TrafficSharePolicy::hourlyLimit($parent->emission_interval_us, $percent);
            /** @var object{start: string}&\stdClass $clock */
            $clock = $db->selectOne("SELECT date_trunc('hour',?::timestamptz AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' AS start", [$asOf]);
            /** @var object{spent: int, cooldown: string|null}&\stdClass $usage */
            $usage = $db->selectOne("SELECT count(*) AS spent,max(coalesce(p.authorized_at,r.sent_at))+interval '1 hour' AS cooldown
                FROM prc_network_permits p JOIN prc_network_demands d ON d.id=p.demand_id
                LEFT JOIN prc_request_attempts r ON r.demand_id=d.id
                WHERE d.origin_budget_id=? AND d.traffic_class='status_refresh' AND coalesce(p.authorized_at,r.sent_at)>?::timestamptz-interval '1 hour'
                    AND coalesce(p.authorized_at,r.sent_at)<=?::timestamptz", [$parent->id, $asOf, $asOf]);
            if ($usage->spent > $limit) {
                throw new DomainException('Legacy status usage exceeds migrated capacity; keep drained for explicit reconciliation.');
            }
            /** @var object{value: string|null}&\stdClass $cooldown */
            $cooldown = $db->selectOne('SELECT greatest(?::timestamptz,?::timestamptz)::text AS value', [$usage->cooldown, $parent->not_before]);
            /** @var object{value: string}&\stdClass $end */
            $end = $db->selectOne("SELECT ?::timestamptz+interval '1 hour' AS value", [$clock->start]);
            $this->journal->put($cutover, $t['budgets'], ['tenant_key' => TenantKey::SYSTEM, 'id' => $id], [
                'scope_key' => $scope, 'emission_interval_us' => $parent->emission_interval_us, 'burst' => $parent->burst,
                'max_inflight' => $parent->max_inflight, 'mandatory' => true, 'revision' => $parent->policy_version,
                'tat' => $parent->tat, 'send_tat' => $parent->send_tat,
                'cooldown_until' => $cooldown->value,
                'hour_limit' => $limit, 'quota_policy_key' => 'legacy-status', 'traffic_class' => 'status_refresh',
                'traffic_parent_id' => $parent->id, 'traffic_percent' => $percent]);
            $this->journal->put($cutover, $t['quota_windows'], ['tenant_key' => TenantKey::SYSTEM, 'budget_id' => $id,
                'starts_at' => $clock->start, 'window_kind' => 'hour', 'policy_key' => 'legacy-status'],
                ['ends_at' => $end->value,
                    'quota_limit' => $limit, 'reserved' => 0, 'spent' => $usage->spent]);
        }
    }
}
