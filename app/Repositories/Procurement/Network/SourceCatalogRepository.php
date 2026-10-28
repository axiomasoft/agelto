<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\CatalogSyncReport;
use App\Dto\Procurement\Network\NetworkChangeResult;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Process\ActorKind;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Services\Procurement\Network\OutboundPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Единственный writer source catalog authority (platform-transport#s02/#s06, refactoring#rf-network):
 * `prc_source_targets`, origin/probe/route-origin `prc_network_budgets` и начальные health rows.
 * Вызывается только явным catalog sync/override/retire, никогда routine boot.
 *
 * Lock order (bp-lock-modes, tier1 → tier7): `system` control FOR UPDATE (audit anchor и
 * сериализация catalog mutations; эта tx его ранее не брала FOR SHARE) → targets FOR UPDATE в
 * byte-sorted target_key → budgets/health INSERT в sorted порядке ключей. Сеть не вызывается.
 *
 * Инварианты: повтор sync без изменения definition ничего не пишет и не bump-ит version;
 * существующий budget (operator override и rate debt tat/send_tat/not_before) sync никогда не
 * перезаписывает — расхождение с default только сообщается. Override меняет лишь rate/burst/
 * inflight + policy_version, не перематывая GCRA debt. Retire сохраняет row и историю.
 * Audit — control transition `system` с bump control_version (отдельной audit table нет).
 */
final readonly class SourceCatalogRepository
{
    private const int REASON_LIMIT = 200;

    private const string TARGET_COLUMNS = 'id, platform_id, target_key, canonical_origin, capability, endpoint_policy::text AS endpoint_policy,
        endpoint_policy_schema_version, policy_version, retired_at::text AS retired_at';

    public function __construct(private TransitionRepository $transitions) {}

    public function syncPlatform(
        int $platformId,
        SourceTargetDefinitions $catalog,
        OutboundPolicy $policy,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): CatalogSyncReport {
        CallerTransaction::require('Source catalog sync');
        $system = self::lockSystemAuthority();
        $rows = self::lockTargets($platformId);
        self::validateOriginDefaults($platformId, $catalog, $policy);

        $created = $updated = $retired = $unchanged = 0;
        $retiredDeclared = [];
        /** @var array<string, string> $active target key → id */
        $active = [];
        foreach ($catalog->targets as $key => $target) {
            $row = $rows[$key] ?? null;
            if ($row === null) {
                $id = self::uuid();
                DB::insert(
                    'INSERT INTO prc_source_targets
                       (id, platform_id, target_key, canonical_origin, capability, endpoint_policy, endpoint_policy_schema_version, policy_version)
                     VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, 1)',
                    [$id, $platformId, $key, $target->origin->value(), $target->capability, self::json($target->endpointPolicy($catalog->originBudget($target->origin, $policy->originBudget))), SourceTargetDefinition::ENDPOINT_POLICY_SCHEMA_VERSION],
                );
                $active[$key] = $id;
                $created++;

                continue;
            }
            if ($row->int('platform_id') !== $platformId) {
                throw new \InvalidArgumentException('Source target key belongs to another platform: '.$key);
            }
            if ($row->nullableString('retired_at') !== null) {
                $retiredDeclared[] = $key;

                continue;
            }
            $active[$key] = $row->string('id');
            if (self::sameTarget($row, $target, $catalog->originBudget($target->origin, $policy->originBudget))) {
                $unchanged++;

                continue;
            }
            DB::update(
                'UPDATE prc_source_targets
                 SET canonical_origin = ?, capability = ?, endpoint_policy = ?::jsonb, endpoint_policy_schema_version = ?, policy_version = policy_version + 1
                 WHERE id = ?',
                [$target->origin->value(), $target->capability, self::json($target->endpointPolicy($catalog->originBudget($target->origin, $policy->originBudget))), SourceTargetDefinition::ENDPOINT_POLICY_SCHEMA_VERSION, $row->string('id')],
            );
            $updated++;
        }
        foreach ($rows as $key => $row) {
            if (isset($catalog->targets[$key]) || $row->int('platform_id') !== $platformId || $row->nullableString('retired_at') !== null) {
                continue;
            }
            // Retired target сохраняет ledger references; новые grants/claimSend его отвергают.
            DB::update('UPDATE prc_source_targets SET retired_at = clock_timestamp(), policy_version = policy_version + 1 WHERE id = ?', [$row->string('id')]);
            $retired++;
        }

        $routes = array_map(static fn (LedgerRow $route): string => $route->string('id'), LedgerRow::all('SELECT id FROM prc_network_routes ORDER BY id'));
        [$budgetsCreated, $drifted] = self::ensureBudgets(self::declaredBudgets($catalog, $policy, $routes));
        $health = self::ensureHealthRows($active, $routes);

        $report = new CatalogSyncReport(
            platformCode: $catalog->platformCode,
            targetsCreated: $created,
            targetsUpdated: $updated,
            targetsRetired: $retired,
            targetsUnchanged: $unchanged,
            budgetsCreated: $budgetsCreated,
            driftedBudgets: $drifted,
            healthRowsCreated: $health,
            retiredDeclared: $retiredDeclared,
        );
        if ($report->changed()) {
            $this->audit($system, 'network_catalog_sync', ActorKind::System, null, [
                'platform' => $catalog->platformCode,
                'targets_created' => $created,
                'targets_updated' => $updated,
                'targets_retired' => $retired,
                'budgets_created' => $budgetsCreated,
                'health_rows_created' => $health,
            ], $eventId, $projectionMessageId);
        }

        return $report;
    }

    /**
     * Audited operator override rate/burst/inflight одного budget scope. Range validation до
     * locks; expected policy_version — optimistic fence. tat/send_tat/not_before не меняются:
     * уже потраченный rate debt не возвращается и не сбрасывается.
     */
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
        $reason = self::reason($reason);
        if (! $policy->withinBounds($budget) || (str_starts_with($scopeKey, 'probe:') && $budget->maxInflight !== 1)) {
            throw new \InvalidArgumentException('Budget override is outside outbound.override_bounds.');
        }
        $system = self::lockSystemAuthority();
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

    /** Explicit audited retire: row и история остаются, новые permits/claimSend запрещены. */
    public function retireTarget(
        ProcessUuid $targetId,
        int $expectedVersion,
        ?int $actorId,
        string $reason,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): NetworkChangeResult {
        CallerTransaction::require('Source target retire');
        $reason = self::reason($reason);
        $system = self::lockSystemAuthority();
        $row = LedgerRow::one('SELECT '.self::TARGET_COLUMNS.' FROM prc_source_targets WHERE id = ? FOR UPDATE', [$targetId->value]);
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

    /** Cross-platform aliases share authority and must declare the same physical default. */
    private static function validateOriginDefaults(int $platformId, SourceTargetDefinitions $catalog, OutboundPolicy $policy): void
    {
        foreach ($catalog->origins() as $origin) {
            $budget = $catalog->originBudget($origin, $policy->originBudget);
            if (! $policy->withinBounds($budget)) {
                throw new \InvalidArgumentException('Catalog origin budget is outside outbound.override_bounds: '.$origin->value());
            }
            foreach (LedgerRow::all(
                'SELECT endpoint_policy::text AS endpoint_policy FROM prc_source_targets WHERE canonical_origin = ? AND platform_id <> ? AND retired_at IS NULL',
                [$origin->value(), $platformId],
            ) as $other) {
                $declared = json_decode($other->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($declared) || self::canonical($declared['origin_budget'] ?? null) !== self::canonical($budget->toArray())) {
                    throw new \InvalidArgumentException('Conflicting origin budget defaults for '.$origin->value());
                }
            }
        }
    }

    /**
     * Origin, probe и route-origin scopes каждого origin в byte-sorted порядке. Aliases одного
     * origin дают один и тот же scope key — квота не умножается.
     *
     * @param  list<string>  $routes
     * @return array<string, OriginBudgetPolicy>
     */
    private static function declaredBudgets(SourceTargetDefinitions $catalog, OutboundPolicy $policy, array $routes): array
    {
        $budgets = [];
        foreach ($catalog->origins() as $origin) {
            $budgets[$origin->budgetScopeKey()] = $catalog->originBudget($origin, $policy->originBudget);
            $budgets[$origin->probeScopeKey()] = $policy->probeBudget;
            foreach ($routes as $route) {
                $budgets[$origin->routeScopeKey($route)] = $policy->routeOriginBudget;
            }
        }
        ksort($budgets, SORT_STRING);

        return $budgets;
    }

    /**
     * INSERT отсутствующих budget rows; существующие не трогаются (override/debt сохраняются).
     *
     * @param  array<string, OriginBudgetPolicy>  $budgets
     * @return array{0: int, 1: list<string>}
     */
    private static function ensureBudgets(array $budgets): array
    {
        if ($budgets === []) {
            return [0, []];
        }
        $created = [];
        foreach ($budgets as $scopeKey => $budget) {
            $inserted = DB::select(
                'INSERT INTO prc_network_budgets (id, scope_key, emission_interval_us, burst, max_inflight, policy_version)
                 VALUES (?, ?, ?, ?, ?, 1) ON CONFLICT (scope_key) DO NOTHING RETURNING id',
                [self::uuid(), $scopeKey, $budget->emissionIntervalUs(), $budget->burst, $budget->maxInflight],
            );
            if ($inserted !== []) {
                $created[$scopeKey] = true;
            }
        }
        $keys = array_keys($budgets);
        $drifted = [];
        foreach (LedgerRow::all(
            'SELECT scope_key, emission_interval_us, burst, max_inflight FROM prc_network_budgets
             WHERE scope_key IN ('.LedgerRow::placeholders(count($keys)).') ORDER BY scope_key COLLATE "C"',
            $keys,
        ) as $row) {
            $scopeKey = $row->string('scope_key');
            if (! isset($created[$scopeKey]) && ! $budgets[$scopeKey]->matchesStored($row->int('emission_interval_us'), $row->int('burst'), $row->int('max_inflight'))) {
                $drifted[] = $scopeKey;
            }
        }

        return [count($created), $drifted];
    }

    /**
     * Начальные health rows: target/route/route-target unknown, epoch 1. Существующие rows
     * (их state/epoch/история) не меняются. Adapter health требует adapter/contract version
     * Definition и создаётся P2.9–P2.12.
     *
     * @param  array<string, string>  $targets  key → id
     * @param  list<string>  $routes
     */
    private static function ensureHealthRows(array $targets, array $routes): int
    {
        ksort($targets, SORT_STRING);
        $created = 0;
        foreach ($targets as $targetId) {
            $created += count(DB::select(
                "INSERT INTO prc_target_health (id, target_id, state, availability, health_epoch, state_version, recent_openings)
                 VALUES (?, ?, 'unknown', 'unknown', 1, 1, 0) ON CONFLICT (target_id) DO NOTHING RETURNING id",
                [self::uuid(), $targetId],
            ));
        }
        foreach ($routes as $routeId) {
            $created += count(DB::select(
                "INSERT INTO prc_route_health (id, route_id, state, health_epoch, state_version, recent_openings, consecutive_failures)
                 VALUES (?, ?, 'unknown', 1, 1, 0, 0) ON CONFLICT (route_id) DO NOTHING RETURNING id",
                [self::uuid(), $routeId],
            ));
            foreach ($targets as $targetId) {
                $created += count(DB::select(
                    "INSERT INTO prc_route_target_health (id, route_id, target_id, state, health_epoch, state_version, recent_openings, consecutive_failures)
                     VALUES (?, ?, ?, 'unknown', 1, 1, 0, 0) ON CONFLICT (route_id, target_id) DO NOTHING RETURNING id",
                    [self::uuid(), $routeId, $targetId],
                ));
            }
        }

        return $created;
    }

    /**
     * Bump control_version `system` + control transition в той же tx, что mutation.
     *
     * @param  array<string, int|string>  $details
     */
    private function audit(
        LedgerRow $system,
        string $reasonCode,
        ActorKind $actor,
        ?int $actorId,
        array $details,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): void {
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

    private static function lockSystemAuthority(): LedgerRow
    {
        return LedgerRow::one("SELECT id, desired_mode, control_version FROM prc_control_scopes WHERE scope_key = 'system' FOR UPDATE")
            ?? throw new \LogicException('Network catalog mutation requires the materialized system authority.');
    }

    /** @return array<string, LedgerRow> */
    private static function lockTargets(int $platformId): array
    {
        $rows = [];
        foreach (LedgerRow::all('SELECT '.self::TARGET_COLUMNS.' FROM prc_source_targets WHERE platform_id = ? ORDER BY target_key COLLATE "C" FOR UPDATE', [$platformId]) as $row) {
            $rows[$row->string('target_key')] = $row;
        }

        return $rows;
    }

    private static function sameTarget(LedgerRow $row, SourceTargetDefinition $target, OriginBudgetPolicy $originBudget): bool
    {
        return $row->string('canonical_origin') === $target->origin->value()
            && $row->string('capability') === $target->capability
            && $row->int('endpoint_policy_schema_version') === SourceTargetDefinition::ENDPOINT_POLICY_SCHEMA_VERSION
            && self::canonical(json_decode($row->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR)) === self::canonical($target->endpointPolicy($originBudget));
    }

    /** jsonb переупорядочивает ключи объектов: сравниваем по отсортированным ключам, списки как есть. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_LIMIT || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('Network authority change requires a short nonempty reason.');
        }

        return $reason;
    }

    private static function uuid(): string
    {
        return Str::lower((string) Str::uuid());
    }
}
