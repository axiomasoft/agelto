<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Procurement\Ai\Execution\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Control\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\Definition\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Inputs\ExtractionWindowInput;
use App\Dto\Procurement\Process\Inputs\PublicationInput;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Models\User;
use App\Services\Llm\Execution\ProviderCodecs;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Llm\Providers\GeminiWireCodec;
use App\Services\Procurement\Extraction\Orchestration\ExtractionHandler;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\Publication\PublicationHandler;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\HistoricalRouteCatalog as RouteRepository;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;

/** Actual business handler -> finance -> network -> durable receipt -> chunk -> publication. */
final readonly class StructuredChainFixtures
{
    public WorkId $work;

    public ExecutionLease $lease;

    public AnalysisWindowSnapshot $window;

    public string $period;

    public function __construct(public WireFixture $server)
    {
        $context = new PinnedLlmContext('fixture-provider', 'fixture-account', 'v1beta', 'fixture-model', 'route/1',
            ['max_output_tokens' => 4096, 'temperature' => '0.25'], ['budget' => 128, 'include_thoughts' => false]);
        [$this->work, $this->lease, $this->window, $this->period] = ExtractionRuntimeFixtures::work(recipe: AiFixtures::recipe($context));
        // Synthetic provider/account, actual Gemini receipt classification. No fixture cost override.
        app(ReceiptPolicies::class)->register('fixture-provider', new GeminiAssetReceiptPolicy);
        app(ProviderCodecs::class)->register('fixture-provider', new GeminiWireCodec);
        $origin = $server->url('/')->origin;
        $profile = TransportFixtures::profile(['total_seconds' => 4]);
        app(AnalysisProviderConnections::class)->register(new AnalysisProviderConnection($context, $origin->value(),
            FinancialFixtures::pricing(), $this->period, $profile, 'fixture-only-key', $server->policy()));
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('fixture-provider'), [
            new SourceTargetDefinition('provider:fixture-provider:generate', 'provider', $origin, [$profile], new OriginBudgetPolicy(600, 1, 2)),
        ]));
        HistoricalNetworkFixtures::synchronize();
        $route = DB::table('prc_network_routes')->where('kind', 'direct')->soleValue('id');
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile(app(OutboundPolicy::class)->providerRouteProfile, $route));
        $key = app(OutboundPolicy::class)->providerRouteProfile;
        $version = (int) DB::table('prc_network_route_profiles')->where('profile_key', $key)->soleValue('policy_version');
        $actor = User::factory()->create()->id;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->updateProfile($key, $version, [$route], $actor,
            'Authorize local structured AI acceptance route', LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100_000]);
        ProxymaProviderFixtures::installTarget(DB::table('prc_source_targets')->where('provider_key', 'fixture-provider')->sole()->id, $origin->value());
        $server->respond('/v1beta/models/fixture-model:generateContent', self::envelope(),
            headers: ['Content-Type' => 'application/json'], method: 'POST');
    }

    public static function envelope(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ProcessCore/Llm/generation-response-v1.json')) ?: throw new \LogicException('Missing generation fixture.');
    }

    public function extract(ExecutionLease $lease): StageOutcome
    {
        return app(ExtractionHandler::class)->execute(new ExtractionWindowInput($this->window->run->id, $this->window->windowKey,
            $this->window->run->recipe->hash), app(ExecutionContextFactory::class)->open($lease));
    }

    public function publish(): void
    {
        $lease = FinalizeFixtures::running($this->work);
        expect(app(WorkFinalizer::class)->finish($lease, $this->extract($lease))->state?->value)->toBe('succeeded');
        $publication = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));
        $lease = FinalizeFixtures::running($publication);
        $input = new PublicationInput($this->window->run->id, $this->window->run->recipe->hash, $this->window->run->generation);
        $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
        expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('succeeded')
            ->and(app(WorkFinalizer::class)->finish($lease, $outcome)->status->value)->toBe('lost_ownership');
    }
}
