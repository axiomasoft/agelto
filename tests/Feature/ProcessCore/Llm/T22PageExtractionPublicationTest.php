<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\WorkId;
use App\Repositories\Procurement\Ai\AnalysisPlanRepository;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Extraction\PublicationHandler;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\PageRuntimeFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('t13', 't22', 'p313');

/** @param list<array{int,int}> $positions */
function p313PageExtractionRaw(int $ordinal, PageRange $range, array $positions): string
{
    $template = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR)['rows'][0];
    $rows = [];
    foreach ($positions as [$page, $line]) {
        $row = $template;
        $row['estimate_ordinal'] = $ordinal;
        $row['page_index'] = $page;
        $row['source_anchor'] = 'page:'.$page.':line:'.$line;
        $row['row']['row_index'] = $page * 100000 + $line;
        $rows[] = $row;
    }

    return json_encode(['rows' => $rows, 'totals' => [], 'covered_pages' => range($range->start, $range->end - 1)], JSON_THROW_ON_ERROR);
}

test('page split сохраняет переменное число строк и публикует две сметы одним current commit', function (): void {
    [$screeningWork, $lease, $run, $period] = PageRuntimeFixtures::work();
    $screeningInput = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $screen = static fn ($lease) => app(WorkFinalizer::class)->finish($lease,
        app(ScreeningHandler::class)->execute($screeningInput, app(ExecutionContextFactory::class)->open($lease)));
    PageRuntimeFixtures::capture($lease, $run, $period, ScreeningRuntimeFixtures::raw());
    $screen($lease);
    $lease = FinalizeFixtures::running($screeningWork);
    PageRuntimeFixtures::capture($lease, $run, $period, '{"pages":[{"page_index":0,"estimate_ordinal":1},{"page_index":1,"estimate_ordinal":1},{"page_index":2,"estimate_ordinal":2},{"page_index":3,"estimate_ordinal":2}],"ambiguous_pages":[]}', new PageRange(0, 4));
    $screen($lease);
    $screen(FinalizeFixtures::running($screeningWork));
    $plans = app(AnalysisPlanRepository::class)->plans($run);
    foreach ($plans as $index => $plan) {
        $window = $plan->windows[0];
        $workId = DB::table('prc_process_work')->where('stage', 'extraction')->where('input_payload->analysis_run_id', $run->id->value)
            ->where('input_payload->window_key', $window->windowKey)->soleValue('id');
        $work = new WorkId(new ProcessUuid($workId));
        $input = new ExtractionWindowInput($run->id, $window->windowKey, $run->recipe->hash);
        $apply = static fn ($lease) => app(WorkFinalizer::class)->finish($lease,
            app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
        $lease = FinalizeFixtures::running($work);
        if ($index === 0) {
            ExtractionRuntimeFixtures::capture($lease, $window, $period, '{"rows":[]}', 'MAX_TOKENS', range: new PageRange(0, 2));
            $apply($lease);
            $lease = FinalizeFixtures::running($work);
            expect($lease->checkpoint->stageProgress->checkpoint->pending)->toEqual([new PageRange(0, 1), new PageRange(1, 2)]);
            ExtractionRuntimeFixtures::capture($lease, $window, $period, p313PageExtractionRaw(1, new PageRange(0, 1), [[0, 0], [0, 1]]), range: new PageRange(0, 1));
            $apply($lease);
            $lease = FinalizeFixtures::running($work);
            ExtractionRuntimeFixtures::capture($lease, $window, $period, p313PageExtractionRaw(1, new PageRange(1, 2), [[1, 0]]), range: new PageRange(1, 2));
        } else {
            ExtractionRuntimeFixtures::capture($lease, $window, $period, p313PageExtractionRaw(2, new PageRange(2, 4), [[2, 0], [3, 0]]), range: new PageRange(2, 4));
        }
        $apply($lease);
        $apply(FinalizeFixtures::running($work));
        expect(DB::table('prc_estimates')->count())->toBe(0);
    }
    $publication = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));
    $lease = FinalizeFixtures::running($publication);
    $input = new PublicationInput($run->id, $run->recipe->hash, $run->generation);
    $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('succeeded')
        ->and(DB::table('prc_estimates')->count())->toBe(2)
        ->and(DB::table('prc_estimate_items')->count())->toBe(5)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(3)
        ->and(DB::table('llm_provider_calls')->count())->toBe(6)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(6)
        ->and(DB::table('prc_document_versions')->where('id', $run->documentVersionId)->value('published_analysis_run_id'))->toBe($run->id->value);
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->status->value)->toBe('lost_ownership')
        ->and(DB::table('prc_estimates')->count())->toBe(2);
    Http::assertNothingSent();
});
