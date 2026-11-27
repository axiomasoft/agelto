<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Definition;

/**
 * Typed collection source targets одной площадки. Catalog validator (pure, без DB/network I/O):
 * уникальные keys и согласованные defaults одного physical origin — конфликтующие объявления
 * budget у aliases одного origin отвергаются, а не выбираются молча.
 */
final readonly class SourceTargetDefinitions
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
    public function __construct(public string $platformCode, array $targets)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $platformCode) !== 1) {
            throw new \InvalidArgumentException('Invalid platform code for source catalog.');
        }
        $byKey = [];
        $budgets = [];
        $routeBudgets = [];
        $egressBudgets = [];
        foreach ($targets as $target) {
            if ($target->adapterContract !== null && $target->adapterContract->platformCode !== $platformCode) {
                throw new \InvalidArgumentException('Adapter contract belongs to another platform.');
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

    /** Объявленный default для одного прокси/маршрута на origin либо общий default политики. */
    public function routeOriginBudget(CanonicalOrigin $origin, OriginBudgetPolicy $default): OriginBudgetPolicy
    {
        return $this->declaredRouteBudgets[$origin->value()] ?? $default;
    }

    /** Объявленный default для одного внешнего IP (egress_id) на origin, если задан. */
    public function egressOriginBudget(CanonicalOrigin $origin): ?OriginBudgetPolicy
    {
        return $this->declaredEgressBudgets[$origin->value()] ?? null;
    }
}
