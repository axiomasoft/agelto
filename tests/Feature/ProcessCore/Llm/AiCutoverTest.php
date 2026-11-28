<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Llm\Execution\LlmOptions;
use App\Dto\Llm\Prompt\LlmPrompt;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\Execution\AnalysisProviderConnection;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Llm\UnsupportedConsumer;
use App\Exceptions\Procurement\Ai\AnalysisRunAlreadyActive;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use App\Repositories\Procurement\Ai\AnalysisReviewRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\LlmProviderFactory;
use App\Services\Procurement\Extraction\Orchestration\AnalysisRuntime;
use App\Services\Procurement\Extraction\Orchestration\RerunAnalysisAction;
use App\Services\Procurement\Extraction\Provider\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Fixture\RunFixtureAnalysisAction;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('document-processing', 'structured-extraction-contract', 'analysis-execution', 'domain-dependency-boundary');

test('production container registers all real analysis handlers without configuration IO or provider calls', function (): void {
    app()->forgetInstance(StageRegistry::class);
    $queries = [];
    DB::listen(static function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });
    $registry = app(StageRegistry::class);
    foreach ([StageCode::Screening, StageCode::Extraction, StageCode::Publication] as $stage) {
        expect($registry->handlerVersion($stage))->toBe(AnalysisRuntime::VERSION)
            ->and($registry->resultRepository($stage))->toBeObject()
            ->and($registry->handler($stage))->toBeObject();
    }
    expect($queries)->toBe([]);
    Http::assertNothingSent();
});

function acceptedRetainedScreening(): Analysis
{
    [$work,$lease,$window,$period] = ScreeningRuntimeFixtures::work();
    $raw = json_decode(ScreeningRuntimeFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    $raw['document_type'] = 'tech_spec';
    $raw['estimate_cardinality'] = 'none';
    ScreeningRuntimeFixtures::capture($lease, $window, $period, json_encode($raw, JSON_THROW_ON_ERROR));
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($work);
    $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $profile = new RequestProfile('fixture.analysis', 1, 2, 3, 8000000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1);
    app(AnalysisProviderConnections::class)->register(new AnalysisProviderConnection($window->run->recipe->llm,
        'https://fixture.test', FinancialFixtures::pricing(), $period, $profile, 'fixture-only'), preferred: true);

    return Analysis::query()->sole();
}

test('manual reanalysis creates one new intent and immutable recipe while duplicate command never resets accepted history', function (): void {
    $analysis = acceptedRetainedScreening();
    $old = $analysis->analysis_run_id;
    $command = LedgerFixtures::uuid();
    $operator = User::factory()->create();
    $action = app(RerunAnalysisAction::class);
    $work = $action->run(analysis: $analysis, commandId: $command, actorId: $operator->id);
    $closeEventId = ListingStoreRepository::id('analysis-command-close:'.$command->value);
    expect($action->run($analysis, $command))->toEqual($work)
        ->and(DB::table('prc_analysis_runs')->count())->toBe(2)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('state', 'active')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('prc_process_runs')->where('id', ListingStoreRepository::id('analysis-command:'.$command->value)->value)->value('requested_by'))->toBe($operator->id)
        ->and(DB::table('prc_process_transitions')->where('event_id', $closeEventId->value)->value('actor_kind'))->toBe('operator')
        ->and(DB::table('prc_process_transitions')->where('event_id', $closeEventId->value)->value('actor_id'))->toBe($operator->id)
        ->and($analysis->fresh()->analysis_run_id)->toBe($old)
        ->and($analysis->fresh()->storedScreeningResult()->summary)->toBe('Локальная смета');
    expect(fn () => $action->run($analysis, LedgerFixtures::uuid()))->toThrow(InvalidArgumentException::class, 'current source');
    Http::assertNothingSent();
});

test('manual reanalysis journal failure rolls back recipe intent and version pointer together', function (): void {
    $analysis = acceptedRetainedScreening();
    DB::unprepared("CREATE FUNCTION fixture_reanalysis_reject() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'reanalysis journal failure'; END $$");
    DB::unprepared('CREATE TRIGGER fixture_reanalysis_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_reanalysis_reject()');
    try {
        expect(fn () => app(RerunAnalysisAction::class)->run($analysis))->toThrow(QueryException::class, 'reanalysis journal failure');
        expect(DB::table('prc_analysis_runs')->count())->toBe(1)
            ->and(DB::table('prc_operation_intents')->where('state', 'active')->count())->toBe(0)
            ->and(DB::table('prc_document_versions')->where('id', $analysis->document_version_id)->value('current_analysis_run_id'))->toBe($analysis->analysis_run_id);
    } finally {
        DB::unprepared('DROP TRIGGER fixture_reanalysis_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_reanalysis_reject()');
    }
});

test('review repository rejects result and execution writes while preserving allowed operator metadata', function (): void {
    $analysis = acceptedRetainedScreening();
    $review = app(AnalysisReviewRepository::class);
    $review->update($analysis, ['review_status' => 'approved', 'operator_comment' => 'Fixture review']);
    expect($analysis->fresh()->review_status->value)->toBe('approved');
    foreach (['status', 'result', 'analysis_run_id', 'attempts', 'locked_by'] as $field) {
        expect(fn () => $review->update($analysis, [$field => null]))->toThrow(InvalidArgumentException::class, 'review metadata');
    }
    expect($analysis->fresh()->storedScreeningResult()->summary)->toBe('Локальная смета');
});

test('providers without a registered owning gate reject synchronous paid methods before HTTP', function (): void {
    $prompt = new LlmPrompt('fixture', []);
    $options = new LlmOptions(provider: 'fixture', model: 'fixture', temperature: 0, maxTokens: 64);
    foreach (['gemini', 'openai_compatible'] as $driver) {
        $provider = app(LlmProviderFactory::class)->forDriver($driver);
        expect(fn () => $provider->complete($prompt, $options))->toThrow(UnsupportedConsumer::class);
    }
    Http::assertNothingSent();
});

test('fixture reanalysis follows the same manual intent owner and preserves its protected accepted result', function (): void {
    $analysis = acceptedRetainedScreening();
    $version = DocumentVersion::query()->findOrFail($analysis->document_version_id);
    $fixture = Fixture::factory()->create([
        'document_id' => $version->document_id, 'sha256' => $version->content_hash, 'source_format' => $version->extension]);
    $action = app(RunFixtureAnalysisAction::class);
    $action->run($fixture);
    expect(DB::table('prc_analysis_runs')->count())->toBe(2)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and($analysis->fresh()->storedScreeningResult()->summary)->toBe('Локальная смета');
    expect(fn () => $action->run($fixture))->toThrow(AnalysisRunAlreadyActive::class);
    Http::assertNothingSent();
});

test('manual reanalysis cannot bypass unresolved provider spending after extraction cancellation', function (): void {
    [$screen,$lease,$window,$period] = ScreeningRuntimeFixtures::work();
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($screen);
    $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $extract = new WorkId(new ProcessUuid(
        DB::table('prc_process_work')->where('stage', 'extraction')->soleValue('id')));
    $lease = FinalizeFixtures::running($extract);
    $plan = new ProviderOperationPlan(new RequestIdentity(
        $window->run->recipe->hash->hex, $window->subrangeInputHash($window->range)->hex, OperationPurpose::Generate, 0, $window->run->id->value),
        $window->run->recipe->llm, FinancialFixtures::pricing(), $extract->value->value);
    $op = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $period);
    FinancialFixtures::claim($op);
    app(CancelWork::class)->execute($extract, 'fixture unresolved paid result');
    $profile = new RequestProfile('fixture.analysis', 1, 2, 3, 8000000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1);
    app(AnalysisProviderConnections::class)->register(new AnalysisProviderConnection($window->run->recipe->llm,
        'https://fixture.test', FinancialFixtures::pricing(), $period, $profile, 'fixture-only'), preferred: true);
    expect(fn () => app(RerunAnalysisAction::class)->run(Analysis::query()->sole()))
        ->toThrow(InvalidArgumentException::class, 'resolved provider history');
    expect(DB::table('prc_analysis_runs')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(2)
        ->and(DB::table('llm_budget_reservations')->where('state', 'reserved')->count())->toBe(1);
    Http::assertNothingSent();
});
