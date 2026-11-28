<?php

declare(strict_types=1);

namespace App\Providers;

use App\Doctor\Diagnostics\ApplicationReadinessIsGreen;
use App\Doctor\Diagnostics\BaseDataIsReady;
use App\Doctor\Diagnostics\InitialAdminIsReady;
use App\Doctor\Diagnostics\PrivatePackagesArePinned;
use App\Doctor\Diagnostics\SecretContractIsReady;
use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\User;
use App\Pulse\Cards\LlmSpendCard;
use App\Pulse\Cards\PipelineStagesCard;
use App\Pulse\Cards\ProcessPlatformHealthCard;
use App\Services\Health\ApplicationReadiness;
use App\Services\Llm\Contracts\PromptScopeRegistry;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Execution\ProviderCodecs;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Llm\Providers\GeminiWireCodec;
use App\Services\Llm\Providers\OpenAiChatCompletionsClient;
use App\Services\Llm\Providers\OpenAiReceiptPolicy;
use App\Services\Llm\TrackingUsageSource;
use App\Services\Platforms\Eis\Card\EisCardCatalog;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Acquisition\Detail\DetailValueCodec;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionRuntime;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentRuntime;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Extraction\Orchestration\AnalysisConfiguration;
use App\Services\Procurement\Extraction\Orchestration\AnalysisFeeder;
use App\Services\Procurement\Extraction\Orchestration\AnalysisRuntime;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderRecoveryResolver;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Network\LegacyOutboundClient;
use App\Services\Procurement\Network\NetworkProbeReconcileCategory;
use App\Services\Procurement\Network\NetworkReconcileCategory;
use App\Services\Procurement\Parsing\Engine\InMemoryDriftCollector;
use App\Services\Procurement\Parsing\Engine\UniversalCardUrlRouter;
use App\Services\Procurement\Parsing\Engine\UniversalCsvReader;
use App\Services\Procurement\Parsing\Engine\UniversalDocumentListExtractor;
use App\Services\Procurement\Parsing\Engine\UniversalDomExtractor;
use App\Services\Procurement\Parsing\Engine\UniversalEventTableExtractor;
use App\Services\Procurement\Parsing\Engine\UniversalJsonExtractor;
use App\Services\Procurement\Parsing\Engine\UniversalTabularSummaryMapper;
use App\Services\Procurement\Parsing\ParsingRuleSetResolver;
use App\Services\Procurement\Parsing\Schema\ParsingRuleSchemaRegistry;
use App\Services\Procurement\Platform\Contracts\CardUrlRouter;
use App\Services\Procurement\Platform\Contracts\DocumentListExtractor;
use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use App\Services\Procurement\Platform\Contracts\DriftCollector;
use App\Services\Procurement\Platform\Contracts\EventTableExtractor;
use App\Services\Procurement\Platform\Contracts\JsonPayloadExtractor;
use App\Services\Procurement\Platform\Contracts\TabularReader;
use App\Services\Procurement\Platform\Contracts\TabularSummaryMapper;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\Daemons\FeederDaemon;
use App\Services\Procurement\Process\Daemons\ReconcileDaemon;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\SourceTargetValidator;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;
use Laravel\Doctor\Facades\Doctor;
use Livewire\Livewire;
use Tests\Support\TestEnvironmentGuard;
use Tests\Support\TestFilesystemIsolation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ReceiptPolicies::class, static function (): ReceiptPolicies {
            $policies = new ReceiptPolicies;
            $policies->register('gemini', new GeminiAssetReceiptPolicy);
            $policies->register('openai', new OpenAiReceiptPolicy);
            $policies->register('openai_compatible', new OpenAiReceiptPolicy);

            return $policies;
        });
        $this->app->singleton(ProviderCodecs::class, static function (): ProviderCodecs {
            $codecs = new ProviderCodecs;
            $codecs->register('gemini', new GeminiWireCodec);
            $codecs->register('openai', new OpenAiChatCompletionsClient);
            $codecs->register('openai_compatible', new OpenAiChatCompletionsClient);

            return $codecs;
        });
        $this->app->singleton(AnalysisProviderConnections::class, static function (): AnalysisProviderConnections {
            $connections = new AnalysisProviderConnections;
            app(AnalysisConfiguration::class)->provision($connections);

            return $connections;
        });
        $this->app->bind(ProviderRecoveryResolver::class, AnalysisProviderRecoveryResolver::class);
        $this->app->bind(EisSourceClock::class, fn () => new EisSourceClock($this->app->make(EisPolicy::class)->sourceTimezone->getName()));
        $this->app->bind(DocumentUrlPolicy::class,
            SourceTargetValidator::class);
        $this->app->singleton(ParsingRuleSchemaRegistry::class);
        $this->app->singleton(ParsingRuleSetResolver::class);
        $this->app->bind(TabularReader::class, UniversalCsvReader::class);
        $this->app->bind(DomExtractor::class, UniversalDomExtractor::class);
        $this->app->bind(TabularSummaryMapper::class, UniversalTabularSummaryMapper::class);
        $this->app->bind(CardUrlRouter::class, UniversalCardUrlRouter::class);
        $this->app->bind(DocumentListExtractor::class, UniversalDocumentListExtractor::class);
        $this->app->bind(EventTableExtractor::class, UniversalEventTableExtractor::class);
        $this->app->bind(JsonPayloadExtractor::class, UniversalJsonExtractor::class);
        $this->app->bind(DriftCollector::class, InMemoryDriftCollector::class);
        $this->app->bind(EisCardRoutes::class, static function (Application $app): EisCardRoutes {
            $catalog = new EisCardCatalog;
            $procedures = $catalog->cardUrlTemplates();
            $procedureTypes = $catalog->procedureUrlTypes();

            if ($app->bound(DetailRuleSetDto::class)) {
                $routes = $app->make(DetailRuleSetDto::class)->routes;
                if ($routes !== null) {
                    $baseOrigin = 'https://zakupki.gov.ru';
                    $baseHost = 'zakupki.gov.ru';
                    $resolveTab = static function (?string $path) use ($baseOrigin, $baseHost): ?string {
                        if ($path === null || substr_count($path, '{regNumber}') !== 1) {
                            return null;
                        }
                        if (str_starts_with($path, 'https://')) {
                            $host = parse_url($path, PHP_URL_HOST);

                            return is_string($host) && strcasecmp($host, $baseHost) === 0
                                ? $path
                                : null;
                        }
                        if (str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
                            return $baseOrigin.$path;
                        }

                        return null;
                    };

                    $overrides = [
                        'common-info' => $resolveTab($routes->commonInfoPath),
                        'documents' => $resolveTab($routes->documentsPath),
                        'event-journal' => $resolveTab($routes->journalPath),
                        'supplier-results' => $resolveTab($routes->supplierResultsPath),
                    ];

                    foreach ($procedures as $procedure => $tabs) {
                        foreach ($overrides as $tab => $url) {
                            if ($url !== null && ($tabs[$tab] ?? null) !== null) {
                                $procedures[$procedure][$tab] = str_replace('{type}', $procedure, $url);
                            }
                        }
                    }
                }
            }

            return new EisCardRoutes($procedures, $procedureTypes);
        });
        $this->app->singleton(DetailValueCodec::class, fn (): DetailValueCodec => $this->app->make(AcquisitionRuntime::class)->values());
        $this->app->afterResolving(StageRegistry::class, function (StageRegistry $registry): void {
            $this->app->make(AcquisitionRuntime::class)->register($registry, $this->app->make(DetailValueCodec::class));
        });
        $this->app->afterResolving(StageRegistry::class, function (StageRegistry $registry): void {
            $this->app->make(DocumentRuntime::class)->register($registry);
            $this->app->make(AnalysisRuntime::class)->register($registry);
        });
        $this->app->afterResolving(FeederDaemon::class,
            static function (FeederDaemon $daemon): void {
                $daemon->registerProducer(app(DocumentFeeder::class));
                $daemon->registerProducer(app(AnalysisFeeder::class));
            });
        $this->app->bind(LegacyOutbound::class, LegacyOutboundClient::class);
        $this->app->afterResolving(ReconcileDaemon::class,
            static function (ReconcileDaemon $daemon): void {
                $daemon->register(app(DocumentStorageReconciler::class));
                $daemon->register(app(NetworkReconcileCategory::class));
                $daemon->register(app(NetworkProbeReconcileCategory::class));
            });
        // Источник расхода LLM читает внутренний tracking из llm_provider_calls.
        $this->app->bind(UsageSource::class, TrackingUsageSource::class);

        // Registry собирается из ai.analyzers и проверяет инварианты один раз при первом resolve.
        $this->app->singleton(DocumentAnalyzerRegistry::class);
        $this->app->bind(PromptScopeRegistry::class, fn () => $this->app->make(DocumentAnalyzerRegistry::class));

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap(['user' => User::class]);

        if ($this->app->environment('production') && (
            (bool) config('app.debug') !== false
            || in_array(config('queue.default'), ['sync', 'null'], true)
            || in_array(config('cache.default'), ['array', 'null'], true)
            || in_array(config('session.driver'), ['array', 'null'], true)
        )) {
            throw new \RuntimeException('Unsafe production configuration detected.');
        }

        $appUrl = config('app.url');
        if ($this->app->environment('production')
            && is_string($appUrl)
            && str_starts_with(strtolower($appUrl), 'https://')
            && config('session.secure') !== true) {
            throw new \RuntimeException('SESSION_SECURE_COOKIE must be true when APP_URL uses HTTPS in production.');
        }

        if ($this->app->environment('testing')) {
            ParallelTesting::setUpProcess(function (string $token): void {
                TestFilesystemIsolation::prepare(process: $token);
            });

            ParallelTesting::tearDownProcess(function (string $token): void {
                TestFilesystemIsolation::cleanup(process: $token);
            });

            ParallelTesting::setUpTestDatabaseBeforeMigrating(function (string $database, string $token): void {
                TestEnvironmentGuard::assertParallelTestDatabase(
                    app: $this->app,
                    database: $database,
                    token: $token,
                );
            });
        }

        if (class_exists(Doctor::class)) {
            Doctor::diagnostics([
                PrivatePackagesArePinned::class,
                SecretContractIsReady::class,
                BaseDataIsReady::class,
                InitialAdminIsReady::class,
                ApplicationReadinessIsGreen::class,
            ]);
        }
        // Pulse-карточки находятся вне App\Livewire и требуют явной регистрации компонентов.
        Livewire::component('pulse.pipeline-stages', PipelineStagesCard::class);
        Livewire::component('pulse.llm-spend', LlmSpendCard::class);
        Livewire::component('pulse.process-platform-health', ProcessPlatformHealthCard::class);

        // Pulse — отдельная страница за auth: отдаёт SQL-текст медленных запросов,
        // стектрейсы исключений и LLM-расход/стоимость, поэтому доступ разрешён только
        // суперадминистраторам и операционным ролям (OperatorRole), но не read-only бизнес-ролям.
        Gate::define('viewPulse', fn (?User $user = null): bool => $user !== null && ($user->isSuperAdmin() || $user->hasRole(OperatorRole::class)));

        // Каждая artisan-команда получает свежий 32-hex trace_id и command в Context;
        // стек восстанавливает предыдущий контекст при завершении вложенной или последовательной команды.
        /** @var list<array{trace_id: mixed, has_trace_id: bool, command: mixed, has_command: bool}> $commandContextStack */
        $commandContextStack = [];
        Event::listen(function (CommandStarting $event) use (&$commandContextStack): void {
            $commandContextStack[] = [
                'trace_id' => Context::get('trace_id'),
                'has_trace_id' => Context::has('trace_id'),
                'command' => Context::get('command'),
                'has_command' => Context::has('command'),
            ];
            Context::add('command', $event->command);
            Context::add('trace_id', bin2hex(random_bytes(16)));

            if ($event->command === 'schedule:work') {
                try {
                    ApplicationReadiness::recordSchedulerHeartbeat();
                } catch (\Throwable) {
                    // Best-effort initial heartbeat; readiness check will report unavailable if cache is down.
                }
            }
        });
        Event::listen(function (CommandFinished $event) use (&$commandContextStack): void {
            $previous = array_pop($commandContextStack);
            if ($previous === null) {
                Context::forget('trace_id');
                Context::forget('command');

                return;
            }
            if ($previous['has_trace_id'] && $previous['trace_id'] !== null) {
                Context::add('trace_id', $previous['trace_id']);
            } else {
                Context::forget('trace_id');
            }
            if ($previous['has_command'] && $previous['command'] !== null) {
                Context::add('command', $previous['command']);
            } else {
                Context::forget('command');
            }
        });
    }
}
