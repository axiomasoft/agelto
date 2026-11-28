<?php

declare(strict_types=1);

use App\Services\Procurement\Tuning\EvalGateEvaluator;

test('пустой JSON-объект {} принимается jsonObject() и parseResultRow()', function (): void {
    $evaluator = new EvalGateEvaluator;

    expect($evaluator->jsonObject([], 'empty_object'))->toBe([]);

    $row = $evaluator->parseResultRow(1, 'fixture_a', '0.85', []);

    expect($row)->toBe([
        'code' => 'fixture_a',
        'f1' => 0.85,
        'row_coverage' => 0.0,
        'per_field' => [],
    ]);
});

test('неассоциативный непустой список отклоняется jsonObject()', function (): void {
    $evaluator = new EvalGateEvaluator;

    expect(fn () => $evaluator->jsonObject(['a', 'b'], 'list_payload'))
        ->toThrow(RuntimeException::class, 'list_payload должен быть JSON-объектом.');
});
