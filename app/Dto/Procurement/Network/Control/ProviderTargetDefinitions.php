<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Control;

use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;

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

    /** @var array<string, OriginBudgetPolicy> origin value → explicitly declared per-route budget */
    private array $declaredRouteBudgets;

    /** @var array<string, OriginBudgetPolicy> origin value → explicitly declared per-egress budget */
    private array $declaredEgressBudgets;

    /** @param list<SourceTargetDefinition> $targets */
    public function __construct(public ProviderKey $providerKey, array $targets)
    {
        $byKey = [];
        $budgets = [];
        $routeBudgets = [];
        $egressBudgets = [];
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
            $origin = $target->origin->value();
            if ($target->originBudget !== null) {
                if (isset($budgets[$origin]) && ! $budgets[$origin]->equals($target->originBudget)) {
                    throw new \InvalidArgumentException('Conflicting origin budget defaults for '.$origin.' (target '.$target->key.')');
                }
                $budgets[$origin] = $target->originBudget;
            }
            if ($target->routeOriginBudget !== null) {
                if (isset($routeBudgets[$origin]) && ! $routeBudgets[$origin]->equals($target->routeOriginBudget)) {
                    throw new \InvalidArgumentException('Conflicting route-origin budget defaults for '.$origin.' (target '.$target->key.')');
                }
                $routeBudgets[$origin] = $target->routeOriginBudget;
            }
            if ($target->egressOriginBudget !== null) {
                if (isset($egressBudgets[$origin]) && ! $egressBudgets[$origin]->equals($target->egressOriginBudget)) {
                    throw new \InvalidArgumentException('Conflicting egress-origin budget defaults for '.$origin.' (target '.$target->key.')');
                }
                $egressBudgets[$origin] = $target->egressOriginBudget;
            }
        }
        ksort($byKey, SORT_STRING);
        $this->targets = $byKey;
        $this->declaredBudgets = $budgets;
        $this->declaredRouteBudgets = $routeBudgets;
        $this->declaredEgressBudgets = $egressBudgets;
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

    public function hasDeclaredOriginBudget(CanonicalOrigin $origin): bool
    {
        return isset($this->declaredBudgets[$origin->value()]);
    }

    public function routeOriginBudget(CanonicalOrigin $origin, OriginBudgetPolicy $default): OriginBudgetPolicy
    {
        return $this->declaredRouteBudgets[$origin->value()] ?? $default;
    }

    public function egressOriginBudget(CanonicalOrigin $origin): ?OriginBudgetPolicy
    {
        return $this->declaredEgressBudgets[$origin->value()] ?? null;
    }
}
