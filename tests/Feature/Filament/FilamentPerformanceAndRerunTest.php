<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Execution\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\DiscoveryOutcome;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use App\Enums\Procurement\Process\Core\WorkState;
use App\Enums\Procurement\ProcurementEventType;
use App\Filament\Resources\Procurement\Ai\Analyses\Pages\ViewAnalysis;
use App\Filament\Resources\Procurement\Ai\Analyses\RelationManagers\CorrectionsRelationManager;
use App\Filament\Resources\Procurement\Ai\Prompts\Pages\ViewPrompt;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems;
use App\Filament\Resources\Procurement\Process\Work\WorkItemResource;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\Prompt;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\Procurement\Process\WorkItem;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\User;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $this->actingAs($operator);
});

function countFilamentRenderQueries(Closure $render): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $render();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

test('число запросов списка запусков не растёт с числом отображаемых запусков', function (): void {
    $first = LedgerFixtures::crawlBlock()['run'];
    Livewire::test(ListProcessRuns::class)->assertSuccessful();
    $render = static fn () => Livewire::test(ListProcessRuns::class)->assertSuccessful();
    $oneRun = countFilamentRenderQueries($render);

    $runs = [$first->value];
    for ($index = 0; $index < 7; $index++) {
        $runs[] = LedgerFixtures::crawlBlock()['run']->value;
    }

    expect(countFilamentRenderQueries($render))->toBe($oneRun);
    Livewire::test(ListProcessRuns::class)
        ->assertCanSeeTableRecords(ProcessRun::query()->whereKey($runs)->get());
});

test('таблица перечитывает исход завершённой работы при следующем открытии списка', function (): void {
    $fixture = LedgerFixtures::crawlBlock();
    $input = LedgerFixtures::listingInput(
        block: $fixture['block'],
        filterHash: $fixture['filter_hash'],
        accessPolicyHash: $fixture['access_policy_hash'],
    );
    $work = LedgerFixtures::storeWork(input: $input, originRunId: $fixture['run']);
    app(WorkStoreRepository::class)->linkRun(
        runId: $fixture['run'],
        workId: $work,
        relation: RunWorkRelation::Created,
        role: AccountingRole::Required,
    );
    $run = ProcessRun::query()->findOrFail($fixture['run']->value);
    ProcessRun::query()->whereKey($run->id)->update([
        'discovery_outcome' => DiscoveryOutcome::Complete->value,
        'discovery_closed_at' => now(),
    ]);
    WorkItem::query()->whereKey($work->value->value)->update(['state' => WorkState::Succeeded->value]);

    Livewire::test(ListProcessRuns::class)->assertTableColumnStateSet('status_text', 'Завершён', $run);

    WorkItem::query()->whereKey($work->value->value)->update([
        'state' => WorkState::FailedItem->value,
        'reason_code' => 'fixture_failure',
    ]);

    Livewire::test(ListProcessRuns::class)
        ->assertTableColumnStateSet('status_text', 'Завершён с проблемами', $run)
        ->assertTableColumnStateSet('required_total', 1, $run);
});

test('перезапуск анализа открывает список с созданной работой по её UUID', function (): void {
    Http::preventStrayRequests();
    $recipe = AiFixtures::recipe();
    $representation = DocumentRepresentation::factory()->create([
        'preparation_recipe_hash' => $recipe->preparationHash->hex,
        'kind' => RepresentationKind::Text,
        'mime' => 'text/plain',
        'coverage_manifest' => '{"unit":"page","source_units":1,"covered":[[0,1]],"missing":[],"complete":true}',
    ]);
    $version = $representation->version;
    $document = $version->document;
    $run = new AnalysisRunSnapshot(
        id: LedgerFixtures::uuid(),
        documentVersionId: $version->id,
        representationId: $representation->id,
        representationHash: new ContentHash($representation->content_hash),
        recipe: $recipe,
        generation: 1,
        expectedDocumentHeadGeneration: $document->head_generation,
        expectedAnalysisGeneration: $version->analysis_generation + 1,
        requestedReason: 'fixture',
    );
    expect(app(AnalysisRepository::class)->startPreparedRunLocked(documentId: $document->id, run: $run))->toBeTrue();
    $input = new ScreeningInput(
        documentVersionId: $run->documentVersionId,
        representationHash: $run->representationHash,
        screeningRecipeHash: $run->recipe->hash,
    );
    $oldWork = new WorkId(LedgerFixtures::uuid());
    app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
        id: $oldWork,
        input: $input,
        inputRevision: $run->expectedAnalysisGeneration,
        intentId: null,
        cohortKey: 'manual',
        platformId: null,
        originRunId: null,
        priority: 100,
        eventId: LedgerFixtures::uuid(),
        projectionMessageId: LedgerFixtures::uuid(),
    ));
    WorkItem::query()->whereKey($oldWork->value->value)->update([
        'state' => WorkState::Succeeded->value,
    ]);
    $analysis = Analysis::factory()->create([
        'document_version_id' => $run->documentVersionId,
        'analysis_run_id' => $run->id->value,
        'status' => AnalysisStatus::Succeeded,
    ]);

    $profile = new RequestProfile('fixture.analysis', 1, 2, 3, 8000000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1);
    app(AnalysisProviderConnections::class)->register(new AnalysisProviderConnection(
        context: $run->recipe->llm,
        origin: 'https://fixture.test',
        pricing: FinancialFixtures::pricing(),
        periodId: LedgerFixtures::uuid()->value,
        profile: $profile,
        apiKey: 'fixture-only',
    ), preferred: true);

    $view = Livewire::test(ViewAnalysis::class, ['record' => $analysis->id])
        ->assertSuccessful()
        ->callAction('rerun');
    $newWork = WorkItem::query()->where('state', WorkState::Ready->value)->sole();
    $url = WorkItemResource::getUrl(name: 'index', parameters: ['search' => $newWork->id]);

    $view->assertRedirect($url);
    $this->get($url)->assertOk();
    Livewire::withQueryParams(['search' => $newWork->id])
        ->test(ListWorkItems::class)
        ->assertSet('tableSearch', $newWork->id)
        ->assertCanSeeTableRecords([$newWork])
        ->assertCanNotSeeTableRecords([$oldWork->value->value]);
    Http::assertNothingSent();
});

test('реестр закупок показывает последнюю работу получения и полный опубликованный снимок', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);
    $this->actingAs($admin);

    $work = WorkItem::query()->findOrFail(ExecutionFixtures::detailWork()->value->value);
    $procurement = Procurement::query()->findOrFail($work->procurement_id);
    $due = now()->addHour()->startOfSecond();
    WorkItem::query()->whereKey($work->id)->update([
        'state' => WorkState::Deferred->value,
        'reason_code' => 'rate_limit',
        'due_at' => $due,
    ]);
    LedgerFixtures::storeWork(LedgerFixtures::statusInput($procurement->id));

    $snapshots = app(DetailSnapshotRepository::class);
    $snapshots->insert(procurement: $procurement->id, head: 1, part: 'fixture:published', completeness: 'complete', payload: '{}', revision: null);
    $snapshots->insert(procurement: $procurement->id, head: 2, part: 'fixture:published', completeness: 'partial', payload: '{}', revision: null);
    $publishedAt = DB::table('prc_detail_snapshots')->where('completeness', 'complete')->value('created_at');
    $withoutWork = Procurement::factory()->create();

    $rows = ProcurementResource::getEloquentQuery()->whereKey([$procurement->id, $withoutWork->id])->get();
    $loaded = $rows->find($procurement->id);
    $empty = $rows->find($withoutWork->id);
    expect($rows)->toHaveCount(2)
        ->and($loaded?->getAttribute('detail_work_state'))->toBe(WorkState::Deferred->value)
        ->and($loaded?->getAttribute('detail_work_reason_code'))->toBe('rate_limit')
        ->and(Carbon::parse($loaded?->getAttribute('detail_work_due_at'))->equalTo($due))->toBeTrue()
        ->and($loaded?->getAttribute('detail_fetched_at'))->toEqual(Carbon::parse($publishedAt))
        ->and($empty?->getAttribute('detail_work_state'))->toBeNull()
        ->and($empty?->getAttribute('detail_fetched_at'))->toBeNull();
});

test('карточка перечитывает историю при новой загрузке той же закупки', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);
    $this->actingAs($admin);

    $procurement = Procurement::factory()->create(['first_seen_at' => now()->subWeek()]);
    $discoveredAt = now()->subDays(3)->startOfMinute();
    $label = $discoveredAt->format('d.m.Y H:i');

    Livewire::test(ViewProcurement::class, ['record' => $procurement->id])->assertDontSee($label);
    ProcurementEvent::factory()->for($procurement)->create([
        'event_type' => ProcurementEventType::Discovered,
        'occurred_at' => $discoveredAt,
    ]);
    Livewire::test(ViewProcurement::class, ['record' => $procurement->id])->assertSee($label);
});

test('карточка промта показывает сто последних версий той же пары', function (): void {
    $versions = Prompt::factory()->count(101)->state(['key' => 'many-versions'])
        ->state(new Sequence(fn (Sequence $sequence): array => ['version' => $sequence->index + 1]))
        ->create();
    $link = static fn (Prompt $record): string => 'href="'.PromptResource::getUrl(name: 'view', parameters: ['record' => $record]).'"';

    Livewire::test(ViewPrompt::class, ['record' => $versions->last()->id])
        ->assertSee($link($versions[1]), escape: false)
        ->assertDontSee($link($versions[0]), escape: false);
});

test('имена авторов коррекций загружаются с постоянным числом запросов', function (): void {
    $analysis = Analysis::factory()->create();
    $author = User::factory()->create(['name' => 'Автор первой коррекции']);
    Correction::factory()->for($analysis)->for($author, 'createdBy')->create();
    $render = static fn () => Livewire::test(CorrectionsRelationManager::class, [
        'ownerRecord' => $analysis,
        'pageClass' => ViewAnalysis::class,
    ])->assertSee($author->name);
    $render();
    $oneCorrection = countFilamentRenderQueries($render);

    Correction::factory()->count(7)->for($analysis)->create();

    expect(countFilamentRenderQueries($render))->toBe($oneCorrection);
});
