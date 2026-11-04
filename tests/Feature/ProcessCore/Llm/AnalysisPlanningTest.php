<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Services\Procurement\Extraction\AnalysisConfiguration;
use App\Services\Procurement\Extraction\AnalysisFeeder;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\AnalysisRuntime;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\PageRuntimeFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('document-processing', 'analysis-execution');

test('prepared backlog создаёт один pinned analysis без acquisition и без платного запроса', function (): void {
    [$candidate, $document, $period] = PageRuntimeFixtures::prepared();
    $connections = app(AnalysisProviderConnections::class);
    $profile = new RequestProfile('fixture.analysis', 1, 2, 3, 8000000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1);
    $connections->register(new AnalysisProviderConnection($candidate->recipe->llm, 'https://fixture.test', FinancialFixtures::pricing(), $period, $profile, 'fixture-secret'), preferred: true);
    expect(app(AnalysisFeeder::class)->reconcile())->toBe(1)
        ->and(app(AnalysisFeeder::class)->reconcile())->toBe(0)
        ->and(DB::table('prc_analysis_runs')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('stage', 'screening')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('stage', 'screening')->where('state', 'active')->count())->toBe(1)
        ->and(DB::table('llm_provider_requests')->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0)
        ->and(DB::table('prc_process_runs')->value('discovery_outcome'))->toBe('complete');
    $runId = DB::table('prc_analysis_runs')->value('id');
    $run = app(AnalysisRepository::class)->getRun(new ProcessUuid($runId));
    expect($run->source)->toBeNull();
    $work = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('stage', 'screening')->soleValue('id')));
    $lease = FinalizeFixtures::running($work);
    expect(fn () => PageRuntimeFixtures::capture($lease, $run, $period, ScreeningRuntimeFixtures::raw()))
        ->toThrow(OperationDenied::class, 'analysis_source_plan_invalid');
    $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StagePartial::class)
        ->and(DB::table('llm_provider_requests')->count())->toBe(0);
    DB::unprepared("CREATE FUNCTION fixture_source_journal_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'fixture source journal failure'; END \$\$");
    DB::unprepared('CREATE TRIGGER fixture_source_journal_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_source_journal_reject()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $outcome))
            ->toThrow(QueryException::class, 'fixture source journal failure');
        expect(app(AnalysisRepository::class)->getRun($run->id)->source)->toBeNull()
            ->and(DB::table('llm_provider_requests')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER fixture_source_journal_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_source_journal_reject()');
    }
    app(WorkFinalizer::class)->finish($lease, $outcome);
    $run = app(AnalysisRepository::class)->getRun($run->id);
    expect($run->source->pageCount)->toBe(4)
        ->and($run->source->anchors['page:0:line:1'])->toBe(0)
        ->and($run->recipe->llm)->toEqual($candidate->recipe->llm)
        ->and(DB::table('prc_document_versions')->where('id', $run->documentVersionId)->value('current_analysis_run_id'))->toBe($runId);
    Http::assertNothingSent();
});

test('нет выбранного account profile означает отсутствие guessed recipe и provider intent', function (): void {
    PageRuntimeFixtures::prepared();
    expect(app(AnalysisFeeder::class)->reconcile())->toBe(0)
        ->and(fn () => app(AnalysisProviderConnections::class)->preferred())->toThrow(OperationDenied::class, 'provider_configuration_hold')
        ->and(DB::table('prc_analysis_runs')->count())->toBe(0)
        ->and(DB::table('llm_provider_requests')->count())->toBe(0);
});

test('analysis configuration и runtime composition не выполняют database или network IO', function (): void {
    config(['llm.profiles_json' => '{}', 'llm.analysis_profile' => null, 'llm.credentials' => []]);
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $config = new AnalysisConfiguration;
    $registry = new StageRegistry;
    (new AnalysisRuntime)->register($registry);
    expect($config->connections)->toBe([])->and($queries)->toBe([]);
    foreach ([StageCode::Screening, StageCode::Extraction, StageCode::Publication] as $stage) {
        expect($registry->handlerVersion($stage))->toBe(AnalysisRuntime::VERSION)
            ->and($registry->handler($stage))->toBeInstanceOf(StageHandler::class);
    }
    config(['llm.analysis_profile' => 'missing']);
    expect(fn () => new AnalysisConfiguration)->toThrow(InvalidArgumentException::class, 'llm.analysis_profile');
    Http::assertNothingSent();
});

test('явный sync provision account period и network catalog без provider send', function (): void {
    FinancialFixtures::reset();
    $context = new PinnedLlmContext('gemini', 'gemini-fixture-account', 'v1beta', 'fixture-model', 'route/1', ['max_output_tokens' => 4096], ['budget' => 0]);
    $profile = ['context' => json_decode($context->json, flags: JSON_THROW_ON_ERROR), 'origin' => 'https://generativelanguage.googleapis.com',
        'period_id' => '', 'credential_key' => 'fixture', 'pricing' => ['currency' => 'USD', 'version' => 'fixture-pricing/1',
            'input_max_tokens' => 1000000, 'output_max_tokens' => 4096, 'thinking_max_tokens' => 0, 'thinking_included_in_output' => true,
            'input_per_million' => 1000000, 'output_per_million' => 2000000, 'thinking_per_million' => 0, 'fixed_charge' => 0,
            'bound_evidence' => 'deterministic fixture only', 'valid_until' => '2099-01-01T00:00:00Z']];
    config(['llm.profiles_json' => json_encode(['test' => $profile], JSON_THROW_ON_ERROR), 'llm.analysis_profile' => 'test', 'llm.credentials' => ['fixture' => 'fixture-only']]);
    app()->forgetInstance(AnalysisConfiguration::class);
    $dates = DB::selectOne("SELECT (clock_timestamp()-interval '1 hour')::text AS start,(clock_timestamp()+interval '1 hour')::text AS stop");
    $args = ['--period-start' => $dates->start, '--period-end' => $dates->stop, '--cap' => '1.00000000', '--reason' => 'Fixture explicit provisioning'];
    $this->artisan('process:sync-analysis', $args)->assertSuccessful();
    $this->artisan('process:sync-analysis', $args)->assertSuccessful();
    expect(DB::table('llm_budget_accounts')->where('account_id', $context->account)->value('unknown_count_threshold'))->toBe(1)
        ->and(DB::table('llm_budget_periods')->where('account_id', $context->account)->count())->toBe(1)
        ->and(DB::table('llm_budget_periods')->where('account_id', $context->account)->value('cap'))->toBe('1.00000000')
        ->and(DB::table('llm_provider_requests')->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0)
        ->and(DB::table('prc_source_targets')->where('provider_key', 'gemini')->count())->toBe(1)
        ->and(app(FinancialReservationRepository::class)->currentPeriod($context->account, 'USD'))->toBe(DB::table('llm_budget_periods')->where('account_id', $context->account)->value('id'));
    Http::assertNothingSent();
});
