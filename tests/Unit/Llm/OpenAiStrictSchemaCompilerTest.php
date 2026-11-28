<?php

declare(strict_types=1);

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Dto\Procurement\Ai\Output\ExtractionOutput;
use App\Dto\Procurement\Ai\Output\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\Output\PageExtractionOutput;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use App\Services\Llm\Schema\OpenAiStrictSchemaCompiler;
use App\Services\Llm\Schema\ProviderSchemaCompiler;

/**
 * @param  array<string, mixed>  $node
 */
function assertEveryObjectNodeIsStrict(array $node, string $path = '#'): void
{
    if (($node['type'] ?? null) === 'object' || isset($node['properties'])) {
        expect($node['additionalProperties'] ?? null)->toBeFalse("Ожидался additionalProperties=false в {$path}");
        $properties = $node['properties'] ?? [];
        expect($properties)->toBeArray("Ожидался массив properties в {$path}");
        $propertyKeys = array_map(static fn (int|string $k): string => (string) $k, array_keys($properties));
        $required = $node['required'] ?? null;
        expect($required)->toBeArray("Ожидался список required в {$path}");
        sort($propertyKeys);
        $sortedRequired = $required;
        sort($sortedRequired);
        expect($sortedRequired)->toBe($propertyKeys, "required должен содержать 100% ключей properties в {$path}");

        foreach ($properties as $name => $propertySchema) {
            if (is_array($propertySchema)) {
                assertEveryObjectNodeIsStrict($propertySchema, "{$path}.{$name}");
            }
        }
    }

    if (isset($node['items']) && is_array($node['items'])) {
        assertEveryObjectNodeIsStrict($node['items'], "{$path}[]");
    }

    if (isset($node['anyOf']) && is_array($node['anyOf'])) {
        foreach ($node['anyOf'] as $idx => $branch) {
            if (is_array($branch)) {
                assertEveryObjectNodeIsStrict($branch, "{$path}|anyOf[{$idx}]");
            }
        }
    }
}

test('все схемы пайплайна смет компилируются в строгий формат с additionalProperties=false и полным required', function (string $dtoClass): void {
    $canonical = new CanonicalSchema((new JsonSchemaGenerator)->generate($dtoClass, canonical: true));
    $strictCompiled = (new OpenAiStrictSchemaCompiler)->compile($canonical);
    $providerCompiled = (new ProviderSchemaCompiler)->compile($canonical);
    $strictified = (new OpenAiStrictSchemaCompiler)->strictify($providerCompiled->definition);

    assertEveryObjectNodeIsStrict($strictCompiled->definition);
    assertEveryObjectNodeIsStrict($strictified);
})->with([
    'ScreeningResult' => [ScreeningResult::class],
    'PageDiscoveryOutput' => [PageDiscoveryOutput::class],
    'PageExtractionOutput' => [PageExtractionOutput::class],
    'ExtractionOutput' => [ExtractionOutput::class],
]);

test('канонический валидатор и Spatie Data принимают ответ с явным null для необязательных полей с дефолтом', function (): void {
    $canonical = new CanonicalSchema((new JsonSchemaGenerator)->generate(ScreeningResult::class, canonical: true));
    $payload = [
        'document_type' => 'estimate',
        'confidence' => 0.94,
        'summary' => 'Локальный сметный расчёт на строительные работы.',
        'estimate_cardinality' => 'single',
        'tags' => ['сметный расчёт'],
        'classification_note' => null,
    ];
    $rawJson = CanonicalJson::encode($payload);

    $validation = (new CanonicalSchemaValidator)->validate($rawJson, $canonical);
    $dto = ScreeningResult::from($payload);

    expect($validation->errors)->toBe([])
        ->and($dto->classification_note)->toBeNull()
        ->and($dto->document_type->value)->toBe('estimate')
        ->and($dto->estimate_cardinality->value)->toBe('single');
});
