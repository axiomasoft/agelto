<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Acquisition\Detail\DetailResult;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;

final class RefreshFixtures
{
    /** @return array{p: Procurement, input: DetailInput, work: WorkId, request: DetailRequest} */
    public static function dueDetail(string $value, ?Registry $registry = null): array
    {
        $fixture = DetailFixtures::work($registry, $value);
        app(WorkFinalizer::class)->finish(FinalizeFixtures::running($fixture['work']), new StageSucceeded(
            new DetailResult(DetailFixtures::snapshot($fixture['request'], 'attributes'), new DetailProgress,
                SnapshotConsistency::BoundedRevalidated, new \DateTimeImmutable)));
        DB::update('UPDATE prc_operation_intents SET next_due_at=clock_timestamp() WHERE id=?', [$fixture['input']->intentId->value]);
        DB::update("UPDATE prc_procurements SET next_status_due_at=clock_timestamp()+interval '1 day' WHERE id=?", [$fixture['p']->id]);

        return $fixture;
    }
}
