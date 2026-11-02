<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ScreeningInput;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Repositories\Procurement\Ai\AnalysisRunReadRepository;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Support\Facades\DB;

final class AiUiFixtures
{
    /**
     * @param  array<string, int|bool>|null  $usage
     * @param  list<string>  $tags
     * @return array{Fixture,AnalysisRun,Analysis,LlmCall}
     */
    public static function accepted(
        ?array $usage = null,
        string $documentType = 'tech_spec',
        string $cardinality = 'none',
        array $tags = ['смета', 'раздел-1'],
    ): array {
        [$work,$lease,$window,$period] = ScreeningRuntimeFixtures::work();
        $raw = json_decode(ScreeningRuntimeFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
        $raw['document_type'] = $documentType;
        $raw['estimate_cardinality'] = $cardinality;
        $raw['tags'] = $tags;
        ScreeningRuntimeFixtures::capture($lease, $window, $period, json_encode($raw, JSON_THROW_ON_ERROR), usage: $usage);
        $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
        $finalizer = app(WorkFinalizer::class);
        $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
        $lease = FinalizeFixtures::running($work);
        $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
        $document = DB::table('prc_document_versions')->where('id', $window->run->documentVersionId)->value('document_id');
        $fixture = Fixture::factory()->est()->create(['document_id' => $document]);
        $run = app(AnalysisRunReadRepository::class)->query()->where('prc_analysis_runs.id', $window->run->id->value)->sole();

        return [$fixture, $run, Analysis::query()->sole(), app(LlmCallReadRepository::class)->query()->sole()];
    }
}
