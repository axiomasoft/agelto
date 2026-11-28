<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Governance\WorkStateCounts;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Projection\RunFacts;
use App\Dto\Procurement\Process\Projection\RunSummary;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\DiscoveryOutcome;
use App\Repositories\Procurement\Process\Projection\RunReadRepository;

/**
 * Read-side прогресс run: current gauge читается из authoritative run и
 * work rows, а не накапливается событиями. Поэтому старый или переставленный event не возвращает
 * статус назад (late lower-seq event безопасен), а lag projector показывается отдельно. Один work
 * может быть required в нескольких runs: он считается в КАЖДОМ, executed_count — только по
 * relation=created. Observes (downstream provenance) показан отдельно и run не удерживает.
 */
final readonly class RunProjector
{
    public function __construct(private RunReadRepository $reads, private RunStateReducer $reducer) {}

    public function summary(ProcessUuid $runId): ?RunSummary
    {
        // Header и links из одного snapshot: иначе cancel между чтениями дал бы несуществовавшее Completed.
        $snapshot = $this->reads->runSnapshot($runId);
        if ($snapshot === null) {
            return null;
        }
        $header = $snapshot['header'];
        $required = [];
        $observes = 0;
        $executed = 0;
        $links = 0;
        foreach ($snapshot['groups'] as $group) {
            $links += $group['links'];
            $executed += $group['created'];
            if ($group['role'] === AccountingRole::Required->value) {
                $required[$group['state']] = ($required[$group['state']] ?? 0) + $group['links'];
            } else {
                $observes += $group['links'];
            }
        }
        $discovery = DiscoveryOutcome::from($header['outcome']);
        $counts = WorkStateCounts::fromMap($required);
        $state = $this->reducer->reduce(new RunFacts($header['cancelled'], $discovery, $header['started'] || $links > 0, $counts));

        return new RunSummary($runId, $state, $discovery, $counts, $observes, $executed);
    }
}
