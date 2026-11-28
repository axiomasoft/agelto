<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\AdapterValidation\AdapterContractRef;
use App\Dto\Procurement\Network\Control\CatalogSyncReport;
use App\Dto\Procurement\Network\Control\NetworkChangeResult;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Governance\TransitionData;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Process\Governance\ActorKind;
use App\Repositories\Procurement\Process\Projection\TransitionRepository;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Proxyma\Caller\CallerSendFacts;

/**
 * Единственный writer source catalog authority:
 * `prc_source_targets` и adapter contracts. Budget/route/network health writers принадлежат package.
 * Вызывается только явным catalog sync/override/retire, никогда routine boot.
 */
final readonly class SourceCatalogRepository
{
    public function __construct(private TransitionRepository $transitions, private AdapterHealthRepository $adapters) {}

    /** @return list<LedgerRow> Bounded advisory scan; every effect is revalidated by claim/send. */
    public function probeCandidates(?string $afterId, int $limit = 8): array
    {
        return CallerSendFacts::active() ? CallerSendFacts::probeCandidates($afterId, $limit) : [];
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
        $budgetsCreated = $health = 0;
        $drifted = [];

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
        $this->transitions->recordControl(new TransitionData(
            eventId: $eventId,
            projectionMessageId: $projectionMessageId,
            entityId: new ProcessUuid($system->string('id')),
            entitySeq: $changed->int('control_version'),
            previousState: $mode,
            newState: $mode,
            reasonCode: $reasonCode,
            actor: $actor,
            actorId: $actorId,
            details: $details,
        ));
    }
}
