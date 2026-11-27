<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Models\Procurement\Ai\Analysis;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const ScreeningResultSchemaMigration = 'database/migrations/2026_08_09_100091_migrate_screening_results_to_v2.php';

function legacyUnknownScreeningResult(): array
{
    return ['confidence' => 0.9, 'description' => 'Прочий документ без сметы', 'tags' => ['Москва'],
        'page_count_estimate' => null, 'ignore_reason' => null, 'branch_reason' => 'unknown', 'extraction_dispatched' => false];
}

test('schema-only screening rollback and reapply preserve accepted and historical result bytes', function (): void {
    $stored = StoredScreeningResult::fromLegacy('unknown', legacyUnknownScreeningResult());
    foreach ([[], ['superseded_at' => now()], ['is_shadow' => true]] as $state) {
        Analysis::factory()->create(['document_type' => 'unknown', 'result' => $stored->toArray(), ...$state]);
    }
    $before = DB::table('prc_ai_analyses')->orderBy('id')->get()->toJson();
    $migration = require base_path(ScreeningResultSchemaMigration);
    $migration->down();
    $migration->up();
    expect(DB::table('prc_ai_analyses')->orderBy('id')->get()->toJson())->toBe($before)
        ->and($stored->decision->reason)->toBe(ScreeningDecisionReason::UnknownType)
        ->and(DB::table('pg_constraint')->where('conname', 'prc_ai_analyses_screening_result_schema_check')->exists())->toBeTrue();
    $indexes = DB::table('pg_indexes')->where('tablename', 'prc_ai_analyses')->pluck('indexname')->all();
    expect($indexes)->toContain('prc_ai_analyses_active_screening_type_index', 'prc_ai_analyses_active_screening_tags_gin_index',
        'prc_ai_analyses_active_screening_cardinality_index', 'prc_ai_analyses_active_screening_disposition_index', 'prc_ai_analyses_active_screening_reason_index');
    DB::statement('ANALYZE prc_ai_analyses');
    DB::statement('SET LOCAL enable_seqscan = off');
    $plan = collect(DB::select("EXPLAIN SELECT id FROM prc_ai_analyses WHERE step='screening' AND superseded_at IS NULL AND is_shadow=false AND result->>'schema_version'='2' AND result->'decision'->>'reason'='unknown_type'"))->pluck('QUERY PLAN')->implode("\n");
    expect($plan)->toContain('prc_ai_analyses_active_screening_reason_index');
    DB::statement('SET LOCAL enable_seqscan = on');
});

test('schema-only screening migration rejects incompatible flat output without rewriting history', function (): void {
    $migration = require base_path(ScreeningResultSchemaMigration);
    $migration->down();
    $raw = legacyUnknownScreeningResult();
    unset($raw['branch_reason']);
    $analysis = Analysis::factory()->create(['document_type' => 'estimate', 'result' => $raw]);
    expect(fn () => StoredScreeningResult::fromLegacy('estimate', $raw))->toThrow(InvalidArgumentException::class, 'no deterministic decision mapping');
    expect(fn () => DB::transaction(fn () => $migration->up()))->toThrow(QueryException::class, 'prc_ai_analyses_screening_result_schema_check');
    expect($analysis->fresh()->result->toArray())->toEqual($raw);
});

test('database rejects a newly written active succeeded flat screening result', function (): void {
    expect(fn () => Analysis::factory()->create(['document_type' => 'unknown', 'result' => legacyUnknownScreeningResult()]))
        ->toThrow(QueryException::class, 'prc_ai_analyses_screening_result_schema_check');
});
