<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Enums\Llm\FinishReason;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Validation\StructuredResponseValidator;

function structuredResponseValidator(): StructuredResponseValidator
{
    return new StructuredResponseValidator;
}

function zeroUsage(): LlmUsage
{
    return new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0);
}

test('MAX_TOKENS отдаёт retryable ДО json_decode — обрезанный невалидный JSON не роняет каскад', function (): void {
    $response = new LlmStructuredResponse(
        data: [],
        usage: zeroUsage(),
        finishReason: FinishReason::MaxTokens,
        raw: '{"document_type": "estimate", "confid', // намеренно обрезанный/невалидный JSON
    );

    $outcome = structuredResponseValidator()->validate($response, ScreeningResult::class);

    expect($outcome->isOk())->toBeFalse()
        ->and($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->toContain('MAX_TOKENS');
});

test('finishReason=safety уходит в fatal — эскалация маршрутом, не re-ask', function (): void {
    $response = new LlmStructuredResponse(
        data: [],
        usage: zeroUsage(),
        finishReason: FinishReason::Safety,
        raw: '{"document_type": "estimate", "confidence": 0.9}',
    );

    $outcome = structuredResponseValidator()->validate($response, ScreeningResult::class);

    expect($outcome->isFatal())->toBeTrue()
        ->and($outcome->reason)->toContain('safety');
});

test('finishReason=other уходит в fatal', function (): void {
    $response = new LlmStructuredResponse(
        data: [],
        usage: zeroUsage(),
        finishReason: FinishReason::Other,
        raw: '{"document_type": "estimate", "confidence": 0.9}',
    );

    $outcome = structuredResponseValidator()->validate($response, ScreeningResult::class);

    expect($outcome->isFatal())->toBeTrue();
});

test('синтаксически невалидный JSON при finishReason=stop уходит в retryable', function (): void {
    $response = new LlmStructuredResponse(
        data: [],
        usage: zeroUsage(),
        finishReason: FinishReason::Stop,
        raw: '{"document_type": "estimate", "confidence": 0.9', // без закрывающей скобки
    );

    $outcome = structuredResponseValidator()->validate($response, ScreeningResult::class);

    expect($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->toContain('JSON');
});

test('JSON не проходит схемную валидацию Data-класса — retryable с текстом ошибки', function (): void {
    $response = new LlmStructuredResponse(
        data: [],
        usage: zeroUsage(),
        finishReason: FinishReason::Stop,
        raw: '{"description": "смета без обязательных полей"}',
    );

    $outcome = structuredResponseValidator()->validate($response, ScreeningResult::class);

    expect($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->not->toBeNull();
});

test('валидный ответ гидрируется в Data-класс и возвращается ok', function (): void {
    $response = new LlmStructuredResponse(
        data: [],
        usage: zeroUsage(),
        finishReason: FinishReason::Stop,
        raw: '{"document_type":"estimate","confidence":0.92,"summary":"Тестовая смета","estimate_cardinality":"single","tags":[],"classification_note":null}',
    );

    $outcome = structuredResponseValidator()->validate($response, ScreeningResult::class);

    expect($outcome->isOk())->toBeTrue()
        ->and($outcome->data)->toBeInstanceOf(ScreeningResult::class)
        ->and($outcome->data->confidence)->toBe(0.92);
});

test('дубликат row_index в RowsChunkResponse — retryable, структурная проверка', function (): void {
    $raw = json_encode([
        'rows' => [
            ['row_index' => 1, 'row_type' => 'item', 'name' => 'Позиция 1'],
            ['row_index' => 1, 'row_type' => 'item', 'name' => 'Позиция 1 дубль'],
        ],
    ]);

    $response = new LlmStructuredResponse(data: [], usage: zeroUsage(), finishReason: FinishReason::Stop, raw: $raw);

    $outcome = structuredResponseValidator()->validate($response, RowsChunkResponse::class);

    expect($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->toContain('Дубликат row_index');
});

test('немонотонный row_index в RowsChunkResponse — retryable', function (): void {
    $raw = json_encode([
        'rows' => [
            ['row_index' => 5, 'row_type' => 'item', 'name' => 'Позиция 5'],
            ['row_index' => 3, 'row_type' => 'item', 'name' => 'Позиция 3'],
        ],
    ]);

    $response = new LlmStructuredResponse(data: [], usage: zeroUsage(), finishReason: FinishReason::Stop, raw: $raw);

    $outcome = structuredResponseValidator()->validate($response, RowsChunkResponse::class);

    expect($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->toContain('монотонность');
});

test('валидный построчный диапазон RowsChunkResponse проходит структурную проверку и возвращается ok', function (): void {
    $raw = json_encode([
        'rows' => [
            ['row_index' => 1, 'row_type' => 'section_header', 'name' => 'Раздел 1'],
            ['row_index' => 2, 'row_type' => 'item', 'name' => 'Позиция 1'],
            ['row_index' => 3, 'row_type' => 'subtotal', 'name' => 'Итого по разделу'],
        ],
    ]);

    $response = new LlmStructuredResponse(data: [], usage: zeroUsage(), finishReason: FinishReason::Stop, raw: $raw);

    $outcome = structuredResponseValidator()->validate($response, RowsChunkResponse::class);

    expect($outcome->isOk())->toBeTrue()
        ->and($outcome->data)->toBeInstanceOf(RowsChunkResponse::class)
        ->and($outcome->data->rows)->toHaveCount(3);
});
