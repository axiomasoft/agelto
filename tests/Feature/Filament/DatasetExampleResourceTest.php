<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ListDatasetExamples;
use App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ViewDatasetExample;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
});

function datasetGoldPayload(): array
{
    return [
        'tier' => 1,
        'estimates' => [[
            'passport' => ['address' => 'Тестовый адрес'],
            'estimate_number' => 'ЛС-42',
            'rows' => [[
                'row_index' => 1,
                'row_type' => 'item',
                'name' => 'Позиция эталонного ответа',
                'resources' => [],
            ]],
        ]],
    ];
}

test('список показывает связи, задачу и источник эталонного ответа', function (): void {
    $fixture = Fixture::factory()->est()->create(['code' => 'EST-XLSX-KS2-042']);
    $example = DatasetExample::factory()->create([
        'fixture_id' => $fixture->id,
        'task' => AnalysisStep::Extraction->value,
        'document_type' => AiDocumentType::Estimate->value,
        'source' => 'llm',
    ]);

    Livewire::test(ListDatasetExamples::class)
        ->assertCanSeeTableRecords([$example])
        ->assertTableColumnStateSet('fixture.code', $fixture->code, record: $example)
        ->assertTableColumnStateSet('task', AnalysisStep::Extraction->value, record: $example)
        ->assertTableColumnStateSet('source', 'llm', record: $example);
});

test('оператор фильтрует примеры по наличию эталон-фикстуры', function (): void {
    $withFixture = DatasetExample::factory()->create(['fixture_id' => Fixture::factory()->est()]);
    $withoutFixture = DatasetExample::factory()->create(['fixture_id' => null]);

    Livewire::test(ListDatasetExamples::class)
        ->filterTable('fixture_id', 'yes')
        ->assertCanSeeTableRecords([$withFixture])
        ->assertCanNotSeeTableRecords([$withoutFixture]);
});

test('страница просмотра показывает структурированное содержимое gold-снапшота', function (): void {
    $example = DatasetExample::factory()->create(['gold' => datasetGoldPayload()]);

    Livewire::test(ViewDatasetExample::class, ['record' => $example->getKey()])
        ->assertSuccessful()
        ->assertSee('ЛС-42')
        ->assertSee('Позиция эталонного ответа');
});

test('страница честно объясняет внешнее хранение тела снимка', function (): void {
    $example = DatasetExample::factory()->create([
        'gold' => [],
        'gold_ref' => 's3://gold/dataset-example-42.json',
    ]);

    Livewire::test(ViewDatasetExample::class, ['record' => $example->getKey()])
        ->assertSuccessful()
        ->assertSee('s3://gold/dataset-example-42.json')
        ->assertSee('не загружает');
});

test('ресурс не регистрирует маршруты и действия мутации', function (): void {
    $example = DatasetExample::factory()->create();

    expect(array_keys(DatasetExampleResource::getPages()))->toBe(['index', 'view'])
        ->and(Route::has('filament.admin.resources.procurement.ai.dataset-examples.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.procurement.ai.dataset-examples.edit'))->toBeFalse()
        ->and(DatasetExampleResource::canCreate())->toBeFalse()
        ->and(DatasetExampleResource::canEdit($example))->toBeFalse()
        ->and(DatasetExampleResource::canDelete($example))->toBeFalse();
});
