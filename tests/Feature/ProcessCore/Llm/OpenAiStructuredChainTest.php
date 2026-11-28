<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Procurement\Ai\Execution\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Process\Inputs\ExtractionWindowInput;
use App\Dto\Procurement\Process\Inputs\PublicationInput;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;
use App\Models\Llm\Provider;
use App\Models\User;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisPlanRepository;
use App\Services\Llm\Execution\ProviderCodecs;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Providers\OpenAiChatCompletionsClient;
use App\Services\Llm\Providers\OpenAiReceiptPolicy;
use App\Services\Procurement\Extraction\Orchestration\ExtractionHandler;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\Publication\PublicationHandler;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\Daemons\ReconcileDaemon;
use App\Services\Procurement\Process\Daemons\RecoveryReconciler;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Contracts\BindingCommands;
use Proxyma\Contracts\DestinationResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Http\DnsResolver;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\HistoricalRouteCatalog;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PageRuntimeFixtures;
use Tests\Support\ProcessCore\ProxymaProviderFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('structured-extraction-contract', 'provider-and-worker-recovery', 'analysis-execution', 'provider-financial-ledger', 'provider-send-fencing');

beforeEach(function (): void {
    $this->server = new WireFixture;
});

afterEach(function (): void {
    $this->server->stop();
});

function openAiEnvelope(string $content, string $finishReason = 'stop', string $model = 'gpt-4.1-mini-2025-04-14'): string
{
    return json_encode([
        'id' => 'chatcmpl-'.bin2hex(random_bytes(4)),
        'object' => 'chat.completion',
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'finish_reason' => $finishReason,
            'message' => [
                'role' => 'assistant',
                'content' => $content,
            ],
        ]],
        'usage' => [
            'prompt_tokens' => 1000,
            'prompt_tokens_details' => ['cached_tokens' => 200],
            'completion_tokens' => 500,
            'completion_tokens_details' => ['reasoning_tokens' => 100],
        ],
    ], JSON_THROW_ON_ERROR);
}

/** @param list<array{int,int}> $positions */
function openAiPageExtractionJson(int $ordinal, PageRange $range, array $positions): string
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

    return json_encode([
        'rows' => $rows,
        'totals' => [],
        'covered_pages' => range($range->start, $range->end - 1),
    ], JSON_THROW_ON_ERROR);
}

function provisionOpenAiWire(WireFixture $server, string $period): PinnedLlmContext
{
    $context = new PinnedLlmContext(
        provider: 'openai',
        account: 'fixture-account',
        apiVersion: 'v1',
        model: 'gpt-4.1-mini',
        routePolicyVersion: 'config/1',
        options: ['max_output_tokens' => 4096],
        thinking: [],
    );
    $pricing = new PricingSnapshot(
        currency: 'USD',
        version: 'catalog/1',
        purpose: OperationPurpose::Generate,
        inputMaxTokens: 128000,
        outputMaxTokens: 4096,
        thinkingMaxTokens: 4096,
        thinkingIncludedInOutput: true,
        inputPerMillion: 400_000,
        outputPerMillion: 1_600_000,
        thinkingPerMillion: 1_600_000,
        fixedCharge: 0,
        cacheCreateCharge: 0,
        storageTokenHoursMax: 0,
        storagePerTokenHour: 0,
        boundEvidence: 'config:llm.model_pricing:gpt-4.1-mini',
        validUntil: '2027-12-31T23:59:59Z',
        cachedInputPerMillion: 100_000,
    );
    $origin = $server->url('/')->origin;
    $profile = TransportFixtures::profile(['total_seconds' => 4]);

    $existingProvider = Provider::query()->where('code', 'openai')->first();
    if ($existingProvider === null) {
        Provider::factory()->create(['code' => 'openai', 'is_active' => true]);
    } else {
        DB::table('llm_providers')->where('code', 'openai')->update(['is_active' => true]);
    }
    app(ReceiptPolicies::class)->register('openai', new OpenAiReceiptPolicy);
    app(ProviderCodecs::class)->register('openai', new OpenAiChatCompletionsClient);
    app(AnalysisProviderConnections::class)->register(
        new AnalysisProviderConnection($context, $origin->value(), $pricing, $period, $profile, 'sk-test-openai-secret', $server->policy()),
        preferred: true,
    );
    app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(
        new ProviderKey('openai'),
        [new SourceTargetDefinition('provider:openai:generate', 'provider', $origin, [$profile], new OriginBudgetPolicy(600, 1, 2))],
    ));
    app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(
        new ProviderKey('fixture-provider'),
        [new SourceTargetDefinition('provider:fixture-provider:generate', 'provider', $origin, [$profile], new OriginBudgetPolicy(600, 1, 2))],
    ));
    HistoricalNetworkFixtures::synchronize();
    $route = DB::table('prc_network_routes')->where('kind', 'direct')->soleValue('id');
    app(ProcessTransaction::class)->run(fn () => app(HistoricalRouteCatalog::class)->ensureProfile(app(OutboundPolicy::class)->providerRouteProfile, $route));
    $key = app(OutboundPolicy::class)->providerRouteProfile;
    $version = (int) DB::table('prc_network_route_profiles')->where('profile_key', $key)->soleValue('policy_version');
    $actor = User::factory()->create()->id;
    app(ProcessTransaction::class)->run(fn () => app(HistoricalRouteCatalog::class)->updateProfile(
        $key,
        $version,
        [$route],
        $actor,
        'Authorize local OpenAI structured chain test route',
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
    ));
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        DB::table($table)->update(['state' => 'closed']);
    }
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 100_000]);
    $openaiTargetId = DB::table('prc_source_targets')->where('provider_key', 'openai')->sole()->id;
    ProxymaProviderFixtures::installTarget($openaiTargetId, $origin->value());
    $tenant = TenantKey::system();
    $profileId = Uuid::fromString(
        DB::table(PackageSchema::tables()['route_profiles'])->soleValue('id'),
    );
    app(BindingCommands::class)->bindPolicy(
        new BindSubjectPolicyCommand(
            new SubjectRef($tenant, 'provider', 'openai'),
            $profileId,
            'openai',
            new CatalogMutationActor(
                new ActorRef('worker', 'fixture-provider'),
                'bind openai provider subject',
                0,
                Uuid::random()->value,
            ),
            panelKey: 'agelto.provider',
            slotKey: 'llm',
        ),
    );
    $canonicalOrigin = CanonicalOrigin::fromUrl($origin->value());
    $dns = new DnsResolver(
        app(ConnectionScope::class),
        static fn (): array => ['127.0.0.1'],
    );
    app()->instance(
        DestinationResolver::class,
        new DestinationPolicy(
            $dns,
            [$openaiTargetId => [$canonicalOrigin->host => ['127.0.0.1']]],
            [$canonicalOrigin->port],
        ),
    );

    return $context;
}

test('полный конвейер OpenAI выполняет screening, discovery, split при length, 1x reask, расчёт cached/reasoning стоимости и публикацию сметы', function (): void {
    $context = new PinnedLlmContext('openai', 'fixture-account', 'v1', 'gpt-4.1-mini', 'config/1', ['max_output_tokens' => 4096], []);
    [$screeningWork, $lease, $run, $period] = PageRuntimeFixtures::work($context);
    provisionOpenAiWire($this->server, $period);

    // 1. Screening response
    $this->server->respond('/v1/chat/completions', openAiEnvelope(ScreeningRuntimeFixtures::raw()), headers: ['Content-Type' => 'application/json'], method: 'POST');
    // 2. Discovery response (4 pages -> 1 estimate across pages 0..3)
    $this->server->respond('/v1/chat/completions', openAiEnvelope(json_encode([
        'pages' => [
            ['page_index' => 0, 'estimate_ordinal' => 1],
            ['page_index' => 1, 'estimate_ordinal' => 1],
            ['page_index' => 2, 'estimate_ordinal' => 1],
            ['page_index' => 3, 'estimate_ordinal' => 1],
        ],
        'ambiguous_pages' => [],
    ], JSON_THROW_ON_ERROR)), headers: ['Content-Type' => 'application/json'], method: 'POST');

    // Window 1 [0, 2]:
    // 3. MAX_TOKENS (finish_reason=length) -> splits [0, 2] into [0, 1] and [1, 2]
    $this->server->respond('/v1/chat/completions', openAiEnvelope('{"rows":[', finishReason: 'length'), headers: ['Content-Type' => 'application/json'], method: 'POST');
    // 4. [0, 1] semantic defect (wrong page_index=2) -> triggers 1x reask
    $this->server->respond('/v1/chat/completions', openAiEnvelope(openAiPageExtractionJson(1, new PageRange(0, 1), [[2, 0]])), headers: ['Content-Type' => 'application/json'], method: 'POST');
    // 5. [0, 1] reask ordinal=1 -> valid
    $this->server->respond('/v1/chat/completions', openAiEnvelope(openAiPageExtractionJson(1, new PageRange(0, 1), [[0, 0]])), headers: ['Content-Type' => 'application/json'], method: 'POST');
    // 6. [1, 2] -> valid
    $this->server->respond('/v1/chat/completions', openAiEnvelope(openAiPageExtractionJson(1, new PageRange(1, 2), [[1, 0]])), headers: ['Content-Type' => 'application/json'], method: 'POST');

    // Window 2 [2, 4]:
    // 7. [2, 4] -> valid
    $this->server->respond('/v1/chat/completions', openAiEnvelope(openAiPageExtractionJson(1, new PageRange(2, 4), [[2, 0], [3, 0]])), headers: ['Content-Type' => 'application/json'], method: 'POST');

    // Execute Screening + Discovery
    $screeningInput = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $runScreening = static fn ($currentLease) => app(WorkFinalizer::class)->finish(
        $currentLease,
        app(ScreeningHandler::class)->execute($screeningInput, app(ExecutionContextFactory::class)->open($currentLease)),
    );
    $runScreening($lease); // screening HTTP call
    $runScreening(FinalizeFixtures::running($screeningWork)); // discovery HTTP call
    expect($runScreening(FinalizeFixtures::running($screeningWork))->state?->value)->toBe('succeeded');

    // Execute Extraction windows
    $plans = app(AnalysisPlanRepository::class)->plans($run);
    expect($plans)->toHaveCount(1);
    foreach ($plans[0]->windows as $index => $window) {
        $workId = DB::table('prc_process_work')
            ->where('stage', 'extraction')
            ->where('input_payload->analysis_run_id', $run->id->value)
            ->where('input_payload->window_key', $window->windowKey)
            ->soleValue('id');
        $work = new WorkId(new ProcessUuid($workId));
        $input = new ExtractionWindowInput($run->id, $window->windowKey, $run->recipe->hash);
        $step = static fn ($currentLease) => app(WorkFinalizer::class)->finish(
            $currentLease,
            app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($currentLease)),
        );

        $finalState = null;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $outcome = $step(FinalizeFixtures::running($work));
            $finalState = $outcome->state?->value;
            if ($finalState === 'succeeded') {
                break;
            }
        }
        expect($finalState)->toBe('succeeded');
    }

    // Publish
    $pubWork = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));
    $pubLease = FinalizeFixtures::running($pubWork);
    $pubOutcome = app(PublicationHandler::class)->execute(
        new PublicationInput($run->id, $run->recipe->hash, $run->generation),
        app(ExecutionContextFactory::class)->open($pubLease),
    );
    expect(app(WorkFinalizer::class)->finish($pubLease, $pubOutcome)->state?->value)->toBe('succeeded');

    // Verify wire headers, ledger calls, cached/reasoning tokens, and prc_estimates publication
    $wireReceipts = $this->server->receipts();
    expect($wireReceipts)->toHaveCount(7);
    foreach ($wireReceipts as $receipt) {
        $headers = array_change_key_case($receipt['headers'], CASE_LOWER);
        expect($headers['authorization'] ?? null)->toBe('Bearer sk-test-openai-secret');
    }

    $calls = DB::table('llm_provider_calls')->get();
    expect($calls)->toHaveCount(7);
    $firstCall = app(RequestRepository::class)->get($calls[0]->physical_call_id);
    expect($firstCall->resolvedModelVersion ?? $calls[0]->resolved_model_version)->toBe('gpt-4.1-mini-2025-04-14')
        ->and($firstCall->recordedAssessment?->usage?->uncachedInput)->toBe(800)
        ->and($firstCall->recordedAssessment?->usage?->cachedInput)->toBe(200)
        ->and($firstCall->recordedAssessment?->usage?->output)->toBe(400)
        ->and($firstCall->recordedAssessment?->usage?->thinking)->toBe(100)
        ->and(DB::table('llm_budget_reservations')->where('state', 'settled')->count())->toBe(7)
        ->and(DB::table('prc_estimates')->count())->toBe(1);
});

test('восстановление после сбоя OpenAI возобновляет из квитанции ResumeFromReceipt без повторного HTTP-запроса', function (): void {
    $context = new PinnedLlmContext('openai', 'fixture-account', 'v1', 'gpt-4.1-mini', 'config/1', ['max_output_tokens' => 4096], []);
    [$screeningWork, $lease, $run, $period] = PageRuntimeFixtures::work($context);
    provisionOpenAiWire($this->server, $period);

    // Screening call succeeds, but worker crashes BEFORE WorkFinalizer::finish()
    $this->server->respond('/v1/chat/completions', openAiEnvelope(ScreeningRuntimeFixtures::raw()), headers: ['Content-Type' => 'application/json'], method: 'POST');
    $screeningInput = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $firstOutcome = app(ScreeningHandler::class)->execute($screeningInput, app(ExecutionContextFactory::class)->open($lease));
    expect($firstOutcome)->toBeInstanceOf(StagePartial::class);

    $physicalCallId = DB::table('llm_provider_calls')->soleValue('physical_call_id');
    FinalizeFixtures::expire($screeningWork, 600);

    app()->forgetInstance(RecoveryReconciler::class);
    $daemon = app(ReconcileDaemon::class);
    foreach (app(RecoveryReconciler::class)->categories() as $category) {
        $daemon->register($category);
    }
    expect($daemon->tick()->errorCode)->toBeNull()
        ->and(ExecutionFixtures::work($screeningWork)->state)->toBe('ready');

    $resumedLease = FinalizeFixtures::running($screeningWork);
    $plan = $resumedLease->checkpoint?->providerRecoveryPlan;
    expect($plan)->toBeInstanceOf(ProviderRecoveryPlan::class)
        ->and($plan->mode)->toBe(ProviderRecoveryMode::ResumeFromReceipt)
        ->and($plan->physicalCallId->value)->toBe($physicalCallId);

    $resumedOutcome = app(ScreeningHandler::class)->execute($screeningInput, app(ExecutionContextFactory::class)->open($resumedLease));
    app(WorkFinalizer::class)->finish($resumedLease, $resumedOutcome);

    expect($this->server->receipts())->toHaveCount(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1);
});
