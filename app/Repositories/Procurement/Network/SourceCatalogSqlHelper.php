<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Repositories\Procurement\Process\Support\LedgerRow;
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
