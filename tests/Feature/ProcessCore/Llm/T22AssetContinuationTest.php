<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\ExtractionProgress;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Llm\OperationPurpose;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Llm\Cache\ProviderAssetManager;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Providers\GeminiAssetClient;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\AssetFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProviderSendFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('t14', 't15', 't22', 'p313');

test('asset raw recovery сохраняет metadata progress и directive consumption атомарно без повторного HTTP', function (OperationPurpose $purpose, bool $cancel): void {
    $server = new WireFixture;
    try {
        $f = AssetFixtures::runtime($server, $purpose);
        app(ReceiptPolicies::class)->register('fixture-provider', new AssetFixtures);
        $server->respond(parse_url($f->wire->endpoint, PHP_URL_PATH), json_encode(AssetFixtures::envelope(
            $purpose === OperationPurpose::FileUpload ? 'file' : 'cache'), JSON_THROW_ON_ERROR), method: 'POST');
        app(ProviderAssetManager::class)->claim($f->wire->assetKey, $f->operation->physicalCallId, $f->wire);
        $initialGate = $f->gate();
        $out = $initialGate->claimSend($f->operation, $f->wire)->transport->sendOnce($f->wire);
        $initialGate->recordTransportOutcome($f->operation->physicalCallId, $out);
        app(ReceiptStore::class)->put(app(RequestRepository::class)->get($f->operation->physicalCallId), $out->status, $out->body());
        FinalizeFixtures::expire($f->work, 600);
        expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
        $lease = FinalizeFixtures::running($f->work);
        expect($lease->checkpoint->providerRecoveryPlan->mode->value)->toBe('resume_provider_asset');
        $run = app(AnalysisRepository::class)->getRun($f->run);
        $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
        $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
        expect($outcome)->toBeInstanceOf(StagePartial::class)->and($outcome->checkpoint->result)->toBeNull();
        $table = $purpose === OperationPurpose::FileUpload ? 'llm_file_assets' : 'llm_context_caches';
        $column = $purpose === OperationPurpose::FileUpload ? 'provider_uri' : 'provider_name';
        if ($cancel) {
            app(CancelWork::class)->execute($f->work, 'fixture cancel before asset progress');
            expect(app(WorkFinalizer::class)->finish($lease, $outcome)->status->value)->toBe('lost_ownership')
                ->and(DB::table($table)->value($column))->toBeNull();
        } else {
            DB::unprepared("CREATE FUNCTION fixture_asset_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'asset journal rollback'; END \$\$");
            DB::unprepared('CREATE TRIGGER fixture_asset_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_asset_reject()');
            try {
                expect(fn () => app(WorkFinalizer::class)->finish($lease, $outcome))->toThrow(QueryException::class, 'asset journal rollback')
                    ->and(DB::table($table)->value($column))->toBeNull()
                    ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(1);
            } finally {
                DB::unprepared('DROP TRIGGER fixture_asset_reject ON prc_process_transitions');
                DB::unprepared('DROP FUNCTION fixture_asset_reject()');
            }
            expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('ready')
                ->and(DB::table($table)->value($column))->not->toBeNull()
                ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(0);
            $next = FinalizeFixtures::running($f->work);
            expect($next->checkpoint->providerRecoveryPlan)->toBeInstanceOf(NoProviderRecovery::class)
                ->and($next->checkpoint->stageProgress->result)->toBeNull()
                ->and(app(WorkFinalizer::class)->finish($lease, $outcome)->status->value)->toBe('lost_ownership');
        }
        expect($server->receipts())->toHaveCount(1)
            ->and(DB::table('llm_provider_calls')->count())->toBe(1)
            ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
            ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('settled')
            ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
            ->and(DB::table('prc_estimates')->count())->toBe(0);
    } finally {
        $server->stop();
    }
})->with([[OperationPurpose::FileUpload, false], [OperationPurpose::CacheCreate, false],
    [OperationPurpose::FileUpload, true], [OperationPurpose::CacheCreate, true]]);

test('lookup recovery продолжает declared generation с отдельной identity и одним новым HTTP', function (OperationPurpose $purpose): void {
    $server = new WireFixture;
    try {
        $f = AssetFixtures::runtime($server, $purpose);
        app(ReceiptPolicies::class)->register('fixture-provider', new AssetFixtures);
        $metadata = AssetFixtures::envelope($purpose === OperationPurpose::FileUpload ? 'file' : 'cache');
        $manager = app(ProviderAssetManager::class);
        $manager->claim($f->wire->assetKey, $f->operation->physicalCallId, $f->wire);
        $server->respond(parse_url($f->wire->endpoint, PHP_URL_PATH), json_encode($metadata, JSON_THROW_ON_ERROR), method: 'POST');
        $initialGate = $f->gate();
        $out = $initialGate->claimSend($f->operation, $f->wire)->transport->sendOnce($f->wire);
        $initialGate->recordTransportOutcome($f->operation->physicalCallId, $out);
        app(ReceiptStore::class)->put(app(RequestRepository::class)->get($f->operation->physicalCallId), $out->status, $out->body());
        $run = app(AnalysisRepository::class)->getRun($f->run);
        $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
        $handler = app(ScreeningHandler::class);
        $recover = static function () use ($f, $input, $handler): ExecutionLease {
            FinalizeFixtures::expire($f->work, 600);
            expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
            $lease = FinalizeFixtures::running($f->work);
            $outcome = $handler->execute($input, app(ExecutionContextFactory::class)->open($lease));
            expect($outcome)->toBeInstanceOf(StagePartial::class)
                ->and(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('ready');

            return FinalizeFixtures::running($f->work);
        };
        $lease = $recover();
        $asset = $manager->prepareRecovery(app(ReceiptStore::class)->inspect(app(RequestRepository::class)->get($f->operation->physicalCallId)));
        $resource = $asset instanceof ProviderFileAsset ? 'files/'.basename($asset->uri) : $asset->name;
        $plan = AssetFixtures::plan($f->plan, OperationPurpose::Lookup, $asset->key->hash);
        $wire = (new GeminiAssetClient($server->url('/')->origin->value()))->lookup($plan, AssetFixtures::capabilities($plan->context), $asset->key, $resource);
        $operation = app(AnalysisCallCoordinator::class)->authorize($lease, $run->id, $plan, $f->period, $wire);
        $server->respond(parse_url($wire->endpoint, PHP_URL_PATH), json_encode($purpose === OperationPurpose::FileUpload ? $metadata['file'] : $metadata, JSON_THROW_ON_ERROR));
        $gate = ProviderSendFixtures::gateFor($lease, $f->run, $f->target, $f->route, $f->profile, $server);
        $out = $gate->claimSend($operation, $wire)->transport->sendOnce($wire);
        $gate->recordTransportOutcome($operation->physicalCallId, $out);
        app(ReceiptStore::class)->put(app(RequestRepository::class)->get($operation->physicalCallId), $out->status, $out->body());
        $lease = $recover();
        expect($lease->checkpoint->providerRecoveryPlan)->toBeInstanceOf(NoProviderRecovery::class)
            ->and($server->receipts())->toHaveCount(2)
            ->and(DB::table('llm_provider_calls')->count())->toBe(2)
            ->and(DB::table('llm_budget_reservations')->count())->toBe(2);
        // The next purpose passes through the production AnalysisGeneration + joint network gate.
        $connections = app(AnalysisProviderConnections::class);
        $connections->register(new AnalysisProviderConnection($run->recipe->llm,
            $server->url('/')->origin->value(), FinancialFixtures::pricing(), $f->period,
            $f->profile, 'fixture-only-key', $server->policy()));
        DB::transaction(fn () => app(RouteRepository::class)->ensureProfile(
            app(OutboundPolicy::class)->providerRouteProfile, $f->route->value));
        $profileId = app(RouteRepository::class)->profile(null, app(OutboundPolicy::class)->providerRouteProfile);
        DB::table('prc_network_route_profile_members')->insertOrIgnore(['profile_id' => $profileId->value, 'route_id' => $f->route->value]);
        $body = json_encode(['fixture_receipt' => 1, 'outcome' => 'success', 'cost' => '0.00200001',
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => ScreeningRuntimeFixtures::raw()]]]]]], JSON_THROW_ON_ERROR);
        $server->respond('/v1beta/models/fixture-model:generateContent', $body, method: 'POST', replace: true);
        $outcome = $handler->execute($input, app(ExecutionContextFactory::class)->open($lease));
        expect($outcome instanceof StageDeferred ? $outcome->reason->value : null)->toBeNull();
        expect($outcome)->toBeInstanceOf(StagePartial::class)
            ->and($outcome->checkpoint->result)->not->toBeNull()
            ->and(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('ready')
            ->and($server->receipts())->toHaveCount(3)
            ->and(DB::table('llm_provider_calls')->count())->toBe(3)
            ->and(DB::table('llm_provider_requests')->where('purpose', 'generate')->count())->toBe(1)
            ->and(DB::table('llm_budget_reservations')->count())->toBe(3)
            ->and(DB::table('prc_analysis_chunks')->count())->toBe(0);
    } finally {
        $server->stop();
    }
})->with([OperationPurpose::FileUpload, OperationPurpose::CacheCreate]);

test('extraction asset recovery сохраняет pending ranges и ранее потраченный semantic ordinal', function (): void {
    $recipe = AiFixtures::recipe(new PinnedLlmContext('fixture-provider', 'fixture-account', 'v1beta',
        'fixture-model', 'route/1', ['max_output_tokens' => 4096], ['include_thoughts' => false]));
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work(recipe: $recipe);
    $key = $window->subrangeKey($window->range)->hex;
    $before = new ExtractionProgress(new ExtractionCheckpoint([$window->range], []), reasks: [$key => 1]);
    app(WorkFinalizer::class)->finish($lease, new StagePartial($before, new MissingParts(['asset_step'])));
    $lease = FinalizeFixtures::running($work);
    $fileKey = AssetFixtures::file($recipe->llm)->key;
    $plan = new ProviderOperationPlan(new RequestIdentity($recipe->hash->hex, $fileKey->hash,
        OperationPurpose::FileUpload, 0, $window->run->id->value), $recipe->llm, AssetFixtures::pricing(OperationPurpose::FileUpload), $work->value->value);
    $wire = (new GeminiAssetClient)->upload($plan, AssetFixtures::capabilities($recipe->llm), $fileKey, AssetFixtures::BYTES);
    $operation = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $period, $wire);
    app(ReceiptPolicies::class)->register('fixture-provider', new AssetFixtures);
    app(ProviderAssetManager::class)->claim($fileKey, $operation->physicalCallId, $wire);
    DB::transaction(fn () => app(RequestRepository::class)->claimFinancialLocked($operation, $wire->fingerprint));
    app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId),
        200, json_encode(AssetFixtures::envelope('file'), JSON_THROW_ON_ERROR));
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $lease = FinalizeFixtures::running($work);
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $recipe->hash);
    $outcome = app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StagePartial::class)
        ->and(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('ready');
    $next = FinalizeFixtures::running($work);
    expect($next->checkpoint->providerRecoveryPlan)->toBeInstanceOf(NoProviderRecovery::class)
        ->and($next->checkpoint->stageProgress)->toEqual($before)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1);
    Http::assertNothingSent();
});
