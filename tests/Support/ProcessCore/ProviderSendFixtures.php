<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\TaskPurpose;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Providers\GeminiGenerateContentClient;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\AnalysisProviderSendGate;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxyma\CallerSendFacts;
use App\Services\Procurement\Screening\ScreeningContract;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;

/** Real Process/financial/network owners. Only model response and clock mutations are fixtures. */
final class ProviderSendFixtures
{
    public WorkId $work;

    public ExecutionLease $lease;

    public ProcessUuid $run;

    public ProviderOperationPlan $plan;

    public ProviderWireRequest $wire;

    public AuthorizedProviderOperation $operation;

    public ProcessUuid $target;

    public ProcessUuid $route;

    public RequestProfile $profile;

    public string $period;

    /**
     * @param  (\Closure(ProviderOperationPlan, string): array{ProviderOperationPlan, ProviderWireRequest})|null  $operationFactory
     * @param  (\Closure(): void)|null  $configureRuntime
     */
    public function __construct(public WireFixture $server, bool $tls = false, ?\Closure $operationFactory = null, ?\Closure $configureRuntime = null)
    {
        PermitFixtures::clean();
        $period = FinancialFixtures::reset();
        $configureRuntime?->__invoke();
        $this->period = $period;
        $context = new PinnedLlmContext('fixture-provider', 'fixture-account', 'v1beta', 'fixture-model', 'route/1',
            ['max_output_tokens' => 4096, 'temperature' => '0.25'], ['budget' => 128, 'include_thoughts' => false]);
        $window = AiFixtures::window($context);
        $this->run = $window->run->id;
        $this->work = new WorkId(LedgerFixtures::uuid());
        $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
        app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork($this->work, $input,
            $window->run->expectedAnalysisGeneration, null, 'manual', null, null, 100, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        $this->lease = FinalizeFixtures::running($this->work);
        $snapshot = $window->run->recipe->tasks->task(TaskPurpose::Screening);
        $task = (new ScreeningContract)->task($snapshot->prompt);
        $generation = new ProviderGenerationInput($task->prompt, 'fixture untrusted document', (new ProviderSchemaCompiler)->compile($task->schema), purpose: $task->purpose);
        $this->plan = new ProviderOperationPlan(new RequestIdentity($window->run->recipe->hash->hex, $window->run->screeningInputHash()->hex, OperationPurpose::Generate, 0, $this->run->value),
            $context, FinancialFixtures::pricing(), $this->work->value->value, generation: $generation);
        $origin = $server->url('/', $tls)->origin;
        $this->wire = (new GeminiGenerateContentClient($origin->value()))->compile($this->plan);
        if ($operationFactory !== null) {
            [$this->plan, $this->wire] = $operationFactory($this->plan, $origin->value());
        }
        $this->operation = app(AnalysisCallCoordinator::class)->authorize($this->lease, $this->run, $this->plan, $period, $this->wire);
        $this->profile = TransportFixtures::profile(['total_seconds' => 4]);
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('fixture-provider'), [
            new SourceTargetDefinition('provider:fixture-provider:generate', 'provider', $origin, [$this->profile], new OriginBudgetPolicy(600, 1, 2)),
        ]));
        HistoricalNetworkFixtures::synchronize();
        $this->target = new ProcessUuid(DB::table('prc_source_targets')->where('provider_key', 'fixture-provider')->sole()->id);
        $this->route = new ProcessUuid(DB::table('prc_network_routes')->where('kind', 'direct')->sole()->id);
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100_000]);
        $server->respond('/v1beta/models/fixture-model:generateContent', FinancialFixtures::body(), headers: ['Content-Type' => 'application/json'], method: 'POST');
    }

    public function gate(): AnalysisProviderSendGate
    {
        if (! CallerSendFacts::active()) {
            new ProxymaProviderFixtures($this);
        }

        return self::gateFor($this->lease, $this->run, $this->target, $this->route, $this->profile, $this->server);
    }

    public static function gateFor(ExecutionLease $lease, ProcessUuid $run, ProcessUuid $target, ProcessUuid $route, RequestProfile $profile, WireFixture|SafeUrl $server, ?ExecutionContext $context = null): AnalysisProviderSendGate
    {
        return app()->make(AnalysisProviderSendGate::class, ['context' => $context ?? app(ExecutionContextFactory::class)->open($lease),
            'runId' => $run, 'target' => $target, 'profile' => $profile, 'urlPolicy' => $server instanceof WireFixture ? $server->policy() : new UrlPolicy([$server->origin], ['fixture.test' => ['127.0.0.1']]),
            'apiKey' => 'fixture-only-key']);
    }

    public function worker(string $action): string
    {
        if (! CallerSendFacts::active()) {
            new ProxymaProviderFixtures($this);
        }
        $data = base64_encode(serialize([$this->lease, $this->run, $this->target, $this->route, $this->profile, $this->server->url('/'), $this->wire, $this->operation]));

        return '\\Tests\\Support\\ProcessCore\\FinalizeFixtures::bind(\\App\\Enums\\Procurement\\Process\\StageCode::Screening);'
            .'[$lease,$run,$target,$route,$profile,$server,$wire,$operation]=unserialize(base64_decode('.var_export($data, true).'));'
            .'\\Tests\\Support\\ProcessCore\\ProxymaProviderFixtures::configure();'
            .'$gate=\\Tests\\Support\\ProcessCore\\ProviderSendFixtures::gateFor($lease,$run,$target,$route,$profile,$server);'
            .$action;
    }
}
