<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\CatalogSyncReport;
use App\Dto\Procurement\Network\NetworkChangeResult;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Process\ActorKind;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Services\Procurement\Network\OutboundPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Единственный writer source catalog authority:
 * `prc_source_targets`, origin/probe/route-origin `prc_network_budgets` и начальные health rows.
 * Вызывается только явным catalog sync/override/retire, никогда routine boot.
 */
final readonly class SourceCatalogRepository
{
    public function __construct(private TransitionRepository $transitions, private AdapterHealthRepository $adapters) {}

    /** @return list<LedgerRow> Bounded advisory scan; every effect is revalidated by claim/send. */
    public function probeCandidates(?string $afterId, int $limit = 8): array
    {
        return SourceCatalogSqlHelper::probeCandidates($afterId, $limit);
    }

    /** New configured routes get the same unknown health and budget authorities as explicit sync. */
    public function initializeRoute(string $routeId, OutboundPolicy $policy): void
    {
        CallerTransaction::require('Route authority initialization');
        $routeRow = LedgerRow::one('SELECT egress_id FROM prc_network_routes WHERE id = ?', [$routeId]);
        $egressId = $routeRow?->string('egress_id');
        $sharedEgress = $egressId !== null && LedgerRow::one('SELECT 1 AS shared FROM prc_network_routes WHERE egress_id = ? AND id <> ? LIMIT 1', [$egressId, $routeId]) !== null;
        $targets = [];
        foreach (LedgerRow::all('SELECT id, canonical_origin, endpoint_policy::text AS endpoint_policy FROM prc_source_targets WHERE retired_at IS NULL ORDER BY id') as $target) {
            $targets[$target->string('id')] = $target->string('id');
            $origin = CanonicalOrigin::fromUrl($target->string('canonical_origin'));
            $ep = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
            $routeBudget = is_array($ep) && is_array($ep['route_origin_budget'] ?? null)
                ? OriginBudgetPolicy::fromArray($ep['route_origin_budget'])
                : $policy->routeOriginBudget;
            DB::insert('INSERT INTO prc_network_budgets (id, scope_key, emission_interval_us, burst, max_inflight, policy_version)
                VALUES (?, ?, ?, ?, ?, 1) ON CONFLICT (scope_key) DO NOTHING',
                [ProcessUuid::random()->value, $origin->routeScopeKey($routeId), $routeBudget->emissionIntervalUs(),
                    $routeBudget->burst, $routeBudget->maxInflight]);
            if ($sharedEgress) {
                $egressBudget = is_array($ep) && is_array($ep['egress_origin_budget'] ?? null)
                    ? OriginBudgetPolicy::fromArray($ep['egress_origin_budget'])
                    : $routeBudget;
                DB::insert('INSERT INTO prc_network_budgets (id, scope_key, emission_interval_us, burst, max_inflight, policy_version)
                    VALUES (?, ?, ?, ?, ?, 1) ON CONFLICT (scope_key) DO NOTHING',
                    [ProcessUuid::random()->value, $origin->egressScopeKey($egressId), $egressBudget->emissionIntervalUs(),
                        $egressBudget->burst, $egressBudget->maxInflight]);
            }
        }
        SourceCatalogSqlHelper::ensureHealthRows($targets, [$routeId]);
    }

    public function findActiveAdapterTarget(int $platformId, string $targetKey, AdapterContractRef $contract): ?ProcessUuid
    {
        $row = LedgerRow::one('SELECT id FROM prc_source_targets WHERE platform_id=? AND target_key=? AND retired_at IS NULL
            AND endpoint_policy_schema_version=2 AND endpoint_policy->\'adapter_contract\'=?::jsonb',
            [$platformId, $targetKey, json_encode($contract->toArray(), JSON_THROW_ON_ERROR)]);

        return $row === null ? null : new ProcessUuid($row->string('id'));
    }

    public function targetForCall(?int $platformId, ?string $providerKey, string $capability, string $origin): ?LedgerRow
    {
        $rows = LedgerRow::all('SELECT '.SourceCatalogSqlHelper::TARGET_COLUMNS.' FROM prc_source_targets
            WHERE platform_id IS NOT DISTINCT FROM ?::bigint AND provider_key IS NOT DISTINCT FROM ?::text
              AND capability = ? AND canonical_origin = ? AND retired_at IS NULL ORDER BY target_key',
            [$platformId, $providerKey, $capability, $origin]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function providerForOrigin(string $origin): ?ProviderKey
    {
        $rows = LedgerRow::all("SELECT DISTINCT provider_key FROM prc_source_targets WHERE platform_id IS NULL
            AND provider_key IS NOT NULL AND capability = 'provider' AND canonical_origin = ? AND retired_at IS NULL", [$origin]);

        return count($rows) === 1 ? new ProviderKey($rows[0]->string('provider_key')) : null;
    }

    /** @return list<CanonicalOrigin> */
    public function originsForOwner(?int $platformId, ?string $providerKey, string $capability): array
    {
        return array_map(static fn (LedgerRow $row): CanonicalOrigin => CanonicalOrigin::fromUrl($row->string('canonical_origin')),
            LedgerRow::all('SELECT DISTINCT canonical_origin FROM prc_source_targets
                WHERE platform_id IS NOT DISTINCT FROM ?::bigint AND provider_key IS NOT DISTINCT FROM ?::text
                  AND capability = ? AND retired_at IS NULL ORDER BY canonical_origin', [$platformId, $providerKey, $capability]));
    }

    public function syncPlatform(
        int $platformId,
        SourceTargetDefinitions $catalog,
        OutboundPolicy $policy,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): CatalogSyncReport {
        return $this->syncOwner($platformId, $catalog, $policy, $eventId, $projectionMessageId);
    }

    public function syncProvider(ProviderTargetDefinitions $catalog, OutboundPolicy $policy, ProcessUuid $eventId, ProcessUuid $projectionMessageId): CatalogSyncReport
    {
        return $this->syncOwner(null, $catalog, $policy, $eventId, $projectionMessageId);
    }

    private function syncOwner(
        ?int $platformId,
        SourceTargetDefinitions|ProviderTargetDefinitions $catalog,
        OutboundPolicy $policy,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): CatalogSyncReport {
        CallerTransaction::require('Source catalog sync');
        $system = SourceCatalogSqlHelper::lockSystemAuthority();
        $providerKey = $catalog instanceof ProviderTargetDefinitions ? $catalog->providerKey->value : null;
        $rows = SourceCatalogSqlHelper::lockTargets($platformId, $providerKey);
        SourceCatalogSqlHelper::validateOriginDefaults($platformId, $catalog, $policy);

        $synced = SourceCatalogSqlHelper::syncTargetRows($platformId, $providerKey, $catalog, $policy, $rows, $this->adapters);
        $routeRows = LedgerRow::all('SELECT id, egress_id FROM prc_network_routes ORDER BY id');
        $routes = array_map(static fn (LedgerRow $route): string => $route->string('id'), $routeRows);
        $routeEgressIds = [];
        foreach ($routeRows as $routeRow) {
            $routeEgressIds[$routeRow->string('id')] = $routeRow->string('egress_id');
        }
        [$budgetsCreated, $drifted] = SourceCatalogSqlHelper::ensureBudgets(
            SourceCatalogSqlHelper::declaredBudgets($catalog, $policy, $routes, $routeEgressIds, $platformId),
        );
        $health = SourceCatalogSqlHelper::ensureHealthRows($synced['active'], $routes);

        $report = new CatalogSyncReport(
            platformCode: $catalog instanceof SourceTargetDefinitions ? $catalog->platformCode : null,
            targetsCreated: $synced['created'],
            targetsUpdated: $synced['updated'],
            targetsRetired: $synced['retired'],
            targetsUnchanged: $synced['unchanged'],
            budgetsCreated: $budgetsCreated,
            driftedBudgets: $drifted,
            healthRowsCreated: $health,
            retiredDeclared: $synced['retiredDeclared'],
            providerKey: $catalog instanceof ProviderTargetDefinitions ? $catalog->providerKey : null,
        );
        if ($report->changed()) {
            $this->audit($system, 'network_catalog_sync', ActorKind::System, null, [
                'platform' => $catalog instanceof SourceTargetDefinitions ? $catalog->platformCode : null,
                'provider' => $providerKey,
                'targets_created' => $synced['created'],
                'targets_updated' => $synced['updated'],
                'targets_retired' => $synced['retired'],
                'budgets_created' => $budgetsCreated,
                'health_rows_created' => $health,
            ], $eventId, $projectionMessageId);
        }

        return $report;
    }

    public function overrideBudget(
        string $scopeKey,
        OriginBudgetPolicy $budget,
        int $expectedVersion,
        OutboundPolicy $policy,
        ?int $actorId,
        string $reason,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): NetworkChangeResult {
        CallerTransaction::require('Network budget override');
        $reason = SourceCatalogSqlHelper::reason($reason);
        if (! $policy->withinBounds($budget) || (str_starts_with($scopeKey, 'probe:') && $budget->maxInflight !== 1)) {
            throw new \InvalidArgumentException('Budget override is outside outbound.override_bounds.');
        }
        $system = SourceCatalogSqlHelper::lockSystemAuthority();
        $row = LedgerRow::one(
            'SELECT id, emission_interval_us, burst, max_inflight, policy_version FROM prc_network_budgets WHERE scope_key = ? FOR UPDATE',
            [$scopeKey],
        );
        if ($row === null) {
            return new NetworkChangeResult(NetworkChangeStatus::NotFound, null);
        }
        $version = $row->int('policy_version');
        if ($version !== $expectedVersion) {
            return new NetworkChangeResult(NetworkChangeStatus::VersionConflict, $version);
        }
        if ($budget->matchesStored($row->int('emission_interval_us'), $row->int('burst'), $row->int('max_inflight'))) {
            return new NetworkChangeResult(NetworkChangeStatus::Unchanged, $version);
        }
        $changed = LedgerRow::one(
            'UPDATE prc_network_budgets SET emission_interval_us = ?, burst = ?, max_inflight = ?, policy_version = policy_version + 1
             WHERE id = ? AND policy_version = ? RETURNING policy_version',
            [$budget->emissionIntervalUs(), $budget->burst, $budget->maxInflight, $row->string('id'), $version],
        ) ?? throw new \LogicException('Budget policy fence did not match the locked row.');
        $this->audit($system, 'network_budget_override', $actorId === null ? ActorKind::System : ActorKind::Operator, $actorId, [
            'scope_key' => $scopeKey,
            'previous_emission_interval_us' => $row->int('emission_interval_us'),
            'previous_burst' => $row->int('burst'),
            'previous_max_inflight' => $row->int('max_inflight'),
            'emission_interval_us' => $budget->emissionIntervalUs(),
            'rate_per_minute' => $budget->ratePerMinute,
            'burst' => $budget->burst,
            'max_inflight' => $budget->maxInflight,
            'policy_version' => $changed->int('policy_version'),
            'reason' => $reason,
        ], $eventId, $projectionMessageId);

        return new NetworkChangeResult(NetworkChangeStatus::Applied, $changed->int('policy_version'));
    }

    public function retireTarget(
        ProcessUuid $targetId,
        int $expectedVersion,
        ?int $actorId,
        string $reason,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): NetworkChangeResult {
        CallerTransaction::require('Source target retire');
        $reason = SourceCatalogSqlHelper::reason($reason);
        $system = SourceCatalogSqlHelper::lockSystemAuthority();
        $row = LedgerRow::one('SELECT '.SourceCatalogSqlHelper::TARGET_COLUMNS.' FROM prc_source_targets WHERE id = ? FOR UPDATE', [$targetId->value]);
        if ($row === null) {
            return new NetworkChangeResult(NetworkChangeStatus::NotFound, null);
        }
        $version = $row->int('policy_version');
        if ($version !== $expectedVersion) {
            return new NetworkChangeResult(NetworkChangeStatus::VersionConflict, $version);
        }
        if ($row->nullableString('retired_at') !== null) {
            return new NetworkChangeResult(NetworkChangeStatus::Unchanged, $version);
        }
        $changed = LedgerRow::one(
            'UPDATE prc_source_targets SET retired_at = clock_timestamp(), policy_version = policy_version + 1
             WHERE id = ? AND policy_version = ? AND retired_at IS NULL RETURNING policy_version',
            [$row->string('id'), $version],
        ) ?? throw new \LogicException('Target policy fence did not match the locked row.');
        $this->audit($system, 'network_target_retired', $actorId === null ? ActorKind::System : ActorKind::Operator, $actorId, [
            'target_key' => $row->string('target_key'),
            'policy_version' => $changed->int('policy_version'),
            'reason' => $reason,
        ], $eventId, $projectionMessageId);

        return new NetworkChangeResult(NetworkChangeStatus::Applied, $changed->int('policy_version'));
    }

    /** @param array<string, int|string|null> $details */
    private function audit(
        LedgerRow $system,
        string $reasonCode,
        ActorKind $actor,
        ?int $actorId,
        array $details,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): void {
        $details = array_filter($details, static fn (int|string|null $value): bool => $value !== null);
        $changed = LedgerRow::one(
            'UPDATE prc_control_scopes SET control_version = control_version + 1 WHERE id = ? AND control_version = ? RETURNING control_version',
            [$system->string('id'), $system->int('control_version')],
        ) ?? throw new \LogicException('System authority fence did not match the locked row.');
        $mode = $system->string('desired_mode');
        $this->transitions->recordControl(
            $eventId, $projectionMessageId, new ProcessUuid($system->string('id')), $changed->int('control_version'),
            $mode, $mode, $reasonCode, $actor, $actorId, $details,
        );
    }
}
