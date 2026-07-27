<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Ai\Estimate\TotalsResponse;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Services\Llm\Schema\JsonSchemaGenerator;

test('схема ScreeningResult валидна и содержит обязательные ключи', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(ScreeningResult::class);
    $json = json_encode($schema, JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBe($schema)
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['document_type', 'confidence', 'description', 'tags', 'page_count_estimate', 'ignore_reason'])
        ->and($schema['required'])->toBe(['confidence', 'document_type'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['document_type'])->toBe([
            'type' => 'string',
            'enum' => ['estimate', 'tech_spec', 'contract_draft', 'protocol', 'unknown'],
        ])
        ->and($schema['properties']['confidence'])->toBe(['type' => 'number'])
        ->and($schema['properties']['description'])->toBe(['type' => ['string', 'null']])
        ->and($schema['properties']['tags'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

test('схема RowsChunkResponse раскладывает вложенные Data-классы в $defs со ссылками $ref', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(RowsChunkResponse::class);

    expect($schema['properties']['rows'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/$defs/EstimateRow']])
        ->and($schema['properties']['incomplete_items'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/$defs/IncompleteRow']])
        ->and($schema['$defs'])->toHaveKeys(['EstimateRow', 'RowResource', 'RowModifier', 'IncompleteRow'])
        ->and($schema['$defs']['EstimateRow']['properties']['row_type'])->toBe([
            'type' => 'string',
            'enum' => ['section_header', 'subsection_header', 'item', 'subtotal', 'summary_row'],
        ])
        ->and($schema['$defs']['EstimateRow']['properties']['resources'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/$defs/RowResource']]);
});

test('рекурсивный TotalLine.children резолвится через $ref без бесконечной рекурсии', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(TotalsResponse::class);

    expect($schema['$defs'])->toHaveKeys(['EstimateTotalsBlock', 'TotalLine'])
        ->and($schema['$defs']['TotalLine']['properties']['children'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/$defs/TotalLine']])
        ->and($schema['$defs']['TotalLine']['required'])->toBe(['label']);

    // Сериализация не должна зацикливаться — само выполнение теста является гарантией.
    json_encode($schema, JSON_THROW_ON_ERROR);
});

test('генерация детерминирована между запусками', function (): void {
    $generator = new JsonSchemaGenerator;

    expect($generator->generate(RowsChunkResponse::class))->toBe($generator->generate(RowsChunkResponse::class));
});
