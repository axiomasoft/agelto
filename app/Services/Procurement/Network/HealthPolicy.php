<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use Illuminate\Contracts\Config\Repository;

/** Configurable engineering defaults, not calibrated reliability claims. No I/O at boot. */
final readonly class HealthPolicy
{
    public int $globalFailures;

    public int $globalBase;

    public int $globalCap;

    public int $targetFailures;

    public int $targetSample;

    public int $targetPercent;

    public int $targetWindow;

    public int $targetBase;

    public int $targetCap;

    public int $stableDecay;

    public int $corroborationWindow;

    public int $originCooldown;

    public int $probeReserve;

    public int $wafCooldown;

    public function __construct(Repository $config)
    {
        $keys = ['globalFailures' => 'global_consecutive', 'globalBase' => 'global_base_seconds', 'globalCap' => 'global_cap_seconds',
            'targetFailures' => 'route_target_consecutive', 'targetSample' => 'route_target_min_sample', 'targetPercent' => 'route_target_failure_percent',
            'targetWindow' => 'route_target_window_seconds', 'targetBase' => 'route_target_base_seconds', 'targetCap' => 'route_target_cap_seconds',
            'stableDecay' => 'stable_decay_seconds', 'corroborationWindow' => 'corroboration_window_seconds',
            'originCooldown' => 'origin_cooldown_seconds', 'probeReserve' => 'probe_reserve_seconds'];
        $optional = ['waf_cooldown_seconds'];
        $data = $config->get('outbound.health');
        if (! is_array($data)
            || array_diff(array_values($keys), array_keys($data)) !== []
            || array_diff(array_keys($data), [...array_values($keys), ...$optional]) !== []) {
            throw new \InvalidArgumentException('Invalid outbound.health');
        }
        $this->globalFailures = self::positive($data['global_consecutive'] ?? null, 'global_consecutive');
        $this->globalBase = self::positive($data['global_base_seconds'] ?? null, 'global_base_seconds');
        $this->globalCap = self::positive($data['global_cap_seconds'] ?? null, 'global_cap_seconds');
        $this->targetFailures = self::positive($data['route_target_consecutive'] ?? null, 'route_target_consecutive');
        $this->targetSample = self::positive($data['route_target_min_sample'] ?? null, 'route_target_min_sample');
        $this->targetPercent = self::positive($data['route_target_failure_percent'] ?? null, 'route_target_failure_percent');
        $this->targetWindow = self::positive($data['route_target_window_seconds'] ?? null, 'route_target_window_seconds');
        $this->targetBase = self::positive($data['route_target_base_seconds'] ?? null, 'route_target_base_seconds');
        $this->targetCap = self::positive($data['route_target_cap_seconds'] ?? null, 'route_target_cap_seconds');
        $this->stableDecay = self::positive($data['stable_decay_seconds'] ?? null, 'stable_decay_seconds');
        $this->corroborationWindow = self::positive($data['corroboration_window_seconds'] ?? null, 'corroboration_window_seconds');
        $this->originCooldown = self::positive($data['origin_cooldown_seconds'] ?? null, 'origin_cooldown_seconds');
        $this->probeReserve = self::positive($data['probe_reserve_seconds'] ?? null, 'probe_reserve_seconds');
        $this->wafCooldown = array_key_exists('waf_cooldown_seconds', $data)
            ? self::positive($data['waf_cooldown_seconds'], 'waf_cooldown_seconds')
            : 330;
        if ($this->targetPercent > 100 || $this->targetSample < 2 || $this->globalCap < $this->globalBase
            || $this->targetCap < $this->targetBase || $this->probeReserve < 30) {
            throw new \InvalidArgumentException('Inconsistent outbound.health thresholds/cooldowns');
        }
    }

    private static function positive(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1 || $value > 86400) {
            throw new \InvalidArgumentException('Invalid outbound.health.'.$key);
        }

        return $value;
    }
}
