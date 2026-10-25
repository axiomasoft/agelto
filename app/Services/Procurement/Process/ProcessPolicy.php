<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\LanePolicy;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Enums\Procurement\Process\ScopeKind;
use App\Enums\Procurement\Process\StageCode;
use Illuminate\Contracts\Config\Repository;

/**
 * Boot-validated process policy v1 из `config/process.php`. Конечные typed таблицы
 * required_lanes(stage) и control scope stage (execution#s11): parent/child lanes действуют
 * одновременно; acquisition и documents controls раздельны. Неполная или противоречивая
 * конфигурация отвергается с ключом config, а не заменяется default.
 */
final readonly class ProcessPolicy
{
    /** @var array<string, list<string>> stage → required lane keys, parent перед child */
    private const array REQUIRED_LANES = [
        'listing' => ['acquisition', 'acquisition.listing'],
        'detail' => ['acquisition', 'acquisition.detail'],
        'status_refresh' => ['acquisition', 'acquisition.status_refresh'],
        'download' => ['download'],
        'prepare' => ['prepare'],
        'screening' => ['analysis', 'analysis.screening'],
        'extraction' => ['analysis', 'analysis.extraction'],
        'publication' => ['publication'],
    ];

    /** @var array<string, ScopeKind> stage → purpose root его control scope */
    private const array STAGE_PURPOSES = [
        'listing' => ScopeKind::Acquisition,
        'detail' => ScopeKind::Acquisition,
        'status_refresh' => ScopeKind::Acquisition,
        'download' => ScopeKind::Documents,
        'prepare' => ScopeKind::Documents,
        'screening' => ScopeKind::Documents,
        'extraction' => ScopeKind::Documents,
        'publication' => ScopeKind::Documents,
    ];

    public int $policyVersion;

    public int $maxHandlerFaultsPerWorkVersion;

    /** @var array<string, LanePolicy> */
    private array $lanes;

    public function __construct(Repository $config)
    {
        $this->policyVersion = self::positiveInteger($config->get('process.policy_version'), 'process.policy_version');
        $this->maxHandlerFaultsPerWorkVersion = self::positiveInteger(
            $config->get('process.max_handler_faults_per_work_version'),
            'process.max_handler_faults_per_work_version',
        );
        $this->lanes = self::lanePolicies($config->get('process.lanes'));
    }

    /** @return list<LanePolicy> parent перед child, стабильный порядок ключей */
    public function lanes(): array
    {
        $lanes = array_values($this->lanes);
        usort($lanes, static fn (LanePolicy $a, LanePolicy $b): int => [$a->parentKey !== null, $a->key] <=> [$b->parentKey !== null, $b->key]);

        return $lanes;
    }

    /** @return list<string> */
    public function requiredLanes(StageCode $stage): array
    {
        return self::REQUIRED_LANES[$stage->value];
    }

    /**
     * Полная цепочка scopes stage: system → purpose root → stage scope.
     *
     * @return list<ScopeDefinition>
     */
    public function stageScopes(StageCode $stage): array
    {
        $purpose = self::STAGE_PURPOSES[$stage->value];

        return [
            new ScopeDefinition(ScopeKind::System, ScopeKind::System->value, null),
            new ScopeDefinition($purpose, $purpose->value, ScopeKind::System->value),
            new ScopeDefinition(ScopeKind::Stage, $purpose->value.':stage:'.$stage->value, $purpose->value),
        ];
    }

    /** @return array<string, LanePolicy> */
    private static function lanePolicies(mixed $lanes): array
    {
        if (! is_array($lanes) || $lanes === []) {
            throw new \InvalidArgumentException('Invalid process policy: process.lanes');
        }
        $policies = [];
        foreach ($lanes as $key => $lane) {
            $path = 'process.lanes.'.$key;
            if (! is_string($key) || ! is_array($lane) || array_keys($lane) !== ['parent', 'max_outstanding', 'max_running', 'low_watermark', 'refill_max']
                || ! (is_string($lane['parent']) || $lane['parent'] === null)) {
                throw new \InvalidArgumentException('Invalid process policy: '.$path);
            }
            try {
                $policies[$key] = new LanePolicy(
                    key: $key,
                    parentKey: $lane['parent'],
                    maxOutstanding: self::positiveInteger($lane['max_outstanding'], $path.'.max_outstanding'),
                    maxRunning: self::positiveInteger($lane['max_running'], $path.'.max_running'),
                    lowWatermark: self::integer($lane['low_watermark'], $path.'.low_watermark'),
                    refillMax: self::positiveInteger($lane['refill_max'], $path.'.refill_max'),
                );
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException('Invalid process policy: '.$path, previous: $exception);
            }
        }
        foreach ($policies as $policy) {
            $parent = $policy->parentKey === null ? null : ($policies[$policy->parentKey] ?? null);
            if ($policy->parentKey !== null && ($parent === null || $parent->parentKey !== null)) {
                throw new \InvalidArgumentException('Invalid process policy: process.lanes.'.$policy->key.'.parent');
            }
        }
        foreach (self::REQUIRED_LANES as $required) {
            foreach ($required as $key) {
                if (! isset($policies[$key])) {
                    throw new \InvalidArgumentException('Invalid process policy: process.lanes.'.$key);
                }
            }
        }

        return $policies;
    }

    private static function positiveInteger(mixed $value, string $path): int
    {
        $integer = self::integer($value, $path);
        if ($integer < 1) {
            throw new \InvalidArgumentException('Invalid process policy: '.$path);
        }

        return $integer;
    }

    private static function integer(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('Invalid process policy: '.$path);
        }

        return $value;
    }
}
