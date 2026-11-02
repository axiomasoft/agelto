<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Services\Procurement\Process\Contracts\StageHandler;

/** Fixture only: identical typed semantics for any provenance, no model or provider request. */
final class FixtureAnalysisHandler implements StageHandler
{
    public int $calls = 0;

    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
    {
        if (! app()->environment('testing') || ! $input instanceof ScreeningInput
            || $input->representationHash != ContentHash::of('anchored fixture representation')) {
            throw new \LogicException('Invalid fixture analysis input or environment.');
        }
        $execution->tick();
        app(AnalysisRepository::class)->forScreening($input);
        $this->calls++;

        return new StageSucceeded(new FixtureStageResult(new ArtifactRef('local', 'fixture/accepted', ContentHash::of('same semantics')), StageCode::Screening));
    }
}
