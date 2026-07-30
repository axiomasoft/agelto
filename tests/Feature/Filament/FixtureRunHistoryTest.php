<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisRunStatus;
use App\Enums\Procurement\RunMode;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\RelationManagers\RunsRelationManager;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

// `artisan()` без потребления возврата откладывает выполнение до __destruct() — форсируем
// синхронный прогон явным assertExitCode (тот же приём, что FixtureRunScreenTest).
beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

/**
 * @return array{0: Fixture, 1: AnalysisRun}
 */
function seedFixtureRun(array $runAttributes = []): array
{
    $document = Document::factory()->create();
    $fixture = Fixture::factory()->est()->create(['document_id' => $document->id]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $run = AnalysisRun::factory()->create([...$runAttributes, 'document_version_id' => $version->id]);

    return [$fixture, $run];
}

test('значения токенов и цены в строке совпадают со слепком прогона', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    [$fixture, $run] = seedFixtureRun([
        'mode' => RunMode::Manual,
        'status' => AnalysisRunStatus::Succeeded,
        'finished_at' => now(),
        'prompt_tokens' => 1000,
        'cached_tokens' => 200,
        'completion_tokens' => 300,
        'total_tokens' => 1300,
        'cost_micros' => 45_600,
    ]);

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->assertTableColumnStateSet('prompt_tokens', 1000, record: $run)
        ->assertTableColumnStateSet('cached_tokens', 200, record: $run)
        ->assertTableColumnStateSet('completion_tokens', 300, record: $run)
        ->assertTableColumnStateSet('total_tokens', 1300, record: $run)
        ->assertTableColumnFormattedStateSet('cost_micros', '$0.0456', record: $run);
});

test('прогон без слепка показывает «нет данных», а не ноль', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    [$fixture, $run] = seedFixtureRun([
        'mode' => RunMode::Auto,
        'status' => AnalysisRunStatus::Running,
    ]);

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->assertTableColumnStateSet('prompt_tokens', null, record: $run)
        ->assertTableColumnStateSet('cached_tokens', null, record: $run)
        ->assertTableColumnStateSet('completion_tokens', null, record: $run)
        ->assertTableColumnStateSet('total_tokens', null, record: $run)
        ->assertTableColumnStateSet('cost_micros', null, record: $run)
        ->assertSee('нет данных');
});

test('режим запуска виден в строке', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    [$fixture, $manualRun] = seedFixtureRun(['mode' => RunMode::Manual]);

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->assertTableColumnStateSet('mode', RunMode::Manual, record: $manualRun);
});

test('статус различает «идёт» и «завершён»', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    $document = Document::factory()->create();
    $fixture = Fixture::factory()->est()->create(['document_id' => $document->id]);

    $runningVersion = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $runningRun = AnalysisRun::factory()->create([
        'document_version_id' => $runningVersion->id,
        'status' => AnalysisRunStatus::Running,
    ]);

    $succeededVersion = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $succeededRun = AnalysisRun::factory()->succeeded()->create([
        'document_version_id' => $succeededVersion->id,
    ]);

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $fixture, 'pageClass' => ViewFixture::class])
        ->assertTableColumnStateSet('status', AnalysisRunStatus::Running, record: $runningRun)
        ->assertTableColumnStateSet('status', AnalysisRunStatus::Succeeded, record: $succeededRun);
});

test('без права ресурса эталонов карточка и история прогонов недоступны', function (): void {
    $guest = User::factory()->create();
    actingAs($guest);

    [$fixture] = seedFixtureRun();

    expect(FixtureResource::canView($fixture))->toBeFalse();

    get(FixtureResource::getUrl('view', ['record' => $fixture]))->assertForbidden();
});
