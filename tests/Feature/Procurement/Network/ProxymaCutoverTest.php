<?php

declare(strict_types=1);

use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Proxyma\AgeltoAuthorizer;
use App\Services\Procurement\Proxyma\AgeltoTenantContext;
use App\Services\Procurement\Proxyma\ProxymaMaintenanceContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Tests\Support\ProcessCore\ProxymaReplay\NativePackageReplay;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures'] as $file) {
    require_once dirname(__DIR__, 3).'/Support/ProcessCore/ProxymaReplay/'.$file.'.php';
}

uses(WithConsoleEvents::class, NativePackageReplay::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class);

test('загрузка приложения регистрирует миграции и команды Proxyma, замораживает политики и планирует очистку', function (): void {
    expect(Schema::connection('pgsql')->hasTable(PackageSchema::tables()['operations']))->toBeTrue()
        ->and(app(OutcomePolicyRegistry::class)->isFrozen())->toBeTrue();

    $commands = Artisan::all();
    expect($commands)->toHaveKeys([
        'proxyma:cutover', 'proxyma:reconcile', 'proxyma:prune', 'proxyma:transport-doctor',
        'proxyma:doctor', 'proxyma:diagnose', 'proxyma:metrics', 'proxyma:health-reset', 'proxyma:probe',
    ]);

    $retention = collect(app(Schedule::class)->events())->sole(fn ($event): bool => str_contains($event->command ?? '', 'proxyma:prune'));
    expect($retention->expression)->toBe('0 * * * *')
        ->and($retention->filtersPass(app()))->toBeTrue();
});

test('демон возвращает ёмкость после падения отправителя, а очистка сохраняет след отправки', function (): void {
    $this->tenant = TenantKey::system();
    $this->useTenant($this->tenant);
    $this->initializeQuotaFixture();
    $this->sqlClock('2030-06-01T10:10:00Z');
    [$permit, $input, $wire] = $this->quotaPrepared();
    $this->claims->claim($permit, $input, $wire);
    [, $unsent] = $this->quotaPrepared();
    $authorizedId = $input->operation->operationId->value;
    $unsentId = $unsent->operation->operationId->value;

    $this->app->bind(Authorizer::class, AgeltoAuthorizer::class);
    $this->app->bind(TenantContextResolver::class, AgeltoTenantContext::class);
    // Категории Agelto используют основную БД, а Proxyma работает в выделенной тестовой схеме.
    DB::setDefaultConnection('pgsql');
    $this->moveSqlClock('2030-07-10T10:12:00Z');
    expect((int) $this->table('budgets')->sum('inflight'))->toBeGreaterThan(0)
        ->and(fn () => app(Reconciler::class)->run($this->tenant))->toThrow(AuthorizationDenied::class);

    $daemon = app(ReconcileDaemon::class);
    expect($daemon->tick()->errorCode)->toBeNull()
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)
        ->and($this->table('attempts')->where('operation_id', $authorizedId)->value('status'))->toBe('unknown_after_authorization')
        ->and($this->table('permits')->where('operation_id', $unsentId)->value('state'))->toBe('expired_before_send');

    $this->artisan('proxyma:reconcile', ['--limit' => 0])->assertExitCode(2);
    $this->artisan('proxyma:reconcile')->assertExitCode(0);
    $this->artisan('proxyma:prune')->assertExitCode(0);
    expect($this->table('permits')->where('operation_id', $unsentId)->exists())->toBeFalse()
        ->and($this->table('permits')->where('operation_id', $authorizedId)->exists())->toBeTrue()
        ->and(fn () => app(Reconciler::class)->run($this->tenant))->toThrow(AuthorizationDenied::class);
});

test('контекст восстановления не даёт полномочий на очистку или изменение каталога', function (): void {
    $maintenance = app(ProxymaMaintenanceContext::class);
    $maintenance->run(ability: ProxymaMaintenanceContext::RECONCILE, callback: function (): void {
        $access = app(CatalogAccess::class);
        $resource = Uuid::random();
        expect(fn () => $access->authorize(tenant: TenantKey::system(), ability: ProxymaMaintenanceContext::PRUNE, id: $resource))
            ->toThrow(AuthorizationDenied::class)
            ->and(fn () => $access->authorize(tenant: TenantKey::system(), ability: 'catalog.route.upsert', id: $resource))
            ->toThrow(AuthorizationDenied::class);
    });
    expect($maintenance->context())->toBeNull();
});
