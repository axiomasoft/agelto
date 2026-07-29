<?php

declare(strict_types=1);

use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Ai\Prompt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * P1.4 — check-constraint'ы оси типа документа (D16/D17, §1.3 root/document-type-axis.md):
 * КАЖДАЯ колонка `document_type`/`task` отвергает битое значение отказом БД, а не только
 * принимает валидное (Implementation Rules п. 5). Плюс дубль кросс-документного маршрута
 * (составной ключ `(document_type, purpose, priority, shadow)`, п. 6).
 */
test('битый document_type отвергается БД на prc_ai_analyses', function (): void {
    expect(fn () => Analysis::factory()->create(['document_type' => 'not-a-real-type']))
        ->toThrow(QueryException::class);
});

test('битый document_type отвергается БД на prc_ai_corrections', function (): void {
    expect(fn () => Correction::factory()->create(['document_type' => 'not-a-real-type']))
        ->toThrow(QueryException::class);
});

/**
 * Implementation Rules P1.4 п. 12: коррекция над анализом без вердикта скрининга не создаётся —
 * `prc_ai_corrections.document_type` `NOT NULL` (§3.2 контракта) отражает это на границе схемы;
 * `ViewAnalysis::rejectWithCorrectionAction()` реализует ту же гарантию на границе UI (форма
 * недоступна/отклоняется с сообщением, без Livewire-инфраструктуры теста — прецедента
 * действие-тестов Filament в сюите нет).
 */
test('коррекция без document_type не создаётся — БД отклоняет NULL', function (): void {
    expect(fn () => Correction::factory()->create(['document_type' => null]))
        ->toThrow(QueryException::class);
});

test('битый document_type отвергается БД на prc_ai_dataset_examples', function (): void {
    expect(fn () => DatasetExample::factory()->create(['document_type' => 'not-a-real-type']))
        ->toThrow(QueryException::class);
});

test('битый task отвергается БД на prc_ai_dataset_examples', function (): void {
    expect(fn () => DatasetExample::factory()->create(['task' => 'not-a-real-step']))
        ->toThrow(QueryException::class);
});

test('битый document_type отвергается БД на prc_ai_eval_runs', function (): void {
    expect(fn () => EvalRun::factory()->create(['document_type' => 'not-a-real-type']))
        ->toThrow(QueryException::class);
});

test('битый task отвергается БД на prc_ai_eval_runs', function (): void {
    expect(fn () => EvalRun::factory()->create(['task' => 'not-a-real-step']))
        ->toThrow(QueryException::class);
});

test('битый document_type отвергается БД на prc_ai_routes', function (): void {
    expect(fn () => AiRoute::factory()->create(['document_type' => 'not-a-real-type']))
        ->toThrow(QueryException::class);
});

test('битый document_type отвергается БД на prc_ai_prompts', function (): void {
    expect(fn () => Prompt::factory()->create(['document_type' => 'not-a-real-type']))
        ->toThrow(QueryException::class);
});

/**
 * `Fixture::$document_type`/`$owner_document_type` кастуются в `AiDocumentType` на границе
 * модели — битое значение через Eloquent упало бы `ValueError` ДО похода в БД, поэтому
 * check-constraint проверяется прямой вставкой в обход каста (то, что реально защищает БД,
 * а не PHP-слой).
 *
 * @return array<string, mixed>
 */
function validFixtureRow(string $code): array
{
    return [
        'code' => $code,
        'source_format' => 'xlsx',
        'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
        'sha256' => hash('sha256', $code),
        'size_bytes' => 100,
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'original_filename' => 'source.xlsx',
        'source_path' => "corpus/{$code}.xlsx",
        'generator' => null,
        'cluster_id' => null,
        'document_type' => 'estimate',
        'owner_document_type' => 'estimate',
        'title' => "Эталон {$code}",
        'quirks' => json_encode([]),
        'type_profile' => json_encode(['class' => 'est', 'form' => 'KS2', 'expected_estimates' => 1, 'expected_rows' => null, 'gold_source' => 'deterministic']),
        'is_verified' => false,
        'verified_by' => null,
        'verified_at' => null,
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

test('битый document_type отвергается БД на prc_ai_fixtures', function (): void {
    $row = validFixtureRow('EST-XLSX-AXIS-001');
    $row['document_type'] = 'not-a-real-type';

    expect(fn () => DB::table('prc_ai_fixtures')->insert($row))->toThrow(QueryException::class);
});

test('битый owner_document_type отвергается БД на prc_ai_fixtures', function (): void {
    $row = validFixtureRow('EST-XLSX-AXIS-002');
    $row['owner_document_type'] = 'not-a-real-type';

    expect(fn () => DB::table('prc_ai_fixtures')->insert($row))->toThrow(QueryException::class);
});

test('дубль кросс-документного маршрута — единый ключ (document_type, purpose, priority, shadow) отвергает повтор', function (): void {
    AiRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => LlmPurpose::Screening,
        'priority' => 0,
        'shadow' => false,
    ]);

    expect(fn () => AiRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => LlmPurpose::Screening,
        'priority' => 0,
        'shadow' => false,
    ]))->toThrow(QueryException::class);
});
