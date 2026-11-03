<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\WindowExtractionTask;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ProviderSendFixtures;
use Tests\Support\ProcessCore\StructuredChainFixtures;

pest()->group('t13', 't15', 't22', 't30', 't35', 'p314');

beforeEach(function (): void {
    $this->server = new WireFixture;
});

afterEach(function (): void {
    $this->server->stop();
});

test('business handler проходит actual wire receipt chunk publication с сохранением финансового upper bound', function (): void {
    $f = new StructuredChainFixtures($this->server);
    $outcome = $f->extract($f->lease);
    expect($outcome)->toBeInstanceOf(StagePartial::class);
    $snapshot = $f->window->run->recipe->tasks->task(TaskPurpose::Rows);
    $expectedWire = CanonicalJson::encode([
        'systemInstruction' => ['parts' => [['text' => $snapshot->prompt->content]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => CanonicalJson::encode([
            'range' => $f->window->range->fields(), 'estimate_ordinal' => 1, 'anchors' => $f->window->sourceAnchors,
            'semantic_reask_ordinal' => 0, 'untrusted_document' => 'fixture anchored representation',
        ])]]]],
        'generationConfig' => ['responseMimeType' => 'application/json',
            'responseJsonSchema' => (new ProviderSchemaCompiler)->compile($snapshot->schema)->definition,
            'maxOutputTokens' => 4096, 'temperature' => 0.25, 'thinkingConfig' => ['thinkingBudget' => 128, 'includeThoughts' => false]],
    ]);
    $request = $this->server->receipts()[0];
    $call = DB::table('llm_provider_calls')->sole();
    $reserve = DB::table('llm_budget_reservations')->sole();
    expect($this->server->receipts())->toHaveCount(1)
        ->and($request['body_sha256'])->toBe(hash('sha256', $expectedWire))
        ->and($request['method'])->toBe('POST')
        ->and($call->send_claimed_at)->not->toBeNull()
        ->and($call->resolved_model_version)->toBe('fixture-model-resolved/1')
        ->and($call->finish_reason)->toBe('STOP')
        ->and($call->usage_status)->toBe('known')
        ->and(app(RequestRepository::class)->get($call->physical_call_id)->recordedAssessment?->usage?->uncachedInput)->toBe(80)
        ->and(app(RequestRepository::class)->get($call->physical_call_id)->recordedAssessment?->usage?->cachedInput)->toBe(20)
        ->and(DB::table('prc_request_attempts')->sole()->request_id)->toBe($call->physical_call_id)
        ->and(DB::table('prc_network_permits')->sole()->state)->toBe('released')
        ->and($reserve->state)->toBe('settled')
        ->and($reserve->settled_amount)->toBe($reserve->upper_bound)
        ->and(DB::table('llm_budget_periods')->where('id', $f->period)->value('spent'))->toBe($reserve->upper_bound)
        ->and(DB::table('llm_budget_periods')->where('id', $f->period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('prc_estimates')->count())->toBe(0);
    expect(app(WorkFinalizer::class)->finish($f->lease, $outcome)->state?->value)->toBe('ready');
    $f->publish();
    expect(DB::table('prc_analysis_chunks')->count())->toBe(1)
        ->and(DB::table('prc_estimates')->count())->toBe(1)
        ->and(DB::table('prc_document_versions')->where('id', $f->window->run->documentVersionId)->value('published_analysis_run_id'))->toBe($f->window->run->id->value)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and($this->server->receipts())->toHaveCount(1);
});

test('durable actual HTTP receipt после потерянного chunk commit восстанавливается общим periodic tick без второго send', function (): void {
    $f = new StructuredChainFixtures($this->server);
    $f->extract($f->lease); // Worker stops after durable receipt and before WorkFinalizer.
    $physical = DB::table('llm_provider_calls')->soleValue('physical_call_id');
    FinalizeFixtures::expire($f->work, 600);
    app()->forgetInstance(RecoveryReconciler::class);
    $daemon = app(ReconcileDaemon::class);
    foreach (app(RecoveryReconciler::class)->categories() as $category) {
        $daemon->register($category);
    }
    expect($daemon->tick()->errorCode)->toBeNull()
        ->and(ExecutionFixtures::work($f->work)->state)->toBe('ready');
    $lease = FinalizeFixtures::running($f->work);
    $plan = $lease->checkpoint?->providerRecoveryPlan;
    expect($plan)->toBeInstanceOf(ProviderRecoveryPlan::class)
        ->and($plan->mode)->toBe(ProviderRecoveryMode::ResumeFromReceipt)
        ->and($plan->physicalCallId->value)->toBe($physical);
    expect(app(WorkFinalizer::class)->finish($lease, $f->extract($lease))->state?->value)->toBe('ready');
    $f->publish();
    expect(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(1)
        ->and(DB::table('prc_estimates')->count())->toBe(1)
        ->and($this->server->receipts())->toHaveCount(1);
});

test('actual wire late put после committed blocked scan возобновляет тот же extraction и публикует единожды', function (): void {
    $f = new StructuredChainFixtures($this->server);
    $window = $f->window;
    $task = WindowExtractionTask::for($window, $window->range);
    $connection = app(AnalysisProviderConnections::class)->for($window->run->recipe->llm);
    $plan = new ProviderOperationPlan(new RequestIdentity($window->run->recipe->hash->hex, $window->subrangeInputHash($window->range)->hex,
        OperationPurpose::Generate, 0, $window->run->id->value), $window->run->recipe->llm, $connection->pricing, $f->work->value->value,
        generation: new ProviderGenerationInput($task->prompt, 'fixture late receipt', (new ProviderSchemaCompiler)->compile($task->schema), purpose: $task->purpose));
    $wire = app(StructuredCallExecutor::class)->compileGeneration($plan, $connection->origin);
    $operation = app(AnalysisCallCoordinator::class)->authorize($f->lease, $window->run->id, $plan, $f->period, $wire);
    $gate = ProviderSendFixtures::gateFor($f->lease, $window->run->id,
        new ProcessUuid(DB::table('prc_source_targets')->where('provider_key', 'fixture-provider')->soleValue('id')),
        new ProcessUuid(DB::table('prc_network_routes')->where('kind', 'direct')->soleValue('id')), $connection->profile, $this->server);
    $claim = $gate->claimSend($operation, $wire);
    expect($claim->transport)->not->toBeNull();
    $response = $claim->transport->sendOnce($wire);
    $gate->recordTransportOutcome($operation->physicalCallId, $response);
    expect($response->status)->toBe(200)->and($this->server->receipts())->toHaveCount(1)
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('reserved');
    // Recovery commits unknown before the already-sent response becomes a durable receipt.
    FinalizeFixtures::expire($f->work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($f->work)->state)->toBe('blocked')
        ->and(ExecutionFixtures::work($f->work)->reason_code)->toBe('provider_result_unknown');
    app(ReceiptStore::class)->put(app(RequestRepository::class)->get($operation->physicalCallId), $response->status, $response->body());
    expect(DB::table('llm_provider_calls')->sole()->receipt_key)->toBeNull();
    FinancialFixtures::due($f->work);
    app()->forgetInstance(RecoveryReconciler::class);
    $daemon = app(ReconcileDaemon::class);
    foreach (app(RecoveryReconciler::class)->categories() as $category) {
        $daemon->register($category);
    }
    expect($daemon->tick()->errorCode)->toBeNull()
        ->and(ExecutionFixtures::work($f->work)->state)->toBe('ready')
        ->and(ExecutionFixtures::work($f->work)->provider_recovery_due_at)->toBeNull();
    DB::table('prc_network_routes')->update(['enabled' => false]);
    $lease = FinalizeFixtures::running($f->work);
    expect($lease->checkpoint?->providerRecoveryPlan->physicalCallId->value)->toBe($operation->physicalCallId);
    expect(app(WorkFinalizer::class)->finish($lease, $f->extract($lease))->state?->value)->toBe('ready');
    $f->publish();
    expect($daemon->tick()->errorCode)->toBeNull()
        ->and(DB::table('prc_estimates')->count())->toBe(1)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('settled')
        ->and(DB::table('llm_budget_periods')->where('id', $f->period)->value('spent'))->toBe(DB::table('llm_budget_reservations')->sole()->upper_bound)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1)
        ->and($this->server->receipts())->toHaveCount(1);
});

test('invalid model JSON на actual wire оплачивается консервативно и исчерпывает один semantic reask без publication', function (): void {
    $f = new StructuredChainFixtures($this->server);
    $envelope = json_decode(StructuredChainFixtures::envelope(), true, flags: JSON_THROW_ON_ERROR);
    $envelope['candidates'][0]['content']['parts'][0]['text'] = 'not valid model JSON';
    $this->server->respond('/v1beta/models/fixture-model:generateContent', json_encode($envelope, JSON_THROW_ON_ERROR),
        headers: ['Content-Type' => 'application/json'], method: 'POST', replace: true);
    $lease = $f->lease;
    for ($ordinal = 0; $ordinal < 2; $ordinal++) {
        expect(app(WorkFinalizer::class)->finish($lease, $f->extract($lease))->state?->value)->toBe('ready');
        $lease = FinalizeFixtures::running($f->work);
    }
    expect(app(WorkFinalizer::class)->finish($lease, $f->extract($lease))->state?->value)->toBe('blocked')
        ->and(ExecutionFixtures::work($f->work)->reason_code)->toBe('ai_response_invalid')
        ->and(ExecutionFixtures::work($f->work)->item_failures)->toBe(0)
        ->and(DB::table('llm_provider_requests')->orderBy('semantic_reask_ordinal')->pluck('semantic_reask_ordinal')->all())->toBe([0, 1])
        ->and(DB::table('llm_provider_calls')->count())->toBe(2)
        ->and(DB::table('llm_budget_reservations')->where('state', 'settled')->count())->toBe(2)
        ->and(DB::table('llm_budget_periods')->where('id', $f->period)->value('spent'))->toBe('0.01419200')
        ->and(DB::table('llm_budget_periods')->where('id', $f->period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('prc_estimates')->count())->toBe(0)
        ->and($this->server->receipts())->toHaveCount(2);
});
