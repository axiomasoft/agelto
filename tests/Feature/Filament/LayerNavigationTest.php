<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Analyses\Pages\ViewAnalysis;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ViewDatasetExample;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use App\Filament\Resources\Procurement\Estimates\Pages\ViewEstimate;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
});

function layerNavigationGraph(): array
{
    $procurement = Procurement::factory()->create();
    $document = Document::factory()->create(['procurement_id' => $procurement->id, 'platform_id' => Platform::factory()]);
    $fixture = Fixture::factory()->est()->create(['document_id' => $document->id]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $analysis = Analysis::factory()->create(['document_version_id' => $version->id]);
    $estimate = Estimate::factory()->create([
        'analysis_id' => $analysis->id,
        'document_version_id' => $version->id,
        'procurement_id' => $procurement->id,
    ]);
    $example = DatasetExample::factory()->create([
        'fixture_id' => $fixture->id,
        'analysis_id' => $analysis->id,
        'document_version_id' => $version->id,
    ]);

    return compact('procurement', 'fixture', 'analysis', 'estimate', 'example');
}

test('переходы сметы открывают закупку и анализ', function (): void {
    ['analysis' => $analysis, 'estimate' => $estimate] = layerNavigationGraph();

    expect(EstimateResource::getUrl('index'))->not->toBeEmpty()
        ->and(AnalysisResource::getUrl('view', ['record' => $analysis]))->not->toBeEmpty();
    Livewire::test(ViewEstimate::class, ['record' => $estimate->getKey()])->assertSuccessful();
});

test('переходы анализа открывают его смету и эталон', function (): void {
    ['fixture' => $fixture, 'analysis' => $analysis, 'estimate' => $estimate] = layerNavigationGraph();

    expect(EstimateResource::getUrl('view', ['record' => $estimate]))->not->toBeEmpty()
        ->and(FixtureResource::getUrl('view', ['record' => $fixture]))->not->toBeEmpty();
    Livewire::test(ViewAnalysis::class, ['record' => $analysis->getKey()])->assertSuccessful();
});

test('переходы эталонного ответа открывают эталон и анализ', function (): void {
    ['fixture' => $fixture, 'analysis' => $analysis, 'example' => $example] = layerNavigationGraph();

    expect(FixtureResource::getUrl('view', ['record' => $fixture]))->not->toBeEmpty()
        ->and(AnalysisResource::getUrl('view', ['record' => $analysis]))->not->toBeEmpty();
    Livewire::test(ViewDatasetExample::class, ['record' => $example->getKey()])->assertSuccessful();
});

test('отсутствующие связи не создают маршрут для просмотра', function (): void {
    $example = DatasetExample::factory()->create(['fixture_id' => null, 'analysis_id' => null]);

    Livewire::test(ViewDatasetExample::class, ['record' => $example->getKey()])->assertSuccessful();
    expect($example->fixture)->toBeNull()->and($example->analysis)->toBeNull();
});
