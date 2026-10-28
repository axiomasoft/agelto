<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/**
 * Итог одного idempotent catalog sync площадки. Повтор без изменения definition: все счётчики
 * изменений 0, policy_version не растёт. driftedBudgets — существующие budgets, чьи значения
 * отличаются от definition default (оператор override или изменённый default): sync их не
 * перезаписывает, изменение применяется только явным audited override.
 * retiredDeclared — объявленные definition keys, уже retired: retired key — tombstone с историей,
 * sync его молча не оживляет (видимая configuration conflict, команда завершается ошибкой).
 */
final readonly class CatalogSyncReport
{
    /**
     * @param  list<string>  $driftedBudgets  scope keys
     * @param  list<string>  $retiredDeclared  target keys
     */
    public function __construct(
        public string $platformCode,
        public int $targetsCreated,
        public int $targetsUpdated,
        public int $targetsRetired,
        public int $targetsUnchanged,
        public int $budgetsCreated,
        public array $driftedBudgets,
        public int $healthRowsCreated,
        public array $retiredDeclared,
    ) {}

    public function changed(): bool
    {
        return $this->targetsCreated + $this->targetsUpdated + $this->targetsRetired + $this->budgetsCreated + $this->healthRowsCreated > 0;
    }
}
