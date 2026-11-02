<?php

declare(strict_types=1);

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Dto\Procurement\Ai\ExtractionOutput;
use App\Dto\Procurement\Ai\RowRange;
use App\Enums\Llm\StructuredDisposition as Decision;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\ExtractionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\AiFixtures;

pest()->group('t13', 'p38');

test('canonical schema проверяется до hydration, decimal precision и flat totals depth5 сохраняются при roundtrip', function (): void {
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $validator = app(StructuredTaskValidator::class);
    $result = $validator->validate($task, AiFixtures::raw(), 'STOP');
    expect($result->disposition)->toBe(Decision::Accepted)->and($result->data)->toBeInstanceOf(ExtractionOutput::class);
    expect($result->data->rows[0]->row->unit_price_curr)->toBe('9007199254740993.01')
        ->and($result->data->rows[1]->row->quantity)->toBeNull()->and($result->data->totals)->toHaveCount(6);
    $roundtrip = $validator->validate($task, $result->data->toJson(), 'STOP');
    expect($roundtrip->disposition)->toBe(Decision::Accepted)->and($roundtrip->data->toArray())->toBe($result->data->toArray());
    $wire = (new ProviderSchemaCompiler)->compile($task->schema);
    expect($wire->canonicalHash)->toBe($task->schema->hash)
        ->and($wire->localOnlyConstraints)->toContain('#/additionalProperties', '#/rows/maxItems')
        ->and(json_encode($wire->definition))->not->toContain('$ref', '$defs', 'children');
});

test('T13 full canonical constraints отвергают model JSON до принятого chunk и публикации', function (string $case): void {
    $payload = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    match ($case) {
        'required' => $payload['rows'][0]['row'] = ['row_index' => 0, 'row_type' => 'item'],
        'unknown root' => $payload['verified'] = true,
        'unknown nested' => $payload['rows'][0]['row']['invented'] = 1,
        'null' => $payload['rows'][0]['row']['name'] = null,
        'enum' => $payload['rows'][0]['row']['row_type'] = 'invented',
        'decimal number' => $payload['rows'][0]['row']['quantity'] = 1.25,
        'decimal exponent' => $payload['rows'][0]['row']['quantity'] = '1e8',
        'decimal grouping' => $payload['rows'][0]['row']['quantity'] = '1 000,50',
        'decimal precision bound' => $payload['rows'][0]['row']['quantity'] = str_repeat('9', 81),
        'integer bounds' => $payload['rows'][0]['page_index'] = -1,
        'nested collection' => $payload['rows'][0]['row']['resources'] = [['resource_type' => 'material', 'name' => 1]],
        'nullable required' => unsetRequiredTotalParent($payload),
        'collection bound' => $payload['totals'] = array_fill(0, 4097, $payload['totals'][0]),
        default => null,
    };
    $raw = match ($case) {
        'invalid JSON' => '{"rows":',
        'markdown' => '```json'.AiFixtures::raw().'```',
        'array root' => '[]',
        default => json_encode($payload, JSON_THROW_ON_ERROR),
    };
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $result = app(StructuredTaskValidator::class)->validate($task, $raw, 'STOP');
    expect($result->disposition)->toBe(Decision::InvalidSchema)->and($result->data)->toBeNull()
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('prc_estimates')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['required', 'unknown root', 'unknown nested', 'null', 'enum', 'decimal number', 'decimal exponent', 'decimal grouping', 'decimal precision bound', 'integer bounds', 'nested collection', 'nullable required', 'collection bound', 'invalid JSON', 'markdown', 'array root']);

function unsetRequiredTotalParent(array &$payload): void
{
    unset($payload['totals'][0]['parent_id']);
}

test('T13 anchors ranges units arithmetic coverage и parent forest проверяются семантически', function (string $case, string $error): void {
    $payload = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    match ($case) {
        'anchor' => $payload['rows'][0]['source_anchor'] = 'missing',
        'page' => $payload['rows'][0]['page_index'] = 1,
        'estimate' => $payload['rows'][0]['estimate_ordinal'] = 2,
        'range' => $payload['rows'][0]['row']['row_index'] = 2,
        'duplicate' => $payload['rows'][1] = $payload['rows'][0],
        'gap' => array_pop($payload['rows']),
        'arithmetic' => $payload['rows'][0]['row']['total_price_curr'] = '1',
        'unit' => $payload['rows'][0]['row']['unit'] = null,
        'parent' => $payload['totals'][5]['parent_id'] = 'missing',
        'cycle' => $payload['totals'][0]['parent_id'] = 'n5',
        'self' => $payload['totals'][0]['parent_id'] = 'n0',
        'duplicate node' => $payload['totals'][5]['node_id'] = 'n0',
        default => null,
    };
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $result = app(StructuredTaskValidator::class)->validate($task, json_encode($payload, JSON_THROW_ON_ERROR), 'STOP');
    expect($result->disposition)->toBe(Decision::InvalidSemantics)->and($result->errors)->toContain($error)->and($result->data)->toBeNull();
})->with([
    ['anchor', 'invalid_source_anchor'], ['page', 'invalid_source_anchor'], ['estimate', 'invalid_source_anchor'], ['range', 'row_out_of_range'],
    ['duplicate', 'duplicate_row'], ['gap', 'coverage_gap'], ['arithmetic', 'totals_arithmetic'], ['unit', 'missing_unit'],
    ['parent', 'missing_total_parent'], ['cycle', 'total_cycle'], ['self', 'total_cycle'], ['duplicate node', 'duplicate_total_node'],
]);

test('T13 provider outcomes не принимают prefix rows и не запускают скрытую continuation или reask', function (string $reason, string $raw, bool $unexpected, Decision $expected): void {
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $calls = DB::table('llm_calls')->count();
    $result = app(StructuredTaskValidator::class)->validate($task, $raw === 'fixture' ? AiFixtures::raw() : $raw, $reason, unexpectedContent: $unexpected);
    expect($result->disposition)->toBe($expected)->and($result->data)->toBeNull()
        ->and(DB::table('llm_calls')->count())->toBe($calls)->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('prc_estimates')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['MAX_TOKENS', 'fixture', false, Decision::SplitRequired], ['SAFETY', 'fixture', false, Decision::Refusal],
    ['REFUSAL', '', false, Decision::Refusal], ['STOP', '', false, Decision::Incomplete],
    ['STOP', '{"rows":[],"totals":[]}', false, Decision::Incomplete],
    ['CONTINUATION', 'fixture', false, Decision::Unsupported], ['NEW_REASON', 'fixture', false, Decision::Unsupported],
    ['TOOL_CALLS', 'fixture', false, Decision::Unsupported], ['STOP', 'fixture', true, Decision::Unsupported],
]);

test('Opis draft2020 full validation ограничен локальными refs и bounded schema input', function (): void {
    $validator = new CanonicalSchemaValidator;
    $schema = new CanonicalSchema(['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2]], 'required' => ['n'], 'additionalProperties' => false]);
    expect($validator->validate('{"n":1}', $schema)->valid())->toBeTrue()
        ->and($validator->validate('{"n":3}', $schema)->valid())->toBeFalse()
        ->and($validator->validate('{"n":"1"}', $schema)->valid())->toBeFalse()
        ->and($validator->validate('{"n":1,"x":1}', $schema)->valid())->toBeFalse()
        ->and($validator->validate(str_repeat(' ', 1048577), $schema)->valid())->toBeFalse();
    foreach (['https://example.test/schema', 'file:///etc/passwd', '#/$defs/missing'] as $reference) {
        expect(fn () => $validator->assertSchema(new CanonicalSchema(['$ref' => $reference])))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $validator->assertSchema(new CanonicalSchema(['$defs' => ['loop' => ['$ref' => '#/$defs/loop']], '$ref' => '#/$defs/loop'])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ProviderSchemaCompiler)->compile(new CanonicalSchema(['type' => 'string', 'const' => 'x'])))->toThrow(InvalidArgumentException::class, 'Unsupported wire');
    Http::assertNothingSent();
});
