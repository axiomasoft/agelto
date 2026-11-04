<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\LanePolicy;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\HandlerAuthorityRepository;
use App\Repositories\Procurement\Process\LaneRepository;
use Illuminate\Support\Facades\DB;

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
    ) {}

    public function ensureBaseline(): void
    {
        $scopes = [];
        $required = [];
        foreach (SchemaSupportedStages::stages() as $stage) {
            array_push($scopes, ...$this->policy->stageScopes($stage));
            array_push($required, ...$this->policy->requiredLanes($stage));
        }
        DB::transaction(fn () => $this->controls->ensureScopes($scopes), attempts: 1);

        $lanes = array_values(array_filter($this->policy->lanes(), static fn (LanePolicy $lane): bool => in_array($lane->key, $required, true)));
        DB::transaction(fn () => $this->lanes->syncLanes($lanes, $this->policy->policyVersion), attempts: 1);
    }

    public function registerHandler(StageCode $stage, string $handlerVersion): HandlerAuthoritySnapshot
    {
        return DB::transaction(fn (): HandlerAuthoritySnapshot => $this->handlers->register($stage, $handlerVersion), attempts: 1);
    }
}
