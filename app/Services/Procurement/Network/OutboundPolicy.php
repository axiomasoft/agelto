<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use Illuminate\Contracts\Config\Repository;

/**
 * Boot-validated outbound policy v1 из `config/outbound.php`. Чистое
 * чтение config: никакого DB/network I/O, поэтому routine boot/DI его безопасно строит. Неполная
 * или противоречивая конфигурация отвергается с точным ключом, а не заменяется default.
 */
final readonly class OutboundPolicy
{
    private const array ROOT_KEYS = ['policy_version', 'origin_budget', 'route_origin_budget', 'probe_budget', 'override_bounds', 'scheduling', 'direct_route', 'legacy_bridge', 'catalog_providers', 'routing', 'http', 'health', 'adapter_health'];

    private const array SCHEDULING_KEYS = ['max_inline_wait_seconds', 'max_pending_demands_per_origin', 'ticket_ttl_seconds', 'ticket_renew_seconds', 'permit_cleanup_reserve_seconds', 'status_capacity_percent'];

    public int $policyVersion;

    public OriginBudgetPolicy $originBudget;

    public OriginBudgetPolicy $routeOriginBudget;

    public OriginBudgetPolicy $probeBudget;

    /** @var array{rate_per_minute: array{min: int, max: int}, burst: array{min: int, max: int}, max_inflight: array{min: int, max: int}} */
    public array $overrideBounds;

    public int $maxInlineWaitSeconds;

    public int $maxPendingDemandsPerOrigin;

    public int $ticketTtlSeconds;

    public int $ticketRenewSeconds;

    public int $permitCleanupReserveSeconds;

    public int $statusCapacityPercent;

    public string $directEgressId;

    public ?string $directIndependenceGroup;

    public int $bridgeLeaseSeconds;

    public int $bridgeMaxDeadlineSeconds;

    /** @var list<string> */
    public array $catalogProviders;

    public string $defaultRouteProfile;

    public string $providerRouteProfile;

    public string $userAgent;

    public function __construct(Repository $config)
    {
        $root = $config->get('outbound');
        if (! is_array($root) || array_keys($root) !== self::ROOT_KEYS) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound');
        }
        new HealthPolicy($config);
        new AdapterHealthPolicy($config);
        $routing = $root['routing'];
        if (! is_array($routing) || array_keys($routing) !== ['default_profile', 'provider_profile']) {
            throw new \InvalidArgumentException('Invalid outbound.routing');
        }
        $this->defaultRouteProfile = self::label($routing['default_profile'], 'outbound.routing.default_profile');
        $this->providerRouteProfile = self::label($routing['provider_profile'], 'outbound.routing.provider_profile');
        $http = $root['http'];
        if (! is_array($http) || array_keys($http) !== ['user_agent', 'tls_bundles'] || ! is_string($http['user_agent'])
            || $http['user_agent'] === '' || preg_match('/[\r\n\x00]/', $http['user_agent']) || ! is_array($http['tls_bundles'])) {
            throw new \InvalidArgumentException('Invalid outbound.http');
        }
        $this->userAgent = $http['user_agent'];
        $this->policyVersion = self::positive($root['policy_version'], 'outbound.policy_version');
        $this->originBudget = self::budget($root['origin_budget'], 'outbound.origin_budget');
        $this->routeOriginBudget = self::budget($root['route_origin_budget'], 'outbound.route_origin_budget');
        $this->probeBudget = self::budget($root['probe_budget'], 'outbound.probe_budget');
        if ($this->probeBudget->maxInflight !== 1) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.probe_budget.max_inflight');
        }
        $this->overrideBounds = self::bounds($root['override_bounds']);
        foreach (['origin_budget' => $this->originBudget, 'route_origin_budget' => $this->routeOriginBudget, 'probe_budget' => $this->probeBudget] as $key => $budget) {
            if (! $this->withinBounds($budget)) {
                throw new \InvalidArgumentException('Invalid outbound policy: outbound.'.$key.' outside outbound.override_bounds');
            }
        }

        $scheduling = $root['scheduling'];
        if (! is_array($scheduling) || array_keys($scheduling) !== self::SCHEDULING_KEYS) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.scheduling');
        }
        $this->maxInlineWaitSeconds = self::positive($scheduling['max_inline_wait_seconds'], 'outbound.scheduling.max_inline_wait_seconds');
        $this->maxPendingDemandsPerOrigin = self::positive($scheduling['max_pending_demands_per_origin'], 'outbound.scheduling.max_pending_demands_per_origin');
        $this->ticketTtlSeconds = self::positive($scheduling['ticket_ttl_seconds'], 'outbound.scheduling.ticket_ttl_seconds');
        $this->ticketRenewSeconds = self::positive($scheduling['ticket_renew_seconds'], 'outbound.scheduling.ticket_renew_seconds');
        if ($this->ticketRenewSeconds >= $this->ticketTtlSeconds) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.scheduling.ticket_renew_seconds');
        }
        $this->permitCleanupReserveSeconds = self::positive($scheduling['permit_cleanup_reserve_seconds'], 'outbound.scheduling.permit_cleanup_reserve_seconds');
        $this->statusCapacityPercent = self::positive($scheduling['status_capacity_percent'], 'outbound.scheduling.status_capacity_percent');
        if ($this->statusCapacityPercent > 100) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.scheduling.status_capacity_percent');
        }

        if ($this->maxInlineWaitSeconds > 20 || $this->maxPendingDemandsPerOrigin > 64
            || $this->ticketTtlSeconds !== 5 || $this->ticketRenewSeconds > 1 || $this->permitCleanupReserveSeconds < 30 || $this->statusCapacityPercent > 30) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.scheduling exceeds contract bounds');
        }

        $direct = $root['direct_route'];
        if (! is_array($direct) || array_keys($direct) !== ['egress_id', 'independence_group']) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.direct_route');
        }
        $this->directEgressId = self::label($direct['egress_id'], 'outbound.direct_route.egress_id');
        $this->directIndependenceGroup = $direct['independence_group'] === null || $direct['independence_group'] === ''
            ? null
            : self::label($direct['independence_group'], 'outbound.direct_route.independence_group');

        $bridge = $root['legacy_bridge'];
        if (! is_array($bridge) || array_keys($bridge) !== ['lease_seconds', 'max_deadline_seconds']) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.legacy_bridge');
        }
        $this->bridgeLeaseSeconds = self::positive($bridge['lease_seconds'], 'outbound.legacy_bridge.lease_seconds');
        $this->bridgeMaxDeadlineSeconds = self::positive($bridge['max_deadline_seconds'], 'outbound.legacy_bridge.max_deadline_seconds');
        if ($this->bridgeLeaseSeconds > $this->bridgeMaxDeadlineSeconds) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.legacy_bridge.lease_seconds');
        }

        $providers = $root['catalog_providers'];
        if (! is_array($providers) || ! array_is_list($providers)) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.catalog_providers');
        }
        $list = [];
        foreach ($providers as $index => $provider) {
            if (! is_string($provider) || $provider === '' || in_array($provider, $list, true)) {
                throw new \InvalidArgumentException('Invalid outbound policy: outbound.catalog_providers.'.$index);
            }
            $list[] = $provider;
        }
        $this->catalogProviders = $list;
    }

    /** Override проходит range validation до любых locks/записей. */
    public function withinBounds(OriginBudgetPolicy $budget): bool
    {
        $bounds = $this->overrideBounds;

        return $budget->ratePerMinute >= $bounds['rate_per_minute']['min'] && $budget->ratePerMinute <= $bounds['rate_per_minute']['max']
            && $budget->burst >= $bounds['burst']['min'] && $budget->burst <= $bounds['burst']['max']
            && $budget->maxInflight >= $bounds['max_inflight']['min'] && $budget->maxInflight <= $bounds['max_inflight']['max'];
    }

    /** Status cap = floor(configured hourly origin capacity · percent). */
    public function statusHourlyCap(OriginBudgetPolicy $origin): int
    {
        return intdiv($origin->ratePerMinute * 60 * $this->statusCapacityPercent, 100);
    }

    private static function budget(mixed $value, string $path): OriginBudgetPolicy
    {
        if (! is_array($value) || array_keys($value) !== ['rate_per_minute', 'burst', 'max_inflight']) {
            throw new \InvalidArgumentException('Invalid outbound policy: '.$path);
        }

        return new OriginBudgetPolicy(
            ratePerMinute: self::positive($value['rate_per_minute'], $path.'.rate_per_minute'),
            burst: self::positive($value['burst'], $path.'.burst'),
            maxInflight: self::positive($value['max_inflight'], $path.'.max_inflight'),
        );
    }

    /** @return array{rate_per_minute: array{min: int, max: int}, burst: array{min: int, max: int}, max_inflight: array{min: int, max: int}} */
    private static function bounds(mixed $value): array
    {
        if (! is_array($value) || array_keys($value) !== ['rate_per_minute', 'burst', 'max_inflight']) {
            throw new \InvalidArgumentException('Invalid outbound policy: outbound.override_bounds');
        }
        $bounds = [];
        foreach (['rate_per_minute', 'burst', 'max_inflight'] as $key) {
            $path = 'outbound.override_bounds.'.$key;
            $range = $value[$key];
            if (! is_array($range) || array_keys($range) !== ['min', 'max']) {
                throw new \InvalidArgumentException('Invalid outbound policy: '.$path);
            }
            $min = self::positive($range['min'], $path.'.min');
            $max = self::positive($range['max'], $path.'.max');
            if ($min > $max || ($key !== 'rate_per_minute' && $max > 32767)) {
                throw new \InvalidArgumentException('Invalid outbound policy: '.$path);
            }
            $bounds[$key] = ['min' => $min, 'max' => $max];
        }

        return [
            'rate_per_minute' => $bounds['rate_per_minute'],
            'burst' => $bounds['burst'],
            'max_inflight' => $bounds['max_inflight'],
        ];
    }

    private static function positive(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('Invalid outbound policy: '.$path);
        }

        return $value;
    }

    private static function label(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid outbound policy: '.$path);
        }

        return $value;
    }
}
