<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Authority\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\Authority\LanePolicy;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Procurement\Process\Authority\ControlRepository;
use App\Repositories\Procurement\Process\Authority\HandlerAuthorityRepository;
use App\Repositories\Procurement\Process\Authority\LaneRepository;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;

/**
 * Идемпотентная boot/catalog материализация authority rows до первой работы:
 * control scopes и required lanes каждой schema-supported stage, handler-version rows при
 * registration. Каждая группа — отдельная короткая tx без admission, сети и work locks.
 * Чистый baseline install эти rows не создаёт: их создаёт вызов sync, не seed.
 */
final readonly class ProcessAuthoritySync
{
    public function __construct(
        private ProcessPolicy $policy,
        private ControlRepository $controls,
        private LaneRepository $lanes,
        private HandlerAuthorityRepository $handlers,
        private ProcessTransaction $transaction,
    ) {}

    public function ensureBaseline(): void
    {
        $scopes = [];
        $required = [];
        foreach (SchemaSupportedStages::stages() as $stage) {
            array_push($scopes, ...$this->policy->stageScopes($stage));
            array_push($required, ...$this->policy->requiredLanes($stage));
        }
        $this->transaction->run(fn () => $this->controls->ensureScopes($scopes));

        $lanes = array_values(array_filter($this->policy->lanes(), static fn (LanePolicy $lane): bool => in_array($lane->key, $required, true)));
        $this->transaction->run(fn () => $this->lanes->syncLanes(policies: $lanes, policyVersion: $this->policy->policyVersion));
    }

    public function registerHandler(StageCode $stage, string $handlerVersion): HandlerAuthoritySnapshot
    {
        return $this->transaction->run(fn (): HandlerAuthoritySnapshot => $this->handlers->register(stage: $stage, handlerVersion: $handlerVersion));
    }
}
