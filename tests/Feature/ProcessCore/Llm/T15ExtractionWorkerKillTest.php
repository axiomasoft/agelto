<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\ExtractionProgress;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Llm\OperationDenied;
use App\Models\User;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t13', 't15', 't22', 'p313');

test('an unclaimed denied send preserves a known-safe retry directive and is never misclassified as paid unknown', function (): void {
    $server = new WireFixture;
    try {
        PermitFixtures::clean();
        $recipe = AiFixtures::recipe(new PinnedLlmContext('fixture-provider', 'fixture-account', 'v1beta', 'fixture-model', 'route/1', ['max_output_tokens' => 4096], ['include_thoughts' => false]));
        [$work,$lease,$window,$period] = ExtractionRuntimeFixtures::work(recipe: $recipe);
        $profile = TransportFixtures::profile(['total_seconds' => 4]);
        $origin = $server->url('/')->origin;
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('fixture-provider'), [
            new SourceTargetDefinition('provider:fixture-provider:generate', 'provider', $origin, [$profile], new OriginBudgetPolicy(600, 1, 2))]));
        app(AnalysisProviderConnections::class)->register(new AnalysisProviderConnection($recipe->llm, $origin->value(), FinancialFixtures::pricing(), $period, $profile, 'fixture-only-key', $server->policy()));
        DB::table('prc_network_routes')->update(['enabled' => false]);
        $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $recipe->hash);
        $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
        app(WorkFinalizer::class)->finish($lease, $outcome);
        expect(ExecutionFixtures::work($work)->state)->toBe('deferred')->and(ExecutionFixtures::work($work)->reason_code)->toBe('no_route')
            ->and(DB::table('llm_provider_requests')->soleValue('state'))->toBe('failed_known')
            ->and(DB::table('llm_provider_calls')->soleValue('outcome'))->toBe('not_sent')
            ->and(DB::table('llm_budget_reservations')->soleValue('state'))->toBe('settled')
            ->and(DB::table('llm_budget_reservations')->soleValue('settled_amount'))->toBe('0.00000000')
            ->and(DB::table('llm_provider_calls')->whereNotNull('send_claimed_at')->count())->toBe(0)
            ->and($server->receipts())->toHaveCount(0);
        $lease = FinalizeFixtures::rerun($work);
        expect($lease->checkpoint->providerRecoveryPlan->mode->value)->toBe('known_retry_eligible');
        DB::table('prc_network_routes')->update(['enabled' => true]);
        $route = DB::table('prc_network_routes')->where('kind', 'direct')->soleValue('id');
        $version = (int) DB::table('prc_network_route_profiles')->where('profile_key', 'llm')->soleValue('policy_version');
        $actor = User::factory()->create()->id;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->updateProfile('llm', $version, [$route], $actor, 'Allow repaired fixture route', LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        $body = json_encode(['fixture_receipt' => 1, 'outcome' => 'success', 'cost' => '0.00200001', 'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => AiFixtures::raw()]]]]]], JSON_THROW_ON_ERROR);
        $server->respond('/v1beta/models/fixture-model:generateContent', $body, headers: ['Content-Type' => 'application/json'], method: 'POST');
        $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
        app(WorkFinalizer::class)->finish($lease, $outcome);
        expect(ExecutionFixtures::work($work)->state)->toBe('ready')->and(DB::table('prc_analysis_chunks')->count())->toBe(1)
            ->and(DB::table('llm_provider_requests')->count())->toBe(1)->and(DB::table('llm_provider_calls')->count())->toBe(2)
            ->and(DB::table('llm_budget_reservations')->count())->toBe(2)->and($server->receipts())->toHaveCount(1);
    } finally {
        $server->stop();
    }
});

test('SIGKILL of the real extraction runner resumes the same work with zero additional HTTP or financial reservations', function (string $boundary): void {
    $server = new WireFixture;
    try {
        PermitFixtures::clean();
        $recipe = AiFixtures::recipe(new PinnedLlmContext('fixture-provider', 'fixture-account', 'v1beta', 'fixture-model',
            'route/1', ['max_output_tokens' => 4096], ['include_thoughts' => false]));
        [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work(recipe: $recipe);
        // Return the initial fixture claim to ready. All subsequent deliveries go through WorkRunner.
        app(WorkFinalizer::class)->finish($lease, new StagePartial(new ExtractionProgress, new MissingParts(['start_runner'])));
        $profile = TransportFixtures::profile(['total_seconds' => 4]);
        $origin = $server->url('/')->origin;
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('fixture-provider'), [
            new SourceTargetDefinition('provider:fixture-provider:generate', 'provider', $origin, [$profile], new OriginBudgetPolicy(600, 1, 2)),
        ]));
        $profileVersion = (int) DB::table('prc_network_route_profiles')->where('profile_key', 'llm')->value('policy_version');
        $route = (string) DB::table('prc_network_routes')->where('kind', 'direct')->value('id');
        $actor = User::factory()->create()->id;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->updateProfile('llm', $profileVersion, [$route], $actor,
            'Authorize fixture route for the real analysis worker', LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100_000]);
        $connection = new AnalysisProviderConnection($recipe->llm, $origin->value(), FinancialFixtures::pricing(), $period, $profile,
            'fixture-only-key', $server->policy());
        $body = json_encode(['fixture_receipt' => 1, 'outcome' => 'success', 'cost' => '0.00200001',
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => AiFixtures::raw()]]]]]], JSON_THROW_ON_ERROR);
        $server->respond('/v1beta/models/fixture-model:generateContent', $body, headers: ['Content-Type' => 'application/json'], method: 'POST');
        app(WorkFeeder::class)->tick('analysis');
        $delivery = new DeliveryEnvelope($work, ExecutionFixtures::delivery($work), LedgerFixtures::uuid(), 1);
        $payload = base64_encode(serialize([$connection, $delivery, Storage::disk('local')->path(''), $boundary]));
        $code = '\Tests\Support\ProcessCore\ExtractionRuntimeFixtures::bind();'
            .'[$connection,$delivery,$localRoot,$boundary]=unserialize(base64_decode('.var_export($payload, true).'));'
            .'config(["filesystems.disks.local.root"=>$localRoot]); \Illuminate\Support\Facades\Storage::forgetDisk("local");'
            .'app(\App\Services\Llm\Execution\ReceiptPolicies::class)->register("fixture-provider",new \Tests\Support\ProcessCore\FinancialFixtures);'
            .'app(\App\Services\Procurement\Extraction\AnalysisProviderConnections::class)->register($connection);'
            .'\Illuminate\Support\Facades\DB::listen(static function($event) use ($boundary): void {'
            .'$raw=$boundary==="raw" && str_contains($event->sql,"UPDATE llm_provider_calls SET receipt_state=") && str_contains($event->sql,"verified");'
            .'$output=$boundary==="output" && str_contains($event->sql,"UPDATE prc_storage_reservations SET status=\'staged\'");'
            .'if($raw || $output) {'
            .'\Illuminate\Support\Facades\DB::afterCommit(static function(): void { posix_kill(getmypid(),SIGKILL); }); }});'
            .'$outcome=app(\App\Services\Procurement\Process\WorkRunner::class)->runDelivery($delivery);'
            .'$work=\Tests\Support\ProcessCore\ExecutionFixtures::work($delivery->workId);'
            .'return ["unexpected_survival"=>$outcome->value,"state"=>$work->state,"reason"=>$work->reason_code];';
        $survived = null;
        $killObserved = false;
        $failureDetail = '';
        try {
            $survived = ConcurrentTransactions::execute($code);
        } catch (RuntimeException $failure) {
            $killObserved = true;
            $failureDetail = $failure->getMessage();
        }
        expect($survived)->toBeNull(json_encode($survived, JSON_THROW_ON_ERROR))->and($killObserved)->toBeTrue();
        expect($server->receipts())->toHaveCount(1, $failureDetail)
            ->and(ExecutionFixtures::work($work)->state)->toBe('running')
            ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
            ->and(DB::table('llm_provider_calls')->value('receipt_state'))->toBe('verified')
            ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('settled');
        $orphanIds = DB::table('prc_storage_reservations')->where('work_id', $work->value->value)
            ->whereIn('status', ['reserved', 'writing', 'staged'])->pluck('id')->all();
        if ($boundary === 'output') {
            expect($orphanIds)->toHaveCount(2)
                ->and(DB::table('prc_storage_reservations')->whereIn('id', $orphanIds)->sum('byte_upper_bound'))->toBeGreaterThan(0);
            app(DocumentStorageReconciler::class)->reconcile();
            expect(DB::table('prc_storage_reservations')->whereIn('id', $orphanIds)->where('status', 'cleaned')->count())->toBe(0);
        }
        FinalizeFixtures::expire($work, 600);
        expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
        if ($boundary === 'output') {
            expect(app(DocumentStorageReconciler::class)->reconcile())->toBe(2)
                ->and(DB::table('prc_storage_reservations')->whereIn('id', $orphanIds)->where('status', 'cleaned')->count())->toBe(2);
        }
        // No runtime provider connection exists in this process: receipt-only resume must not ask for one.
        expect(fn () => app(AnalysisProviderConnections::class)->for($recipe->llm))->toThrow(OperationDenied::class);
        app(WorkFeeder::class)->tick('analysis');
        $delivery = new DeliveryEnvelope($work, ExecutionFixtures::delivery($work), LedgerFixtures::uuid(), 1);
        expect(app(WorkRunner::class)->runDelivery($delivery)->value)->toBe('executed')
            ->and(DB::table('prc_analysis_chunks')->count())->toBe(1)
            ->and(DB::table('llm_provider_calls')->count())->toBe(1)
            ->and(DB::table('prc_request_attempts')->count())->toBe(1)
            ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
            ->and($server->receipts())->toHaveCount(1)
            ->and(ExecutionFixtures::work($work)->state)->toBe('ready');
        app(WorkFeeder::class)->tick('analysis');
        expect(app(WorkRunner::class)->runDelivery(new DeliveryEnvelope($work, ExecutionFixtures::delivery($work), LedgerFixtures::uuid(), 1))->value)
            ->toBe('executed')
            ->and(ExecutionFixtures::work($work)->state)->toBe('succeeded')
            ->and(DB::table('prc_process_work')->where('stage', StageCode::Publication->value)->count())->toBe(1)
            ->and(DB::table('llm_provider_calls')->count())->toBe(1);
        $publication = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));
        app(WorkFeeder::class)->tick('publication');
        expect(app(WorkRunner::class)->runDelivery(new DeliveryEnvelope($publication, ExecutionFixtures::delivery($publication), LedgerFixtures::uuid(), 1))->value)
            ->toBe('executed')->and(ExecutionFixtures::work($publication)->state)->toBe('succeeded')
            ->and(DB::table('prc_estimates')->count())->toBe(1)
            ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('published')
            ->and(DB::table('llm_provider_calls')->count())->toBe(1)->and(DB::table('llm_budget_reservations')->count())->toBe(1)
            ->and(DB::table('prc_request_attempts')->count())->toBe(1)->and($server->receipts())->toHaveCount(1);
    } finally {
        $server->stop();
    }
})->with(['durable raw before chunk' => 'raw', 'output put before ref and spool cleanup' => 'output']);

test('missing immutable representation defers as storage dependency before reserving money and never consumes the handler fault budget', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    $connection = new AnalysisProviderConnection($window->run->recipe->llm, 'https://provider.invalid', FinancialFixtures::pricing(),
        $period, TransportFixtures::profile(), 'fixture-only-key');
    app(AnalysisProviderConnections::class)->register($connection);
    $rep = DB::table('prc_document_representations')->where('id', $window->run->representationId)->sole();
    Storage::disk($rep->disk)->delete($rep->storage_path);
    app(WorkFinalizer::class)->finish($lease, new StagePartial(new ExtractionProgress, new MissingParts(['start_runner'])));
    app(WorkFeeder::class)->tick('analysis');
    expect(app(WorkRunner::class)->runDelivery(new DeliveryEnvelope($work, ExecutionFixtures::delivery($work), LedgerFixtures::uuid(), 1))->value)
        ->toBe('executed')
        ->and(ExecutionFixtures::work($work)->state)->toBe('deferred')
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe('storage_unavailable')
        ->and(ExecutionFixtures::work($work)->item_failures)->toBe(0)
        ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});
