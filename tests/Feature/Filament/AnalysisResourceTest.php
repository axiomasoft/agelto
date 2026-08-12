<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Analyses\Pages\ListAnalyses;
use App\Filament\Resources\Procurement\Ai\Analyses\Pages\ViewAnalysis;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Analysis;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

function analysisResourceOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

/**
 * @param  list<string>  $tags
 * @return array<string, mixed>
 */
function screeningEnvelope(
    string $summary = 'Смета на строительные работы',
    array $tags = ['строительство'],
    AiDocumentType $documentType = AiDocumentType::Estimate,
    EstimateCardinality $cardinality = EstimateCardinality::Single,
    ScreeningDisposition $disposition = ScreeningDisposition::Extract,
    ScreeningDecisionReason $reason = ScreeningDecisionReason::AnalyzerAvailable,
): array {
    return [
        'schema_version' => 2,
        'screening' => [
            'confidence' => 0.91,
            'summary' => $summary,
            'estimate_cardinality' => $cardinality->value,
            'tags' => $tags,
            'classification_note' => $documentType === AiDocumentType::Unknown ? 'Не удалось определить тип' : null,
            'page_count_estimate' => 4,
        ],
        'decision' => [
            'disposition' => $disposition->value,
            'reason' => $reason->value,
            'extraction_dispatched' => $disposition === ScreeningDisposition::Extract,
        ],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function screeningAnalysis(array $attributes = []): Analysis
{
    return Analysis::factory()->create([
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::Estimate->value,
        'result' => screeningEnvelope(),
        ...$attributes,
    ]);
}

test('оператор видит v2 классификацию и backend-решение на списке и карточке', function (): void {
    actingAs(analysisResourceOperator());

    $analysis = screeningAnalysis();

    Livewire::test(ListAnalyses::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$analysis])
        ->assertTableColumnStateSet('screening_cardinality', EstimateCardinality::Single->value, record: $analysis)
        ->assertTableColumnStateSet('screening_disposition', ScreeningDisposition::Extract->value, record: $analysis)
        ->assertTableColumnStateSet('screening_summary', 'Смета на строительные работы', record: $analysis);

    Livewire::test(ViewAnalysis::class, ['record' => $analysis->getKey()])
        ->assertSuccessful()
        ->assertSee('Классификация screening')
        ->assertSee('Смета на строительные работы')
        ->assertSee(ScreeningDecisionReason::AnalyzerAvailable->value)
        ->assertSee('строительство');
});

test('точный тег-якорь и поиск summary/tag не захватывают неактивные прогоны', function (): void {
    actingAs(analysisResourceOperator());

    $target = screeningAnalysis([
        'result' => screeningEnvelope(summary: 'Уникальная смета для поиска', tags: ['якорь']),
    ]);
    $other = screeningAnalysis([
        'result' => screeningEnvelope(summary: 'Другой документ', tags: ['другое']),
    ]);
    $superseded = screeningAnalysis([
        'result' => screeningEnvelope(summary: 'Уникальная смета для поиска', tags: ['якорь']),
        'superseded_at' => now(),
    ]);
    $shadow = screeningAnalysis([
        'result' => screeningEnvelope(summary: 'Уникальная смета для поиска', tags: ['якорь']),
        'is_shadow' => true,
    ]);

    Livewire::test(ListAnalyses::class)
        ->filterTable('screening_tag', ['value' => 'якорь'])
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other, $superseded, $shadow])
        ->removeTableFilter('screening_tag')
        ->searchTable('Уникальная')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other, $superseded, $shadow])
        ->searchTable('якорь')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other, $superseded, $shadow]);

    expect(AnalysisResource::getUrl('index', [
        'tableFilters' => ['screening_tag' => ['value' => 'якорь']],
    ]))->toContain('screening_tag');
});

test('enum-фильтры screening используют точные активные indexed поля', function (string $filter, string $value): void {
    actingAs(analysisResourceOperator());

    $target = screeningAnalysis([
        'document_type' => AiDocumentType::Estimate->value,
        'result' => screeningEnvelope(
            cardinality: EstimateCardinality::Multiple,
            disposition: ScreeningDisposition::Extract,
            reason: ScreeningDecisionReason::AnalyzerAvailable,
        ),
    ]);
    $other = screeningAnalysis([
        'document_type' => AiDocumentType::TechSpec->value,
        'result' => screeningEnvelope(
            summary: 'Техническое задание',
            tags: ['тз'],
            documentType: AiDocumentType::TechSpec,
            cardinality: EstimateCardinality::None,
            disposition: ScreeningDisposition::Retain,
            reason: ScreeningDecisionReason::KnownTypeNoAnalyzer,
        ),
    ]);

    Livewire::test(ListAnalyses::class)
        ->filterTable($filter, $value)
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
})->with([
    'type' => ['screening_document_type', AiDocumentType::Estimate->value],
    'cardinality' => ['screening_cardinality', EstimateCardinality::Multiple->value],
    'disposition' => ['screening_disposition', ScreeningDisposition::Extract->value],
    'reason' => ['screening_reason', ScreeningDecisionReason::AnalyzerAvailable->value],
]);

test('legacy или повреждённый envelope показывает placeholder и raw JSON остаётся аудитом', function (): void {
    actingAs(analysisResourceOperator());

    $analysis = screeningAnalysis([
        'result' => ['description' => '<script>alert(1)</script>', 'tags' => ['устаревший']],
        'superseded_at' => now(),
    ]);

    Livewire::test(ViewAnalysis::class, ['record' => $analysis->getKey()])
        ->assertSuccessful()
        ->assertSee('Нет v2-результата screening')
        ->assertSee('устаревший');
});

test('summary с HTML-подобным текстом экранируется при показе', function (): void {
    actingAs(analysisResourceOperator());

    $analysis = screeningAnalysis([
        'result' => screeningEnvelope(summary: '<script>alert(1)</script>'),
    ]);

    Livewire::test(ViewAnalysis::class, ['record' => $analysis->getKey()])
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

test('список analyses не получает N+1 при росте числа v2 строк', function (): void {
    actingAs(analysisResourceOperator());

    screeningAnalysis();
    Livewire::test(ListAnalyses::class)->assertSuccessful();

    DB::enableQueryLog();
    Livewire::test(ListAnalyses::class)->assertSuccessful();
    $fewRowsQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    Analysis::factory()->count(30)->create([
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::Estimate->value,
        'result' => screeningEnvelope(),
    ]);
    DB::flushQueryLog();

    Livewire::test(ListAnalyses::class)->assertSuccessful();
    $manyRowsQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($manyRowsQueryCount)->toBe($fewRowsQueryCount);
});

test('пользователь без analysis.view_any не открывает список или карточку', function (): void {
    $guest = User::factory()->create();
    $analysis = screeningAnalysis();
    actingAs($guest);

    expect(AnalysisResource::canViewAny())->toBeFalse()
        ->and(AnalysisResource::canView($analysis))->toBeFalse();

    get(AnalysisResource::getUrl('index'))->assertForbidden();
    get(AnalysisResource::getUrl('view', ['record' => $analysis]))->assertForbidden();
});
