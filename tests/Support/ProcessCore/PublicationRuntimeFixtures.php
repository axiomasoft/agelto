<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Document\Prepare\RepresentationCoverage;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Inputs\ExtractionWindowInput;
use App\Dto\Procurement\Process\Inputs\PublicationInput;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Services\Procurement\Extraction\Orchestration\ExtractionHandler;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;

final class PublicationRuntimeFixtures
{
    /** @return array{WorkId,ExecutionLease,PublicationInput,AnalysisWindowSnapshot} */
    public static function ready(?string $raw = null, ?RepresentationCoverage $coverage = null): array
    {
        [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work(coverage: $coverage);
        ExtractionRuntimeFixtures::capture($lease, $window, $period, $raw ?? AiFixtures::raw());
        $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
        for ($i = 0; $i < 2; $i++) {
            app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
            if ($i === 0) {
                $lease = FinalizeFixtures::running($work);
            }
        }
        $id = new WorkId(new ProcessUuid((string) DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));

        return [$id, FinalizeFixtures::running($id), new PublicationInput($window->run->id, $window->run->recipe->hash, $window->run->generation), $window];
    }
}
