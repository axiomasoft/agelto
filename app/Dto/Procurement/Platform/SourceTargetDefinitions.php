<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

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

    /** @param list<SourceTargetDefinition> $targets */
    public function __construct(public string $platformCode, array $targets)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $platformCode) !== 1) {
            throw new \InvalidArgumentException('Invalid platform code for source catalog.');
        }
        $byKey = [];
        $budgets = [];
        foreach ($targets as $target) {
            if ($target->adapterContract !== null && $target->adapterContract->platformCode !== $platformCode) {
                throw new \InvalidArgumentException('Adapter contract belongs to another platform.');
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
