<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Services\Llm\Schema\GeminiResponseSchemaAdapter;
use App\Services\Llm\Schema\JsonSchemaGenerator;

test('адаптер разворачивает $defs/$ref в плоскую inline-структуру', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(RowsChunkResponse::class));

    expect($adapted)->not->toHaveKey('$defs')
        ->and($adapted['properties']['rows']['items'])->not->toHaveKey('$ref')
        ->and($adapted['properties']['rows']['items']['type'])->toBe('object')
        ->and($adapted['properties']['rows']['items']['properties'])->toHaveKey('resources')
        ->and($adapted['properties']['rows']['items']['properties']['resources']['items'])->not->toHaveKey('$ref');
});

test('адаптер конвертирует type:[X,null] в nullable:true', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(ScreeningResult::class));

    expect($adapted['properties']['classification_note'])->toBe([
        'description' => 'необязательное пояснение классификации по видимому содержанию; не является backend-решением.',
        'type' => 'string',
        'nullable' => true,
    ]);
});

test('адаптер отбрасывает additionalProperties на всех уровнях', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(RowsChunkResponse::class));

    expect($adapted)->not->toHaveKey('additionalProperties')
        ->and($adapted['properties']['rows']['items'])->not->toHaveKey('additionalProperties');
});

test('адаптер не эмитирует propertyOrdering', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(RowsChunkResponse::class));

    expect($adapted)->not->toHaveKey('propertyOrdering')
        ->and($adapted['properties']['rows']['items'])->not->toHaveKey('propertyOrdering');
});

test('адаптер проносит description на узле объекта (вложенный $ref-контракт)', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(PassportResponse::class));

    expect($adapted['properties']['passport']['description'])
        ->toBe('тип/обоснование/направление/адрес/программа-генератор/договор — прямое соответствие prc_estimates.passport (jsonb).')
        ->and($adapted['properties']['passport']['type'])->toBe('object');
});

test('адаптер проносит description на узле массива', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(ScreeningResult::class));

    expect($adapted['properties']['tags'])->toBe([
        'description' => 'нормализованные метки видимого содержания: 0–12 непустых значений, до 64 символов каждое.',
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]);
});

test('адаптер проносит description на ветке anyOf (nullable $ref-контракт)', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(PassportResponse::class));

    expect($adapted['properties']['totals']['description'])
        ->toBe('итоги/начисления, если документ виден целиком и подвал попал в контекст; иначе null — запрашиваются отдельно через extract_totals.')
        ->and($adapted['properties']['totals'])->toHaveKey('anyOf');
});

test('адаптер обрывает рекурсивный TotalLine.children на заданной глубине без бесконечного цикла', function (): void {
    $generator = new JsonSchemaGenerator;
    $adapter = new GeminiResponseSchemaAdapter;

    $adapted = $adapter->adapt($generator->generate(TotalsResponse::class));

    // Сериализация не должна зацикливаться — само выполнение теста является гарантией.
    $json = json_encode($adapted, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('$ref')->not->toContain('$defs');

    $level1 = $adapted['properties']['totals']['properties']['footer']['items'];
    expect($level1['properties'])->toHaveKey('children');

    $level2 = $level1['properties']['children']['items'];
    expect($level2['properties'])->toHaveKey('children');

    $level3 = $level2['properties']['children']['items'];
    expect($level3['properties'])->not->toHaveKey('children');
});
