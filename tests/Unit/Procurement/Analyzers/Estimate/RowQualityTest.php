<?php

declare(strict_types=1);

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Enums\Procurement\Estimate\RowQualityReason;
use Illuminate\Validation\ValidationException;

// Гейт фиксированной схемы `quality` (research/12-uncertainty-model.md §3.1, P8.3) — тем же
// приёмом, что уже применён к `attributes` (EstimateRowContractTest).

test('строка с валидной пометкой quality проходит валидацию', function (): void {
    $row = EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'quantity' => null,
        'quality' => [
            'reason' => 'unreadable',
            'fields' => ['quantity'],
        ],
    ]);

    expect($row->quality?->reason)->toBe(RowQualityReason::Unreadable)
        ->and($row->quality?->fields)->toBe(['quantity']);
});

test('неизвестный ключ внутри quality отклоняется', function (): void {
    EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'quality' => [
            'reason' => 'unreadable',
            'fields' => ['quantity'],
            'note' => 'ключ вне контракта',
        ],
    ]);
})->throws(ValidationException::class);

test('неизвестная причина quality отклоняется', function (): void {
    EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'quality' => [
            'reason' => 'illegible',
            'fields' => ['quantity'],
        ],
    ]);
})->throws(ValidationException::class);

test('причина arithmetic_mismatch (вне model-origin подмножества) отклоняется', function (): void {
    EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'quality' => [
            'reason' => 'arithmetic_mismatch',
            'fields' => ['quantity'],
        ],
    ]);
})->throws(ValidationException::class);

test('причина numbering_gap (вне model-origin подмножества) отклоняется', function (): void {
    EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'quality' => [
            'reason' => 'numbering_gap',
            'fields' => ['quantity'],
        ],
    ]);
})->throws(ValidationException::class);

test('пустой fields в quality отклоняется', function (): void {
    EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'quality' => [
            'reason' => 'unreadable',
            'fields' => [],
        ],
    ]);
})->throws(ValidationException::class);

test('строка без quality остаётся валидной (обратная совместимость)', function (): void {
    $row = EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
    ]);

    expect($row->quality)->toBeNull();
});

test('каждая строка шести существующих gold-эталонов проходит EstimateRow::validateAndCreate() без quality', function (): void {
    $paths = glob(base_path('estimates/gold/*.gold.json'));

    expect($paths)->toHaveCount(6);

    foreach ($paths as $path) {
        $gold = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($gold['estimates'] as $estimate) {
            foreach ($estimate['rows'] as $rowJson) {
                $row = EstimateRow::validateAndCreate($rowJson);

                expect($row->quality)->toBeNull(basename($path).': row_index='.$rowJson['row_index']);
            }
        }
    }
});
