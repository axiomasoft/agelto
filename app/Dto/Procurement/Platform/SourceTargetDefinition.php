<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

/**
 * Logical source target площадки (platform-transport#s02): key + capability + canonical origin +
 * endpoint request profiles. Key уникален внутри platform в `prc_source_targets`. Origin budget default опционален: null —
 * общий default OutboundPolicy. Physical budget принадлежит origin, не target.
 */
final readonly class SourceTargetDefinition
{
    public const int ENDPOINT_POLICY_SCHEMA_VERSION = 1;

    /** @var array<string, RequestProfile> */
    public array $profiles;

    /** @param list<RequestProfile> $profiles */
    public function __construct(
        public string $key,
        public string $capability,
        public CanonicalOrigin $origin,
        array $profiles,
        public ?OriginBudgetPolicy $originBudget = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid source target key.');
        }
        if (preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $capability) !== 1) {
            throw new \InvalidArgumentException('Invalid source target capability: '.$key);
        }
        if ($profiles === []) {
            throw new \InvalidArgumentException('Source target requires at least one request profile: '.$key);
        }
        $byCode = [];
        foreach ($profiles as $profile) {
            if (isset($byCode[$profile->code])) {
                throw new \InvalidArgumentException('Duplicate request profile '.$profile->code.' in target '.$key);
            }
            $byCode[$profile->code] = $profile;
        }
        ksort($byCode, SORT_STRING);
        $this->profiles = $byCode;
    }

    /**
     * Endpoint policy JSON boundary; profiles в стабильном порядке кода — repeat sync сравнивает
     * эквивалентные definitions без ложного version bump.
     *
     * @return array{origin: string, origin_budget: array{rate_per_minute: int, burst: int, max_inflight: int}, profiles: list<array<string, mixed>>}
     */
    public function endpointPolicy(OriginBudgetPolicy $originBudget): array
    {
        return [
            'origin' => $this->origin->value(),
            'origin_budget' => $originBudget->toArray(),
            'profiles' => array_values(array_map(static fn (RequestProfile $profile): array => $profile->toArray(), $this->profiles)),
        ];
    }

    public function profile(string $code): RequestProfile
    {
        return $this->profiles[$code] ?? throw new \InvalidArgumentException('Unknown request profile '.$code.' for target '.$this->key);
    }
}
