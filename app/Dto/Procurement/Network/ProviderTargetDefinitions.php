<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;

/**
 * Typed provider-owned target collection; no procurement platform identity. Catalog validator (pure, без DB/network I/O):
 * уникальные keys и согласованные defaults одного physical origin — конфликтующие объявления
 * budget у aliases одного origin отвергаются, а не выбираются молча.
 */
final readonly class ProviderTargetDefinitions
{
    /** @var array<string, SourceTargetDefinition> */
    public array $targets;

    /** @var array<string, OriginBudgetPolicy> origin value → explicitly declared budget */
    private array $declaredBudgets;

    /** @param list<SourceTargetDefinition> $targets */
    public function __construct(public ProviderKey $providerKey, array $targets)
    {
        $byKey = [];
        $budgets = [];
        foreach ($targets as $target) {
            if ($target->adapterContract !== null) {
                throw new \InvalidArgumentException('Provider targets cannot carry a platform parser contract.');
            }
            if (! str_starts_with($target->key, 'provider:'.$providerKey->value.':')) {
                throw new \InvalidArgumentException('Provider target key must carry its declared owner.');
            }
            if (isset($byKey[$target->key])) {
                throw new \InvalidArgumentException('Duplicate source target key: '.$target->key);
            }
            $byKey[$target->key] = $target;
            if ($target->originBudget === null) {
                continue;
            }
            $origin = $target->origin->value();
            if (isset($budgets[$origin]) && ! $budgets[$origin]->equals($target->originBudget)) {
                throw new \InvalidArgumentException('Conflicting origin budget defaults for '.$origin.' (target '.$target->key.')');
            }
            $budgets[$origin] = $target->originBudget;
        }
        ksort($byKey, SORT_STRING);
        $this->targets = $byKey;
        $this->declaredBudgets = $budgets;
    }

    /**
     * Уникальные origins в стабильном порядке значения.
     *
     * @return list<CanonicalOrigin>
     */
    public function origins(): array
    {
        $origins = [];
        foreach ($this->targets as $target) {
            $origins[$target->origin->value()] = $target->origin;
        }
        ksort($origins, SORT_STRING);

        return array_values($origins);
    }

    /** Объявленный default origin либо общий default политики. */
    public function originBudget(CanonicalOrigin $origin, OriginBudgetPolicy $default): OriginBudgetPolicy
    {
        return $this->declaredBudgets[$origin->value()] ?? $default;
    }
}
