<?php

declare(strict_types=1);

use App\Attributes\Ai\Description;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\DiscoveryResponse;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use Spatie\LaravelData\Data;

final class JsonSchemaGeneratorFixtureNested extends Data
{
    public function __construct(
        #[Description('вложенное строковое поле фикстуры.')]
        public string $label,
    ) {}
}

final class JsonSchemaGeneratorFixtureRoot extends Data
{
    /**
     * @param  JsonSchemaGeneratorFixtureNested[]  $items
     */
    public function __construct(
        #[Description('обязательное булево поле корня.')]
        public bool $flag,
        #[Description('вложенный объект корня.')]
        public JsonSchemaGeneratorFixtureNested $nested,
        #[Description('массив вложенных объектов.')]
        public array $items,
        #[Description('nullable-ссылка на вложенный объект.')]
        public ?JsonSchemaGeneratorFixtureNested $optionalNested = null,
        public ?string $bare = null,
    ) {}
}

final class JsonSchemaGeneratorFixtureNoAttributes extends Data
{
    public function __construct(
        public string $zeta,
        public string $alpha,
    ) {}
}

test('description доезжает до корня, вложенного объекта, элемента массива и nullable-свойства', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(JsonSchemaGeneratorFixtureRoot::class);

    expect($schema['properties']['flag'])->toBe(['description' => 'обязательное булево поле корня.', 'type' => 'boolean'])
        ->and($schema['properties']['nested'])->toBe(['description' => 'вложенный объект корня.', '$ref' => '#/$defs/JsonSchemaGeneratorFixtureNested'])
        ->and($schema['$defs']['JsonSchemaGeneratorFixtureNested']['properties']['label'])->toBe(['description' => 'вложенное строковое поле фикстуры.', 'type' => 'string'])
        ->and($schema['properties']['items'])->toBe(['description' => 'массив вложенных объектов.', 'type' => 'array', 'items' => ['$ref' => '#/$defs/JsonSchemaGeneratorFixtureNested']])
        ->and($schema['properties']['optionalNested'])->toBe([
            'description' => 'nullable-ссылка на вложенный объект.',
            'anyOf' => [['$ref' => '#/$defs/JsonSchemaGeneratorFixtureNested'], ['type' => 'null']],
        ])
        ->and($schema['properties']['bare'])->toBe(['type' => ['string', 'null']]);
});

test('порядок свойств и required — порядок конструктора, не алфавит', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(JsonSchemaGeneratorFixtureRoot::class);

    // Алфавитный порядок дал бы bare, flag, items, nested, optionalNested — фикстура намеренно
    // расходится с ним (bare — последний параметр конструктора, но первый по алфавиту).
    expect(array_keys($schema['properties']))->toBe(['flag', 'nested', 'items', 'optionalNested', 'bare'])
        ->and($schema['required'])->toBe(['flag', 'nested', 'items'])
        ->and($schema)->not->toHaveKey('propertyOrdering');
});

test('контракт без атрибутов даёт схему без ключей description, с точностью до порядка свойств', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(JsonSchemaGeneratorFixtureNoAttributes::class);

    expect($schema)->toBe([
        'type' => 'object',
        'properties' => [
            'zeta' => ['type' => 'string'],
            'alpha' => ['type' => 'string'],
        ],
        'required' => ['zeta', 'alpha'],
        'additionalProperties' => false,
    ]);
});

test('схема ScreeningResult валидна и содержит обязательные ключи', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(ScreeningResult::class);
    $json = json_encode($schema, JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBe($schema)
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['document_type', 'confidence', 'description', 'tags', 'page_count_estimate', 'ignore_reason'])
        ->and(array_keys($schema['properties']))->toBe(['document_type', 'confidence', 'description', 'tags', 'page_count_estimate', 'ignore_reason'])
        ->and($schema['required'])->toBe(['document_type', 'confidence'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['document_type'])->toBe([
            'description' => 'тип документа — estimate/tech_spec/contract_draft/protocol/unknown.',
            'type' => 'string',
            'enum' => ['estimate', 'tech_spec', 'contract_draft', 'protocol', 'unknown'],
        ])
        ->and($schema['properties']['confidence']['type'])->toBe('number')
        ->and($schema['properties']['description']['type'])->toBe(['string', 'null'])
        ->and($schema['properties']['tags']['type'])->toBe('array');
});

test('схема RowsChunkResponse раскладывает вложенные Data-классы в $defs со ссылками $ref', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(RowsChunkResponse::class);

    expect($schema['properties']['rows']['type'])->toBe('array')
        ->and($schema['properties']['rows']['items'])->toBe(['$ref' => '#/$defs/EstimateRow'])
        ->and($schema['properties']['incomplete_items']['items'])->toBe(['$ref' => '#/$defs/IncompleteRow'])
        ->and($schema['$defs'])->toHaveKeys(['EstimateRow', 'RowResource', 'RowModifier', 'IncompleteRow'])
        ->and($schema['$defs']['EstimateRow']['properties']['row_type'])->toBe([
            'description' => 'section_header/subsection_header/item/subtotal/summary_row.',
            'type' => 'string',
            'enum' => ['section_header', 'subsection_header', 'item', 'subtotal', 'summary_row'],
        ])
        ->and($schema['$defs']['EstimateRow']['properties']['resources']['items'])->toBe(['$ref' => '#/$defs/RowResource']);
});

test('рекурсивный TotalLine.children резолвится через $ref без бесконечной рекурсии', function (): void {
    $generator = new JsonSchemaGenerator;

    $schema = $generator->generate(TotalsResponse::class);

    expect($schema['$defs'])->toHaveKeys(['EstimateTotalsBlock', 'TotalLine'])
        ->and($schema['$defs']['TotalLine']['properties']['children']['items'])->toBe(['$ref' => '#/$defs/TotalLine'])
        ->and($schema['$defs']['TotalLine']['required'])->toBe(['label']);

    // Сериализация не должна зацикливаться — само выполнение теста является гарантией.
    json_encode($schema, JSON_THROW_ON_ERROR);
});

test('генерация детерминирована между запусками', function (): void {
    $generator = new JsonSchemaGenerator;

    expect($generator->generate(RowsChunkResponse::class))->toBe($generator->generate(RowsChunkResponse::class));
});

test('схемы Response-классов всех шагов извлечения и скрининга совпадают с golden-фикстурой', function (): void {
    $generator = new JsonSchemaGenerator;

    $classes = [
        'DiscoveryResponse' => DiscoveryResponse::class,
        'PassportResponse' => PassportResponse::class,
        'RowsChunkResponse' => RowsChunkResponse::class,
        'ScreeningResult' => ScreeningResult::class,
        'TotalsResponse' => TotalsResponse::class,
    ];

    $actual = [];

    foreach ($classes as $shortName => $class) {
        $actual[$shortName] = $generator->generate($class);
    }

    $golden = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Llm/step-json-schemas.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // Схема — контракт structured output провайдера: её молчаливое изменение ломает разбор
    // ответа, а не тест (P6.1 Implementation Rule 2). Фикстура пересобрана в P2.4 (эмиссия
    // description + порядок конструктора, D89) — байты снова снимаются здесь до следующего шага.
    expect($actual)->toBe($golden);
});
