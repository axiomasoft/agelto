<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Contracts\StageHandler;
use Illuminate\Support\Facades\Storage;

/** Test-only consumer of real prepared bytes. No provider, estimate or analysis-run publication. */
final class PreparedFixtureAnalysisHandler implements StageHandler
{
    public int $calls = 0;

    public function __construct(private readonly string $expectedText) {}

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! app()->environment('testing') || getenv('PROCESS_CORE_TEST') !== '1'
            || ! $input instanceof ScreeningInput) {
            throw new \LogicException('Prepared fixture analysis is testing-only.');
        }
        $execution->tick();
        app(AnalysisRepository::class)->forScreening($input);
        $representation = LedgerRow::one("SELECT disk,storage_path,coverage_manifest::text AS coverage,coverage_manifest_schema_version
            FROM prc_document_representations WHERE document_version_id=? AND content_hash=decode(?,'hex') AND range_key='complete'",
            [$input->documentVersionId, $input->representationHash->hex])
            ?? throw new \LogicException('Fixture requires a real complete prepared representation.');
        $coverage = json_decode($representation->string('coverage'), associative: true, flags: JSON_THROW_ON_ERROR);
        $text = Storage::disk($representation->string('disk'))->get($representation->string('storage_path'));
        if ($representation->int('coverage_manifest_schema_version') !== 1 || $coverage['complete'] !== true
            || $coverage['missing'] !== [] || $text !== $this->expectedText || ContentHash::of($text) != $input->representationHash) {
            throw new \LogicException('Fixture preparation coverage or immutable bytes mismatch.');
        }
        $this->calls++;

        return new StageSucceeded(new FixtureStageResult(new ArtifactRef(
            disk: $representation->string('disk'), key: $representation->string('storage_path'), sha256: $input->representationHash,
        ), StageCode::Screening));
    }
}
