<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowPlan;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Repositories\Procurement\Ai\AnalysisPlanRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('claim-recovery-and-invariants', 'analysis-execution');

test('replacement analysis with identical recipe cannot consume the previous screening work checkpoint', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    $old = $window->run;
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($old->documentVersionId, $old->representationHash, $old->recipe->hash);
    app(WorkFinalizer::class)->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($work);

    $replacement = new AnalysisRunSnapshot(LedgerFixtures::uuid(), $old->documentVersionId, $old->representationId,
        $old->representationHash, $old->recipe, 1, $old->expectedDocumentHeadGeneration, $old->expectedAnalysisGeneration + 1,
        'explicit same-recipe reanalysis', $old->id);
    $replacementWindow = new AnalysisWindowSnapshot(LedgerFixtures::uuid(), $replacement, $window->windowKey,
        $window->range, $window->estimateOrdinal, $window->sourceAnchors);
    app(ProcessTransaction::class)->run(function () use ($replacement, $replacementWindow): void {
        DB::select('SELECT id FROM prc_document_versions WHERE id=? FOR UPDATE', [$replacement->documentVersionId]);
        DB::select('SELECT id FROM prc_documents WHERE current_version_id=? FOR UPDATE', [$replacement->documentVersionId]);
        app(AnalysisRepository::class)->storeRunLocked($replacement);
        app(AnalysisRepository::class)->storeWindowLocked($replacementWindow);
        app(AnalysisPlanRepository::class)->sealLocked($replacement, [new AnalysisWindowPlan($replacement, 1, $replacementWindow->range, [$replacementWindow])]);
    });

    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    app(WorkFinalizer::class)->finish($lease, $outcome);

    Http::assertNothingSent();
    expect([
        'old_work_state' => ExecutionFixtures::work($work)->state,
        'replacement_result_published' => DB::table('prc_analysis_runs')->where('id', $replacement->id->value)->value('screening_result') !== null,
        'extraction_successors' => DB::table('prc_process_work')->where('stage', 'extraction')->count(),
        'physical_calls' => DB::table('llm_provider_calls')->count(),
        'financial_reservations' => DB::table('llm_budget_reservations')->count(),
    ])->toBe(['old_work_state' => 'superseded', 'replacement_result_published' => false,
        'extraction_successors' => 0, 'physical_calls' => 1, 'financial_reservations' => 1]);
});
