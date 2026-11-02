<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\WorkId;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
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
