<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkInput;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use Illuminate\Support\Facades\DB;

/** Real transactional PG sink, testing only; publishes no estimates and activates no paid handler. */
final class FinancialFixturePublication implements StageResultRepository
{
    public function __construct()
    {
        DB::statement('DROP TABLE IF EXISTS pg_temp.p39_receipt_publications');
        DB::statement('CREATE TEMP TABLE p39_receipt_publications(document_version_id bigint PRIMARY KEY,artifact_key text NOT NULL)');
    }

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void {}

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        if (! $input instanceof ScreeningInput || ! $result instanceof FixtureStageResult) {
            throw new \InvalidArgumentException('Unexpected fixture publication input.');
        }
        DB::insert('INSERT INTO p39_receipt_publications(document_version_id,artifact_key) VALUES (?,?)', [$input->documentVersionId, $result->artifact->key]);

        return [];
    }
}
