<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkInput;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use Illuminate\Support\Facades\DB;

/**
 * Fixture finalization repository: listing пишет реальные domain rows своего locked crawl block
 * (cursor — progress, terminal_proof — result), чтобы tests проверяли ноль domain writes при
 * потере ownership. Другие stages domain rows не пишут. Никогда не регистрируется в приложении.
 */
final class FixtureStageResultRepository implements StageResultRepository
{
    /** @var list<WorkInput> successors, которые вернёт storeResult */
    public array $successors = [];

    /** Сбой mandatory progress write после work CAS: checkpoint обязан откатиться целиком. */
    public bool $failProgress = false;

    public int $progressWrites = 0;

    public int $resultWrites = 0;

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        $this->progressWrites++;
        if ($input instanceof ListingInput && $progress instanceof FixtureStageCheckpoint) {
            DB::update("UPDATE prc_crawl_blocks SET cursor = jsonb_build_object('position', ?::int) WHERE id = ?", [$progress->position, $input->crawlBlockId->value]);
        }
        if ($this->failProgress) {
            throw new \RuntimeException('Fixture progress write failed.');
        }
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        $this->resultWrites++;
        if ($input instanceof ListingInput && $result instanceof FixtureStageResult) {
            DB::update(
                "UPDATE prc_crawl_blocks SET terminal_proof = jsonb_build_object('artifact', ?::text), terminal_proof_schema_version = 1 WHERE id = ?",
                [$result->artifact->key, $input->crawlBlockId->value],
            );
        }

        return $this->successors;
    }
}
