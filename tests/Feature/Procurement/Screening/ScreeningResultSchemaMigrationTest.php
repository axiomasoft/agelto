<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Models\Procurement\Ai\Analysis;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const ScreeningResultSchemaMigration = 'database/migrations/2026_08_09_100091_migrate_screening_results_to_v2.php';

beforeEach(function (): void {
    expect(Artisan::call(command: 'migrate:fresh', parameters: ['--force' => true]))->toBe(0);
});

/**
 * @param  array<string, mixed>  $result
 */
function legacyScreeningAnalysis(array $result, string $documentType = 'unknown', array $state = []): Analysis
{
    return Analysis::factory()->create([
        'status' => AnalysisStatus::Succeeded,
        'document_type' => $documentType,
        'result' => $result,
        ...$state,
    ]);
}

/**
 * @return array<string, mixed>
 */
function legacyUnknownScreeningResult(): array
{
    return [
        'confidence' => 0.9,
        'description' => 'Прочий документ без сметы',
        'tags' => ['Москва'],
        'page_count_estimate' => null,
        'ignore_reason' => null,
        'branch_reason' => 'unknown',
        'extraction_dispatched' => false,
    ];
}

function rollbackScreeningResultSchemaMigration(): void
{
    expect(Artisan::call(command: 'migrate:rollback', parameters: [
        '--path' => ScreeningResultSchemaMigration,
        '--force' => true,
    ]))->toBe(0);
}

function migrateScreeningResultSchema(): int
{
    return Artisan::call(command: 'migrate', parameters: [
        '--path' => ScreeningResultSchemaMigration,
        '--force' => true,
    ]);
}

test('backfills active flat results, preserves historical provenance and restores on rollback/reapply', function (): void {
    rollbackScreeningResultSchemaMigration();

    $active = legacyScreeningAnalysis(result: legacyUnknownScreeningResult());
    $superseded = legacyScreeningAnalysis(
        result: legacyUnknownScreeningResult(),
        state: ['superseded_at' => now()],
    );
    $shadow = legacyScreeningAnalysis(
        result: legacyUnknownScreeningResult(),
        state: ['is_shadow' => true],
    );
    $borderline = legacyScreeningAnalysis(result: [
        'confidence' => 0.5,
        'description' => 'Пограничная смета',
        'tags' => [],
        'page_count_estimate' => 1,
        'ignore_reason' => null,
        'needs_review' => true,
        'extraction_dispatched' => false,
    ], documentType: 'estimate');
    $unprocessable = Analysis::factory()->create([
        'status' => AnalysisStatus::Skipped,
        'result' => ['unprocessable_reason' => 'unreadable_format'],
    ]);
    $alreadyV2 = legacyScreeningAnalysis(result: [
        'schema_version' => 2,
        'screening' => [
            'confidence' => 0.5,
            'summary' => 'Пограничный документ',
            'estimate_cardinality' => 'unknown',
            'tags' => [],
            'classification_note' => null,
            'page_count_estimate' => null,
        ],
        'decision' => [
            'disposition' => 'review',
            'reason' => 'borderline_confidence',
            'extraction_dispatched' => false,
        ],
    ], documentType: 'estimate');

    expect(migrateScreeningResultSchema())->toBe(0);

    $active->refresh();
    $superseded->refresh();
    $shadow->refresh();
    $borderline->refresh();
    $unprocessable->refresh();
    $alreadyV2->refresh();

    expect($active->result['schema_version'])->toBe(2)
        ->and($active->result['screening']['summary'])->toBe('Прочий документ без сметы')
        ->and($active->result['decision']['reason'])->toBe('unknown_type')
        ->and($superseded->result->toArray())->toEqual(legacyUnknownScreeningResult())
        ->and($shadow->result->toArray())->toEqual(legacyUnknownScreeningResult())
        ->and($borderline->result['decision']['reason'])->toBe('borderline_confidence')
        ->and($unprocessable->result->toArray())->toBe(['unprocessable_reason' => 'unreadable_format'])
        ->and($alreadyV2->result['schema_version'])->toBe(2);

    expect($active->storedScreeningResult()?->decision->reason)->toBe(ScreeningDecisionReason::UnknownType)
        ->and(Analysis::query()->withScreeningDocumentType(AiDocumentType::Unknown)->pluck('id')->all())->toContain($active->id)
        ->and(Analysis::query()->withEstimateCardinality(EstimateCardinality::Unknown)->pluck('id')->all())->toContain($active->id)
        ->and(Analysis::query()->withScreeningDisposition(ScreeningDisposition::Ignore)->pluck('id')->all())->toContain($active->id)
        ->and(Analysis::query()->withScreeningDecisionReason(ScreeningDecisionReason::UnknownType)->pluck('id')->all())->toContain($active->id)
        ->and(Analysis::query()->withScreeningTag('Москва')->pluck('id')->all())->toContain($active->id);

    $indexes = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'prc_ai_analyses')
        ->pluck('indexname')
        ->all();
    $check = DB::table('pg_constraint')
        ->where('conname', 'prc_ai_analyses_screening_result_schema_check')
        ->exists();

    expect($indexes)->toContain(
        'prc_ai_analyses_active_screening_type_index',
        'prc_ai_analyses_active_screening_tags_gin_index',
        'prc_ai_analyses_active_screening_cardinality_index',
        'prc_ai_analyses_active_screening_disposition_index',
        'prc_ai_analyses_active_screening_reason_index',
    )->and($check)->toBeTrue();

    $nonMatchingResult = [
        'schema_version' => 2,
        'screening' => [
            'confidence' => 0.9,
            'summary' => 'Техническое задание',
            'estimate_cardinality' => 'none',
            'tags' => [],
            'classification_note' => null,
            'page_count_estimate' => null,
        ],
        'decision' => [
            'disposition' => 'retain',
            'reason' => 'known_type_no_analyzer',
            'extraction_dispatched' => false,
        ],
    ];

    Analysis::factory()->count(96)->create([
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::TechSpec,
        'result' => $nonMatchingResult,
    ]);
    DB::statement('ANALYZE prc_ai_analyses');

    DB::statement('SET enable_seqscan = off');
    $reasonPlan = collect(DB::select("EXPLAIN SELECT id FROM prc_ai_analyses WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false AND result->>'schema_version' = '2' AND result->'decision'->>'reason' = 'unknown_type'"))
        ->pluck('QUERY PLAN')
        ->implode("\n");
    DB::statement('SET enable_seqscan = on');

    expect($reasonPlan)->toContain('prc_ai_analyses_active_screening_reason_index');

    rollbackScreeningResultSchemaMigration();
    $active->refresh();

    expect($active->result->toArray())->toEqual(legacyUnknownScreeningResult());

    expect(migrateScreeningResultSchema())->toBe(0);
    $active->refresh();

    expect($active->result['schema_version'])->toBe(2);
});

test('refuses an active flat result without a deterministic decision mapping', function (): void {
    rollbackScreeningResultSchemaMigration();

    legacyScreeningAnalysis(result: [
        'confidence' => 0.95,
        'description' => 'Смета',
        'tags' => [],
        'page_count_estimate' => 1,
        'ignore_reason' => null,
        'extraction_dispatched' => false,
    ], documentType: 'estimate');

    expect(fn (): int => migrateScreeningResultSchema())
        ->toThrow(RuntimeException::class, 'no deterministic decision mapping');

    DB::table('prc_ai_analyses')->delete();

    expect(migrateScreeningResultSchema())->toBe(0);
});

test('database rejects a newly written active succeeded flat screening result', function (): void {
    expect(fn (): Analysis => legacyScreeningAnalysis(result: legacyUnknownScreeningResult()))
        ->toThrow(QueryException::class, 'prc_ai_analyses_screening_result_schema_check');
});
