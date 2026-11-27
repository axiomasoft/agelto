<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use Illuminate\Support\Facades\DB;

/** Transactional test sink in a PG temporary table; never an estimate/current-analysis writer. */
final readonly class PreparedFixturePublicationRepository implements StageResultRepository
{
    public function __construct(private WorkId $work) {}

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        throw new \LogicException('Fixture analysis has no continuation.');
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Fixture publication');
        if (! app()->environment('testing') || getenv('PROCESS_CORE_TEST') !== '1'
            || ! $input instanceof ScreeningInput || ! $result instanceof FixtureStageResult
            || $input->representationHash != $result->artifact->sha256) {
            throw new \LogicException('Invalid fixture publication.');
        }
        DB::insert('INSERT INTO p36_fixture_publications(work_id,version_id,disk,object_key,content_hash) VALUES (?,?,?,?,?)',
            [$this->work->value->value, $input->documentVersionId, $result->artifact->disk, $result->artifact->key, $result->artifact->sha256->hex]);

        return [];
    }
}
