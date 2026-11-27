<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Procurement\Proxyma\Transport\AgeltoPhysicalHttpTransport;
use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\AttemptObservers;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Extensions\ExtensionConfiguration;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\DestinationResolver;
use Proxyma\Contracts\OutcomePolicy;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Contracts\PolicyResolver as PolicyResolverContract;
use Proxyma\Contracts\SecretResolver;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Telemetry\OperationsTelemetry;
use Proxyma\Laravel\Console;
use Proxyma\Laravel\ProxymaServiceProvider;

/** Package services with Agelto-owned scheduling and scoped extension definitions. */
final class InactiveProxymaServiceProvider extends ProxymaServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(PolicyResolver::class, static function (Application $app): PolicyResolver {
            $configuration = $app->make(ConfigRepository::class);
            $config = $configuration->get('proxyma.policy', []);
            $config = is_array($config) ? $config : [];
            $connection = $configuration->get('proxyma.connection', 'pgsql');
            /** @var array<string, mixed> $projectDefaults */
            $projectDefaults = is_array($config['project_defaults'] ?? null) ? $config['project_defaults'] : [];
            /** @var array<string, array<string, mixed>> $panelDefaults */
            $panelDefaults = is_array($config['panel_defaults'] ?? null) ? $config['panel_defaults'] : [];
            /** @var array<string, mixed> $hardBounds */
            $hardBounds = is_array($config['hard_bounds'] ?? null) ? $config['hard_bounds'] : [];

            return new PolicyResolver(
                db: $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : 'pgsql'),
                access: $app->make(CatalogAccess::class),
                registry: null,
                cache: $app->make(PolicyResolutionCache::class),
                registryResolver: static fn (): SettingRegistry => $app->make(SettingRegistry::class),
                policyConfigResolver: static function () use ($app): array {
                    $value = $app->make(ConfigRepository::class)->get('proxyma.policy', []);
                    /** @var array<string, mixed> $policy */
                    $policy = is_array($value) ? $value : [];

                    return $policy;
                },
                projectDefaults: $projectDefaults,
                panelDefaults: $panelDefaults,
                hardBounds: $hardBounds,
                defaultPanelKey: is_string($config['default_panel'] ?? null) ? $config['default_panel'] : 'default',
                definitions: $app->make(DefinitionRegistry::class),
            );
        });
        $this->app->bind(PhysicalTransport::class, static fn (Application $app, array $parameters): AgeltoPhysicalHttpTransport => new AgeltoPhysicalHttpTransport(
            $app->make(ConnectionScope::class), $app->make(WireSealer::class),
            is_int($parameters['maxResponseBytes'] ?? null) ? $parameters['maxResponseBytes'] : 67_108_864,
            ($parameters['cancelled'] ?? null) instanceof \Closure ? $parameters['cancelled'] : null,
        ));
        $this->app->bind(OutboundExecutor::class, static fn (Application $app, array $parameters): OutboundExecutor => new OutboundExecutor(
            $app->make(ConnectionScope::class), $app->make(CatalogQueries::class),
            ($parameters['policies'] ?? null) instanceof PolicyResolverContract ? $parameters['policies'] : $app->make(PolicyResolverContract::class),
            $app->make(DestinationResolver::class), $app->make(RouteSelector::class),
            $app->make(ProxyResolver::class), $app->make(SecretResolver::class),
            $app->make(TlsTrustPolicy::class), $app->make(WireSealer::class),
            $app->make(OperationCommands::class), $app->make(ReservationService::class), $app->make(ClaimSend::class),
            ($parameters['transport'] ?? null) instanceof PhysicalTransport ? $parameters['transport'] : $app->make(PhysicalTransport::class),
            $app->make(OutcomePolicyRegistry::class), $app->make(CompletionCoordinator::class),
            telemetry: $app->make(OperationsTelemetry::class), observers: $app->make(AttemptObservers::class),
        ));
        // Frozen definitions содержат scoped guards/resolvers; worker не наследует чужой scope.
        $this->app->scoped(DefinitionRegistry::class, function (): DefinitionRegistry {
            $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
            $configuration = $this->app->make(ExtensionConfiguration::class);
            $config = config('proxyma.extensions', []);
            if (! is_array($config)) {
                throw new \LogicException('Proxyma extensions configuration must be an array.');
            }
            $configuration->register($registry, $config);
            $registry->freeze();
            $configuration->validate($registry, $config);

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $registry = $this->app->make(DefinitionRegistry::class);
            $outcomes = $this->app->make(OutcomePolicyRegistry::class);
            /** @var list<string> $policyIds */
            $policyIds = Config::array(key: 'proxyma.extensions.outcome_policies', default: []);

            foreach ($registry->selectExtensions(kind: ExtensionKind::OutcomePolicy, ids: $policyIds) as $definition) {
                $policy = $definition->implementation;
                assert($policy instanceof OutcomePolicy);
                $outcomes->register(key: $definition->id, policy: $policy, priority: $definition->priority);
            }
            $outcomes->freeze();
        });

        if ($this->app->runningInConsole()) {
            $packagePath = InstalledVersions::getInstallPath('axiomasoft/proxyma')
                ?? throw new \LogicException('Proxyma package migration path is unavailable.');
            $providerDirectory = realpath($packagePath.'/src/Laravel')
                ?: throw new \LogicException('Proxyma package provider directory is unavailable.');
            // Laravel deduplicates literal paths. Match the upstream provider's registration.
            $this->loadMigrationsFrom([
                $providerDirectory.'/../../database/migrations',
                $providerDirectory.'/Migrations',
            ]);

            $this->commands([
                Console\ReconcileCommand::class,
                Console\TransportDoctorCommand::class,
                Console\DoctorCommand::class,
                Console\DiagnoseCommand::class,
                Console\PruneCommand::class,
                Console\MetricsCommand::class,
                Console\HealthResetCommand::class,
                Console\ProbeCommand::class,
            ]);
        }
    }
}
