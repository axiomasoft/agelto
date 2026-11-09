<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\DB;

/**
 * SQL-хелперы, блокировки и канонизация для SourceCatalogRepository.
 */
final readonly class SourceCatalogSqlHelper
{
    public const int REASON_LIMIT = 200;

    public const string TARGET_COLUMNS = 'id, platform_id, provider_key, target_key, canonical_origin, capability, endpoint_policy::text AS endpoint_policy,
        endpoint_policy_schema_version, policy_version, retired_at::text AS retired_at';

    /** @return list<LedgerRow> */
    public static function probeCandidates(?string $afterId, int $limit = 8): array
    {
        return LedgerRow::all("SELECT t.id, t.platform_id, t.provider_key, t.target_key,
                t.endpoint_policy->>'probe_url' AS probe_url
            FROM prc_source_targets t WHERE t.retired_at IS NULL AND t.endpoint_policy->>'probe_url' IS NOT NULL
                AND (?::uuid IS NULL OR t.id > ?::uuid)
                AND (EXISTS (SELECT 1 FROM prc_target_health h WHERE h.target_id = t.id AND h.state <> 'closed'
                    AND (h.open_until IS NULL OR h.open_until <= clock_timestamp())
                    AND (h.probe_until IS NULL OR h.probe_until <= clock_timestamp()))
                    OR EXISTS (SELECT 1 FROM prc_route_target_health h WHERE h.target_id = t.id AND h.state <> 'closed'
                        AND (h.open_until IS NULL OR h.open_until <= clock_timestamp())
                        AND (h.probe_until IS NULL OR h.probe_until <= clock_timestamp()))
                    OR EXISTS (SELECT 1 FROM prc_route_health h JOIN prc_route_target_health rt ON rt.route_id = h.route_id
                        WHERE rt.target_id = t.id AND h.state <> 'closed'
                        AND (h.open_until IS NULL OR h.open_until <= clock_timestamp())
                        AND (h.probe_until IS NULL OR h.probe_until <= clock_timestamp())))
            ORDER BY t.id LIMIT ?", [$afterId, $afterId, $limit]);
    }

    /**
     * @param  array<string, LedgerRow>  $rows
     * @return array{created: int, updated: int, retired: int, unchanged: int, retiredDeclared: list<string>, active: array<string, string>}
     */
    public static function syncTargetRows(
        ?int $platformId,
        ?string $providerKey,
        SourceTargetDefinitions|ProviderTargetDefinitions $catalog,
        OutboundPolicy $policy,
        array $rows,
        AdapterHealthRepository $adapters,
    ): array {
        $created = $updated = $retired = $unchanged = 0;
        $retiredDeclared = [];
        $active = [];
        foreach ($catalog->targets as $key => $target) {
            if ($target->adapterContract !== null) {
                $adapters->ensure($target->adapterContract);
            }
            $effectiveOriginBudget = self::effectiveOriginBudget($platformId, $catalog, $target->origin, $policy);
            $row = $rows[$key] ?? null;
            if ($row === null) {
                $id = ProcessUuid::random()->value;
                DB::insert(
                    'INSERT INTO prc_source_targets
                       (id, platform_id, provider_key, target_key, canonical_origin, capability, endpoint_policy, endpoint_policy_schema_version, policy_version)
                     VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, 1)',
                    [$id, $platformId, $providerKey, $key, $target->origin->value(), $target->capability, self::json($target->endpointPolicy($effectiveOriginBudget)), $target->endpointPolicySchemaVersion()],
                );
                $active[$key] = $id;
                $created++;

                continue;
            }
            if ($row->nullableInt('platform_id') !== $platformId || $row->nullableString('provider_key') !== $providerKey) {
                throw new \InvalidArgumentException('Source target key belongs to another platform: '.$key);
            }
            if ($row->nullableString('retired_at') !== null) {
                $retiredDeclared[] = $key;

                continue;
            }
            $active[$key] = $row->string('id');
            if (self::sameTarget($row, $target, $effectiveOriginBudget)) {
                $unchanged++;

                continue;
            }
            DB::update(
                'UPDATE prc_source_targets
                 SET canonical_origin = ?, capability = ?, endpoint_policy = ?::jsonb, endpoint_policy_schema_version = ?, policy_version = policy_version + 1
                 WHERE id = ?',
                [$target->origin->value(), $target->capability, self::json($target->endpointPolicy($effectiveOriginBudget)), $target->endpointPolicySchemaVersion(), $row->string('id')],
            );
            $updated++;
        }
        foreach ($rows as $key => $row) {
            if (isset($catalog->targets[$key]) || $row->nullableInt('platform_id') !== $platformId || $row->nullableString('provider_key') !== $providerKey || $row->nullableString('retired_at') !== null) {
                continue;
            }
            DB::update('UPDATE prc_source_targets SET retired_at = clock_timestamp(), policy_version = policy_version + 1 WHERE id = ?', [$row->string('id')]);
            $retired++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'retired' => $retired,
            'unchanged' => $unchanged,
            'retiredDeclared' => $retiredDeclared,
            'active' => $active,
        ];
    }

    /** Cross-platform aliases share authority and must declare the same physical default. */
    public static function validateOriginDefaults(?int $platformId, SourceTargetDefinitions|ProviderTargetDefinitions $catalog, OutboundPolicy $policy): void
    {
        foreach ($catalog->origins() as $origin) {
            $budget = self::effectiveOriginBudget($platformId, $catalog, $origin, $policy);
            $routeBudget = $catalog->routeOriginBudget($origin, $policy->routeOriginBudget);
            $egressBudget = $catalog->egressOriginBudget($origin);
            if (! $policy->withinBounds($budget)
                || ! $policy->withinBounds($routeBudget)
                || ($egressBudget !== null && ! $policy->withinBounds($egressBudget))) {
                throw new \InvalidArgumentException('Catalog origin budget is outside outbound.override_bounds: '.$origin->value());
            }
            foreach (LedgerRow::all(
                'SELECT endpoint_policy::text AS endpoint_policy FROM prc_source_targets WHERE canonical_origin = ? AND NOT (platform_id IS NOT DISTINCT FROM ?::bigint AND provider_key IS NOT DISTINCT FROM ?::text) AND retired_at IS NULL',
                [$origin->value(), $platformId, $catalog instanceof ProviderTargetDefinitions ? $catalog->providerKey->value : null],
            ) as $other) {
                $declared = json_decode($other->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($declared) || self::canonical($declared['origin_budget'] ?? null) !== self::canonical($budget->toArray())) {
                    throw new \InvalidArgumentException('Conflicting origin budget defaults for '.$origin->value());
                }
            }
        }
    }

    public static function effectiveOriginBudget(
        ?int $platformId,
        SourceTargetDefinitions|ProviderTargetDefinitions $catalog,
        CanonicalOrigin $origin,
        OutboundPolicy $policy,
    ): OriginBudgetPolicy {
        if ($catalog instanceof ProviderTargetDefinitions && ! $catalog->hasDeclaredOriginBudget($origin)) {
            $existing = LedgerRow::one(
                'SELECT endpoint_policy::text AS endpoint_policy FROM prc_source_targets WHERE canonical_origin = ? AND platform_id IS NOT NULL AND retired_at IS NULL ORDER BY id LIMIT 1',
                [$origin->value()],
            );
            if ($existing !== null) {
                $declared = json_decode($existing->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($declared) && is_array($declared['origin_budget'] ?? null)) {
                    return OriginBudgetPolicy::fromArray($declared['origin_budget']);
                }
            }
        }

        return $catalog->originBudget($origin, $policy->originBudget);
    }

    /**
     * @param  list<string>  $routes
     * @param  array<string, string>  $routeEgressIds  route_id -> egress_id
     * @return array<string, OriginBudgetPolicy>
     */
    public static function declaredBudgets(
        SourceTargetDefinitions|ProviderTargetDefinitions $catalog,
        OutboundPolicy $policy,
        array $routes,
        array $routeEgressIds = [],
        ?int $platformId = null,
    ): array {
        $egressCounts = array_count_values($routeEgressIds);
        $budgets = [];
        foreach ($catalog->origins() as $origin) {
            $budgets[$origin->budgetScopeKey()] = self::effectiveOriginBudget($platformId, $catalog, $origin, $policy);
            $budgets[$origin->probeScopeKey()] = $policy->probeBudget;
            $routeBudget = $catalog->routeOriginBudget($origin, $policy->routeOriginBudget);
            $egressBudget = $catalog->egressOriginBudget($origin) ?? $routeBudget;
            foreach ($routes as $route) {
                $budgets[$origin->routeScopeKey($route)] = $routeBudget;
                $egressId = $routeEgressIds[$route] ?? null;
                if ($egressId !== null && ($egressCounts[$egressId] ?? 0) > 1) {
                    $budgets[$origin->egressScopeKey($egressId)] = $egressBudget;
                }
            }
        }
        ksort($budgets, SORT_STRING);

        return $budgets;
    }

    /**
     * @param  array<string, OriginBudgetPolicy>  $budgets
     * @return array{0: int, 1: list<string>}
     */
    public static function ensureBudgets(array $budgets): array
    {
        if ($budgets === []) {
            return [0, []];
        }
        $created = [];
        foreach ($budgets as $scopeKey => $budget) {
            $inserted = DB::select(
                'INSERT INTO prc_network_budgets (id, scope_key, emission_interval_us, burst, max_inflight, policy_version)
                 VALUES (?, ?, ?, ?, ?, 1) ON CONFLICT (scope_key) DO NOTHING RETURNING id',
                [ProcessUuid::random()->value, $scopeKey, $budget->emissionIntervalUs(), $budget->burst, $budget->maxInflight],
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
     * @param  array<string, string>  $targets  key → id
     * @param  list<string>  $routes
     */
    public static function ensureHealthRows(array $targets, array $routes): int
    {
        ksort($targets, SORT_STRING);
        $created = 0;
        foreach ($targets as $targetId) {
            $created += count(DB::select(
                "INSERT INTO prc_target_health (id, target_id, state, availability, health_epoch, state_version, recent_openings)
                 VALUES (?, ?, 'unknown', 'unknown', 1, 1, 0) ON CONFLICT (target_id) DO NOTHING RETURNING id",
                [ProcessUuid::random()->value, $targetId],
            ));
        }
        foreach ($routes as $routeId) {
            $created += count(DB::select(
                "INSERT INTO prc_route_health (id, route_id, state, health_epoch, state_version, recent_openings, consecutive_failures)
                 VALUES (?, ?, 'unknown', 1, 1, 0, 0) ON CONFLICT (route_id) DO NOTHING RETURNING id",
                [ProcessUuid::random()->value, $routeId],
            ));
            foreach ($targets as $targetId) {
                $created += count(DB::select(
                    "INSERT INTO prc_route_target_health (id, route_id, target_id, state, health_epoch, state_version, recent_openings, consecutive_failures)
                     VALUES (?, ?, ?, 'unknown', 1, 1, 0, 0) ON CONFLICT (route_id, target_id) DO NOTHING RETURNING id",
                    [ProcessUuid::random()->value, $routeId, $targetId],
                ));
            }
        }

        return $created;
    }

    public static function lockSystemAuthority(): LedgerRow
    {
        return LedgerRow::one("SELECT id, desired_mode, control_version FROM prc_control_scopes WHERE scope_key = 'system' FOR UPDATE")
            ?? throw new \LogicException('Network catalog mutation requires the materialized system authority.');
    }

    /** @return array<string, LedgerRow> */
    public static function lockTargets(?int $platformId, ?string $providerKey): array
    {
        $rows = [];
        foreach (LedgerRow::all('SELECT '.self::TARGET_COLUMNS.' FROM prc_source_targets WHERE platform_id IS NOT DISTINCT FROM ?::bigint AND provider_key IS NOT DISTINCT FROM ?::text ORDER BY target_key COLLATE "C" FOR UPDATE', [$platformId, $providerKey]) as $row) {
            $rows[$row->string('target_key')] = $row;
        }

        return $rows;
    }

    public static function sameTarget(LedgerRow $row, SourceTargetDefinition $target, OriginBudgetPolicy $originBudget): bool
    {
        return $row->string('canonical_origin') === $target->origin->value()
            && $row->string('capability') === $target->capability
            && $row->int('endpoint_policy_schema_version') === $target->endpointPolicySchemaVersion()
            && self::canonical(json_decode($row->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR)) === self::canonical($target->endpointPolicy($originBudget));
    }

    public static function canonical(mixed $value): mixed
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
    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_LIMIT || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('Network authority change requires a short nonempty reason.');
        }

        return SecretRedactor::text($reason);
    }
}
