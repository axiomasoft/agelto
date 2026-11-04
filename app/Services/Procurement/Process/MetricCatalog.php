<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\DiscoveryOutcome;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;

/**
 * Закрытый каталог counter-серий и их измерений. Dimension — закрытые
 * перечисления либо ограниченный platform code; IDs, URL, hashes и произвольный текст отвергаются,
 * поэтому число серий ограничено каталогом. Ключ измерения — канонический versioned tuple
 * `v1;name=value;…` в порядке каталога, не JSON.
 */
final class MetricCatalog
{
    private const string VERSION = 'v1';

    /** platform: канонический код площадки либо manual; формат проверяется перед созданием dimension. */
    private const string PLATFORM_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** @var list<string> */
    private const array INCIDENT_STATES = ['open', 'acknowledged', 'resolved'];

    /** @return list<string> */
    public static function metrics(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Канонический ключ измерения серии; любое значение вне каталога — ProjectionSchemaError.
     *
     * @param  array<string, string>  $dimensions
     */
    public static function dimensionKey(string $metric, array $dimensions): string
    {
        $definition = self::definitions()[$metric] ?? throw new ProjectionSchemaError('metric_unknown');
        if (array_keys($dimensions) !== array_keys($definition)) {
            throw new ProjectionSchemaError('dimension_set_invalid');
        }
        $parts = [self::VERSION];
        foreach ($definition as $name => $allowed) {
            $value = $dimensions[$name];
            $valid = $allowed === null
                ? ($value === 'manual' || preg_match(self::PLATFORM_PATTERN, $value) === 1)
                : in_array($value, $allowed, true);
            if (! $valid) {
                throw new ProjectionSchemaError('dimension_value_invalid');
            }
            $parts[] = $name.'='.$value;
        }

        return implode(';', $parts);
    }

    /** Платформа cohort work: `manual` либо `platform:<code>`; иное — вне каталога. */
    public static function platformOf(string $cohortKey): string
    {
        if ($cohortKey === 'manual') {
            return 'manual';
        }
        if (preg_match('/^platform:([a-z][a-z0-9_]{0,31})$/D', $cohortKey, $match) !== 1) {
            throw new ProjectionSchemaError('cohort_invalid');
        }

        return $match[1];
    }

    /** Детерминированное описание серий для catalog_hash reducer. */
    public static function signature(string $metric): string
    {
        $definition = self::definitions()[$metric] ?? throw new ProjectionSchemaError('metric_unknown');
        $parts = [$metric];
        foreach ($definition as $name => $allowed) {
            $parts[] = $name.'='.($allowed === null ? 'platform' : implode('|', $allowed));
        }

        return implode(';', $parts);
    }

    /**
     * Серия => измерение => допустимые значения (null — ограниченный platform code).
     *
     * @return array<string, array<string, list<string>|null>>
     */
    private static function definitions(): array
    {
        $stages = self::values(StageCode::cases());

        return [
            'work_transition_total' => ['platform' => null, 'stage' => $stages, 'state' => self::values(WorkState::cases())],
            'stage_deferred_total' => ['platform' => null, 'stage' => $stages, 'reason' => self::values(DeferralReason::cases())],
            // Run transition пишет discovery_outcome: collecting при создании, complete/incomplete после closure/cancel.
            'run_transition_total' => ['state' => self::values(DiscoveryOutcome::cases())],
            'control_transition_total' => ['mode' => self::values(ControlMode::cases())],
            'incident_transition_total' => ['state' => self::INCIDENT_STATES],
        ];
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }
}
