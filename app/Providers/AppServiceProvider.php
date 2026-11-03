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
use App\Models\User;
use App\Pulse\Cards\LlmSpendCard;
use App\Pulse\Cards\PipelineStagesCard;
use App\Pulse\Cards\ProcessPlatformHealthCard;
use App\Services\Llm\Contracts\PromptScopeRegistry;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Llm\TrackingUsageSource;
use App\Services\Platforms\Eis\Card\EisCardCatalog;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Acquisition\AcquisitionRuntime;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentRuntime;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Extraction\AnalysisConfiguration;
use App\Services\Procurement\Extraction\AnalysisFeeder;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\AnalysisProviderRecoveryResolver;
use App\Services\Procurement\Extraction\AnalysisRuntime;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Network\LegacyOutboundClient;
use App\Services\Procurement\Network\NetworkProbeReconcileCategory;
use App\Services\Procurement\Network\NetworkReconcileCategory;
use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\SourceTargetValidator;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

            return $policies;
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
        $this->app->bind(EisCardRoutes::class, static fn (): EisCardRoutes => (new EisCardCatalog)->routes());
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
        // Единственная реализация листинг-маппера в проекте (только ЕИС, `01-database.md`/`02-crawling.md`
        // не заводят второй источник в этом плане) — прямой bind без config-driven реестра (YAGNI).

        // Источник расхода LLM (P4.2, контракт `root/usage-source-contract.md`, D34): реализация
        // одна — «наш трекинг» поверх `llm_provider_calls`; второй (биллинг провайдера) нет и класса-заглушки
        // под неё не заводится, место второй реализации описано в контракте ТЕКСТОМ.
        $this->app->bind(UsageSource::class, TrackingUsageSource::class);

        // Реестр анализаторов типов документа (P2.3, §5.2 контракта): собирается из
        // `config('ai.analyzers')` при ПЕРВОМ резолве и дальше неизменен — отсюда singleton,
        // а не bind (иначе инварианты сборки проверялись бы на каждом резолве).
        $this->app->singleton(DocumentAnalyzerRegistry::class);
        $this->app->bind(PromptScopeRegistry::class, fn () => $this->app->make(DocumentAnalyzerRegistry::class));

        // Правила реакции краулера на отказы: типизированный разбор `Process controls and network policy`
        // один раз на процесс; резолвится в `boot()`, чтобы ошибка конфига валила старт.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

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
        // Кастомные Pulse-карточки (P7.5, D25) — классы вне `App\Livewire\*` (Files-список
        // item'а несёт `app/Pulse/**`), поэтому регистрация explicit, не Livewire-конвенцией.
        Livewire::component('pulse.pipeline-stages', PipelineStagesCard::class);
        Livewire::component('pulse.llm-spend', LlmSpendCard::class);
        Livewire::component('pulse.process-platform-health', ProcessPlatformHealthCard::class);

        // Pulse — отдельная страница за auth (Implementation Rules P7.5): ТОТ ЖЕ предикат, что
        // `User::canAccessPanel()` (super-admin или есть роль), а не «любой аутентифицированный» —
        // Pulse отдаёт SQL-текст медленных запросов, стектрейсы исключений и LLM-расход/стоимость,
        // это не менее чувствительно, чем сама Filament-панель (P7 phase-review, MED: предыдущая
        // версия открывала эти данные ЛЮБОМУ пользователю с аккаунтом, не только персоналу).
        Gate::define('viewPulse', fn (?User $user = null): bool => $user !== null && ($user->isSuperAdmin() || $user->getRoleNames()->isNotEmpty()));

        // Сквозной trace_id для КАЖДОЙ artisan-команды (P7.5, D25, Code Guidance: `cmd:<name>:
        // <uuid>`) — единый `CommandStarting`-листенер вместо правки каждой Procurement-команды
        // по отдельности (Required Reads item'а указывает на них лишь как на «точки старта»,
        // не как на цель редактирования; Files-список item'а их не несёт). `Context::add` здесь
        // же дегидрируется в payload любого job'а, диспатченного из команды.
        Event::listen(function (CommandStarting $event): void {
            Context::add('trace_id', 'cmd:'.$event->command.':'.(string) Str::uuid());
        });
    }
}
