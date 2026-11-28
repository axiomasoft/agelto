<?php

declare(strict_types=1);

use App\Dto\Llm\Execution\LlmStructuredResponse;
use App\Dto\Llm\Execution\LlmUsage;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Services\Llm\Validation\StructuredResponseValidator;
use App\Services\Llm\Validation\ValidationOutcome;
use Illuminate\Validation\ValidationException;

/** @return array<string, mixed> */
function validScreeningResultPayload(array $overrides = []): array
{
    return [...[
        'document_type' => 'estimate',
        'confidence' => 0.92,
        'summary' => 'Локальный сметный расчёт на ремонт кровли, 12 страниц',
        'estimate_cardinality' => 'single',
        'tags' => ['Москва', 'ОС', 'ФЕР', 'Кровельные работы'],
        'classification_note' => null,
    ], ...$overrides];
}

/** @param array<string, mixed> $payload */
function screeningValidationOutcome(array $payload): ValidationOutcome
{
    return app(StructuredResponseValidator::class)->validate(
        new LlmStructuredResponse(
            data: $payload,
            usage: new LlmUsage(promptTokens: 1, completionTokens: 1, cachedTokens: 0, totalTokens: 2),
            finishReason: FinishReason::Stop,
            raw: json_encode($payload, JSON_THROW_ON_ERROR),
        ),
        ScreeningResult::class,
    );
}

test('гидрирует и нормализует полный v2 JSON-ответ скрининга', function (): void {
    $result = ScreeningResult::validateAndCreate(validScreeningResultPayload([
        'summary' => "  Локальный\nсметный расчёт  ",
        'tags' => [' Москва ', 'ОС'],
        'classification_note' => '  видна только первая часть  ',
    ]));

    expect($result->document_type)->toBe(AiDocumentType::Estimate)
        ->and($result->confidence)->toBe(0.92)
        ->and($result->summary)->toBe('Локальный сметный расчёт')
        ->and($result->estimate_cardinality)->toBe(EstimateCardinality::Single)
        ->and($result->tags)->toBe(['Москва', 'ОС'])
        ->and($result->classification_note)->toBe('видна только первая часть');
});

test('summary, tags и cardinality обязательны, а legacy description отклоняется', function (): void {
    foreach (['summary', 'tags', 'estimate_cardinality'] as $missing) {
        $payload = validScreeningResultPayload();
        unset($payload[$missing]);

        expect(fn () => ScreeningResult::validateAndCreate($payload))->toThrow(ValidationException::class);
    }

    $legacy = validScreeningResultPayload(['description' => 'старое поле']);
    unset($legacy['summary']);

    expect(screeningValidationOutcome($legacy)->isOk())->toBeFalse();
});

test('отклоняет пустое или слишком длинное summary и confidence вне диапазона', function (): void {
    foreach ([
        ['summary' => '   '],
        ['summary' => str_repeat('а', 1001)],
        ['confidence' => -0.01],
        ['confidence' => 1.01],
    ] as $overrides) {
        expect(screeningValidationOutcome(validScreeningResultPayload($overrides))->isOk())->toBeFalse();
    }
});

test('отклоняет невалидную пару document type и estimate cardinality', function (): void {
    foreach ([
        ['document_type' => 'estimate', 'estimate_cardinality' => 'none'],
        ['document_type' => 'protocol', 'estimate_cardinality' => 'multiple'],
        ['document_type' => 'tech_spec', 'estimate_cardinality' => 'unknown'],
    ] as $overrides) {
        expect(screeningValidationOutcome(validScreeningResultPayload($overrides))->isOk())->toBeFalse();
    }
});

test('отклоняет пустые, повторные и чрезмерные tags', function (): void {
    foreach ([
        ['tags' => ['']],
        ['tags' => ['Москва', 'москва']],
        ['tags' => array_fill(0, 13, 'tag')],
        ['tags' => array_fill(0, 9, str_repeat('я', 64))],
    ] as $overrides) {
        expect(screeningValidationOutcome(validScreeningResultPayload($overrides))->isOk())->toBeFalse();
    }
});
