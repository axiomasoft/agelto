<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinalPromptPage;
use App\Filament\Pages\NetworkRouteProfilesPage;
use App\Filament\Pages\PromptCompositionPage;
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use App\Filament\Resources\Procurement\Ai\Providers\ProviderResource;
use App\Filament\Resources\Procurement\Crawler\Crawlers\CrawlerResource;
use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\SourceIncidentResource;
use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use App\Filament\Resources\Procurement\Health\PlatformChecks\PlatformCheckResource;
use App\Filament\Resources\Procurement\OperationLogs\OperationLogResource;
use App\Filament\Resources\Procurement\Platform\Platforms\PlatformResource;
use App\Filament\Resources\Procurement\Platform\Registries\RegistryResource;
use App\Filament\Resources\Procurement\Platform\StatusMappings\StatusMappingResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Incidents\IncidentResource;
use App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems;
use App\Filament\Resources\Procurement\Process\Work\WorkItemResource;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Process\ControlScope;
use App\Models\Procurement\Process\Incident;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\Procurement\Process\WorkItem;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Platform\PlatformUiResolver;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\Platforms\JsonProcurement\JsonListingFilter;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;
use Tests\Support\ProcessCore\AiUiFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

pest()->group('p316', 'e2e', 'exr01');

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertSuccessful();
});

test('EXR-01 навигация и все страницы админки рендерятся на финальной схеме без выведенных из эксплуатации таблиц', function (): void {
    foreach (['prc_source_circuits', 'prc_crawler_runs', 'prc_ai_analysis_runs', 'llm_calls'] as $retiredTable) {
        expect(Schema::hasTable($retiredTable))->toBeFalse("Retired table {$retiredTable} must not exist");
    }

    foreach ([
        'filament.admin.resources.procurement.crawler.source-circuits.index',
        'filament.admin.resources.procurement.crawler.source-circuits.edit',
        'filament.admin.resources.procurement.crawler.runs.index',
        'filament.admin.resources.procurement.crawler.runs.view',
    ] as $retiredRoute) {
        expect(Route::has($retiredRoute))->toBeFalse("Retired route {$retiredRoute} must not exist");
    }

    $panel = Filament::getPanel('admin');
    $groups = array_map(
        static fn (NavigationGroup|string $group): ?string => $group instanceof NavigationGroup
            ? $group->getLabel()
            : $group,
        $panel->getNavigationGroups(),
    );
    expect($groups)->toBe([
        'Закупки',
        'Площадки',
        'Сбор данных',
        'Качество анализа',
        'Настройка AI',
        'Доступ и безопасность',
        'Система',
    ]);

    $expectedResources = [
        ProcurementResource::class => 'Закупки',
        EstimateResource::class => 'Закупки',
        AnalysisResource::class => 'Закупки',
        PlatformResource::class => 'Площадки',
        RegistryResource::class => 'Площадки',
        StatusMappingResource::class => 'Площадки',
        PlatformCheckResource::class => 'Площадки',
        CrawlerResource::class => 'Сбор данных',
        ProxyResource::class => 'Сбор данных',
        SourceIncidentResource::class => 'Сбор данных',
        FixtureResource::class => 'Качество анализа',
        DatasetExampleResource::class => 'Качество анализа',
        ProviderResource::class => 'Настройка AI',
        LlmRouteResource::class => 'Настройка AI',
        PromptResource::class => 'Настройка AI',
        PromptSuggestionResource::class => 'Настройка AI',
        OperationLogResource::class => 'Система',
        ControlScopeResource::class => 'Система',
        IncidentResource::class => 'Система',
        ProcessRunResource::class => 'Система',
        WorkItemResource::class => 'Система',
    ];

    foreach ($expectedResources as $resourceClass => $expectedGroup) {
        expect($resourceClass::getNavigationGroup())->toBe($expectedGroup);
    }

    // Verify guest is redirected to login on protected admin routes.
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get(ControlScopeResource::getUrl())->assertRedirect('/admin/login');

    // Verify role-based access across Analyst, Operator, and SuperAdmin.
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);

    actingAs($analyst)->get('/admin')->assertOk();
    actingAs($analyst)->get(ProcurementResource::getUrl())->assertOk();
    actingAs($analyst)->get(ProcessRunResource::getUrl())->assertOk();
    actingAs($analyst)->get(ControlScopeResource::getUrl())->assertForbidden();
    actingAs($analyst)->get(WorkItemResource::getUrl())->assertForbidden();
    actingAs($analyst)->get('/admin/roles')->assertForbidden();

    actingAs($operator)->get('/admin')->assertOk();
    actingAs($operator)->get(ControlScopeResource::getUrl())->assertOk();
    actingAs($operator)->get(WorkItemResource::getUrl())->assertOk();
    actingAs($operator)->get('/admin/roles')->assertForbidden();

    actingAs($admin)->get('/admin')->assertOk();
    actingAs($admin)->get('/admin/roles')->assertOk();

    // Seed an unresolved SourceIncident and Process entities to verify badge and table coexistence.
    SourceIncident::factory()->create();
    expect(SourceIncidentResource::getNavigationBadge())->toBe('1');

    // Seed canonical AI UI fixtures for full EXR-01 page rendering.
    [$fixture, $analysisRun, $analysis] = AiUiFixtures::accepted();
    $procurement = Procurement::factory()->create();
    $estimate = Estimate::factory()->create([
        'procurement_id' => $procurement->id,
        'document_version_id' => $analysis->document_version_id,
        'analysis_id' => $analysis->id,
    ]);

    actingAs($admin);
    foreach (array_keys($expectedResources) as $resourceClass) {
        $this->get($resourceClass::getUrl('index'))->assertOk();
    }

    foreach ([
        Dashboard::getUrl(),
        NetworkRouteProfilesPage::getUrl(),
        PromptCompositionPage::getUrl(),
        FinalPromptPage::getUrl(['run' => $analysisRun->id]),
        ProcurementResource::getUrl('view', ['record' => $procurement]),
        AnalysisResource::getUrl('view', ['record' => $analysis]),
        EstimateResource::getUrl('view', ['record' => $estimate]),
        FixtureResource::getUrl('view', ['record' => $fixture]),
        FixtureResource::getUrl('gold', ['record' => $fixture]),
    ] as $pageUrl) {
        $this->get($pageUrl)->assertOk();
    }
});

test('PlatformUiResolver рендерит карточку закупки ЕИС и второй площадки без платформенных веток в общем ресурсе', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);
    actingAs($admin);

    $eisPlatform = Platform::query()->firstOrCreate(
        ['code' => 'eis'],
        ['name' => 'ЕИС', 'base_url' => 'https://zakupki.gov.ru', 'is_active' => true, 'is_public' => true],
    );
    $eisRegistry = Registry::query()->firstOrCreate(
        ['platform_id' => $eisPlatform->id, 'code' => 'fz44'],
        ['name' => '44-ФЗ', 'settings' => []],
    );
    $eisProcurement = Procurement::factory()->create([
        'platform_id' => $eisPlatform->id,
        'registry_id' => $eisRegistry->id,
        'source_identity_type' => 'registry:44fz',
        'source_identity_value' => '0338200009125000777',
        'title' => 'Поставка серверного оборудования ЕИС',
    ]);
    EisDetails::factory()->create([
        'procurement_id' => $eisProcurement->id,
        'ikz' => '261770000000077010100100010010000244',
        'etp_number' => 'ETP-2026-0777',
    ]);

    config()->set('platforms.definitions.json', JsonProcurementDefinition::class);
    $jsonPlatform = Platform::query()->firstOrCreate(
        ['code' => 'json'],
        ['name' => 'JSON Площадка E2E', 'base_url' => 'https://json-e2e.example.test', 'is_active' => true, 'is_public' => true],
    );
    $jsonRegistry = Registry::query()->firstOrCreate(
        ['platform_id' => $jsonPlatform->id, 'code' => 'fz44'],
        ['name' => 'Лоты JSON', 'settings' => ['filter_dto' => JsonListingFilter::class]],
    );
    $jsonProcurement = Procurement::factory()->create([
        'platform_id' => $jsonPlatform->id,
        'registry_id' => $jsonRegistry->id,
        'source_identity_type' => 'opaque:notice',
        'source_identity_value' => 'LOT-2026-9001',
        'title' => 'Поставка кабельной продукции JSON',
    ]);

    app()->forgetInstance(PlatformRegistry::class);
    app()->forgetInstance(PlatformUiResolver::class);

    Livewire::test(ViewProcurement::class, ['record' => $eisProcurement->getRouteKey()])
        ->assertOk()
        ->assertSee('0338200009125000777')
        ->assertSee('261770000000077010100100010010000244')
        ->assertSee('ETP-2026-0777');

    Livewire::test(ViewProcurement::class, ['record' => $jsonProcurement->getRouteKey()])
        ->assertOk()
        ->assertSee('LOT-2026-9001')
        ->assertSee('Поставка кабельной продукции JSON')
        ->assertDontSee('261770000000077010100100010010000244');
});

test('операторские команды управления контурами, задачами, запусками и инцидентами работают end-to-end через UI и CLI с аудитом переходов', function (): void {
    FinalizeFixtures::authority([StageCode::Listing, StageCode::Detail]);
    FixtureProviderRecoveryResolver::install();
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);
    $commands = app(ProcessOperatorCommands::class);

    // 1. Pause and resume scope via Livewire table actions + verify stale version conflict and CLI resume.
    actingAs($operator);
    $scopeRecord = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    $initialVersion = (int) $scopeRecord->control_version;

    Livewire::test(ListControlScopes::class)
        ->assertTableActionVisible('pause', $scopeRecord)
        ->assertTableActionHidden('resume', $scopeRecord)
        ->callTableAction('pause', $scopeRecord, ['reason' => 'Плановое окно обслуживания E2E'])
        ->assertHasNoTableActionErrors();

    $pausedScope = DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->sole();
    expect($pausedScope->desired_mode)->toBe('paused')
        ->and((int) $pausedScope->control_version)->toBe($initialVersion + 1);

    $staleResult = $commands->resumeScope($operator, 'acquisition', $initialVersion, 'Устаревшая версия');
    expect($staleResult->status)->toBe(OperatorCommandStatus::VersionConflict);

    artisan('process:control', [
        'action' => 'resume',
        'target' => 'acquisition',
        '--user' => (string) $operator->id,
        '--reason' => 'Возобновление через CLI E2E',
        '--expected' => (string) ($initialVersion + 1),
    ])->expectsOutputToContain('resume: applied')->assertExitCode(0);
    expect(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('enabled');

    // 2. Cancel work item via Livewire table action.
    $cancelWorkId = ExecutionFixtures::listingWork();
    $workRecord = WorkItem::query()->findOrFail($cancelWorkId->value->value);
    Livewire::test(ListWorkItems::class)
        ->assertCanSeeTableRecords([$workRecord])
        ->assertTableActionVisible('cancel_work', $workRecord)
        ->callTableAction('cancel_work', $workRecord, ['reason' => 'Отмена задачи оператором E2E'])
        ->assertHasNoTableActionErrors();
    expect(ExecutionFixtures::work($cancelWorkId)->state)->toBe(WorkState::Cancelled->value);

    // 3. Cancel active process run via Livewire table action without cancelling shared work of another run.
    $sharedWorkId = ExecutionFixtures::listingWork();
    $cancelledRunId = LedgerFixtures::crawlBlock()['run'];
    $otherRunId = LedgerFixtures::crawlBlock()['run'];
    $workStore = app(WorkStoreRepository::class);
    $workStore->linkRun($cancelledRunId, $sharedWorkId, RunWorkRelation::Created, AccountingRole::Required);
    $workStore->linkRun($otherRunId, $sharedWorkId, RunWorkRelation::Discovered, AccountingRole::Required);

    $runRecord = ProcessRun::query()->findOrFail($cancelledRunId->value);
    Livewire::test(ListProcessRuns::class)
        ->assertCanSeeTableRecords([$runRecord])
        ->assertTableActionVisible('cancel_run', $runRecord)
        ->callTableAction('cancel_run', $runRecord, ['reason' => 'Остановка тестового запуска E2E'])
        ->assertHasNoTableActionErrors();
    expect(DB::table('prc_process_runs')->where('id', $cancelledRunId->value)->value('cancel_requested_at'))->not->toBeNull()
        ->and(DB::table('prc_process_runs')->where('id', $otherRunId->value)->value('cancel_requested_at'))->toBeNull()
        ->and(ExecutionFixtures::work($sharedWorkId)->state)->not->toBe(WorkState::Cancelled->value);

    // 4. Acknowledge active incident via Livewire table action.
    $incidentId = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(fn () => app(IncidentRepository::class)->open(
        LedgerFixtures::hash('e2e-incident-1'),
        'acquisition',
        'handler_hold',
        ['stage' => 'listing'],
        $incidentId,
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
    ));
    $incidentRecord = Incident::query()->findOrFail($incidentId->value);
    Livewire::test(ListIncidents::class)
        ->assertCanSeeTableRecords([$incidentRecord])
        ->assertTableActionVisible('acknowledge', $incidentRecord)
        ->callTableAction('acknowledge', $incidentRecord, ['reason' => 'Инцидент принят в работу E2E'])
        ->assertHasNoTableActionErrors();
    expect(DB::table('prc_process_incidents')->where('id', $incidentId->value)->value('state'))
        ->toBe('acknowledged');

    // 5. EXR-02: Retry handler-faulted work is denied for Operator and allowed for SuperAdmin via Livewire UI.
    $faultedWorkId = ExecutionFixtures::listingWork();
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finishHandlerFault(FinalizeFixtures::running($faultedWorkId), FinalizeFixtures::fault('fault-1'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($faultedWorkId), FinalizeFixtures::fault('fault-2'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($faultedWorkId), FinalizeFixtures::fault('fault-3'));

    $faultedRecord = WorkItem::query()->findOrFail($faultedWorkId->value->value);
    expect($faultedRecord->state)->toBe(WorkState::Blocked)
        ->and($faultedRecord->reason_code)->toBe('handler_fault_limit');

    expect(fn () => $commands->retryHandlerFaultedWork(
        $operator,
        $faultedWorkId,
        (int) $faultedRecord->transition_seq,
        LedgerFixtures::uuid(),
        'Попытка без роли superadmin',
    ))->toThrow(AuthorizationException::class);

    Livewire::test(ListWorkItems::class)
        ->assertTableActionHidden('retry_handler_fault', $faultedRecord);

    actingAs($superAdmin);
    Livewire::test(ListWorkItems::class)
        ->assertTableActionVisible('retry_handler_fault', $faultedRecord)
        ->callTableAction('retry_handler_fault', $faultedRecord, ['reason' => 'Повтор после исправления деплоя E2E'])
        ->assertHasNoTableActionErrors();
    expect(ExecutionFixtures::work($faultedWorkId)->state)->toBe(WorkState::Superseded->value);

    // 6. Retry failed item: creates a new ready successor work item while keeping failed_item work immutable.
    $failedWorkId = ExecutionFixtures::listingWork();
    $itemFailureOutcome = new StageFailedItem(
        ItemFailureReason::ContractRejected,
        new ArtifactRef('fixture', 'evidence/fail', LedgerFixtures::hash('fail-item')),
    );
    $finalizer->finish(FinalizeFixtures::running($failedWorkId), $itemFailureOutcome);
    $finalizer->finish(FinalizeFixtures::rerun($failedWorkId), $itemFailureOutcome);
    $finalizer->finish(FinalizeFixtures::rerun($failedWorkId), $itemFailureOutcome);
    expect(ExecutionFixtures::work($failedWorkId)->state)->toBe(WorkState::FailedItem->value);

    $failedSeq = (int) ExecutionFixtures::work($failedWorkId)->transition_seq;
    $retryCommandId = LedgerFixtures::uuid();
    $retryResult = $commands->retryFailedItem(
        $operator,
        $failedWorkId,
        $failedSeq,
        $retryCommandId,
        'Повтор объекта после обновления правил E2E',
    );
    expect($retryResult->status)->toBe(OperatorCommandStatus::Applied)
        ->and($retryResult->successor)->toBeInstanceOf(WorkId::class)
        ->and(ExecutionFixtures::work($failedWorkId)->state)->toBe(WorkState::FailedItem->value)
        ->and(ExecutionFixtures::work($retryResult->successor)->state)->toBe(WorkState::Ready->value);

    // Verify operator transition audit trail.
    $operatorTransitions = DB::table('prc_process_transitions')
        ->where('actor_kind', 'operator')
        ->count();
    expect($operatorTransitions)->toBeGreaterThanOrEqual(6);
});
