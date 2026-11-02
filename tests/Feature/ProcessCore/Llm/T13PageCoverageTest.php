<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\DiscoveryCheckpoint;
use App\Dto\Procurement\Ai\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\PageExtractionOutput;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\PageSourceManifest;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\AnalysisRepresentation;
use App\Services\Procurement\Extraction\DiscoverySemanticValidator;
use App\Services\Procurement\Extraction\ExtractionSemanticValidator;
use Tests\Support\ProcessCore\AiFixtures;

uses()->group('t13', 't22', 'p313');

test('page coverage не выдаёт число страниц за число строк и отвергает gap и чужой anchor', function (): void {
    $range = new PageRange(0, 2);
    $anchors = ['page:0' => 0, 'page:1' => 1, 'page:0:line:0' => 0, 'page:0:line:2' => 0, 'page:1:line:0' => 1];
    $task = new StructuredTask(PageExtractionOutput::class, new PromptSnapshot('Extract source pages.', 1), TaskPurpose::Rows,
        new ExtractionSemanticValidator($range, 1, $anchors));
    $raw = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    $raw['rows'] = [$raw['rows'][0], $raw['rows'][0]];
    $raw['rows'][0]['source_anchor'] = 'page:0:line:0';
    $raw['rows'][1]['source_anchor'] = 'page:0:line:2';
    $raw['rows'][1]['row']['row_index'] = 2;
    $third = $raw['rows'][0];
    $third['source_anchor'] = 'page:1:line:0';
    $third['page_index'] = 1;
    $third['row']['row_index'] = 100000;
    $raw['rows'][] = $third;
    $raw['totals'] = [];
    $raw['covered_pages'] = [0, 1];
    $validator = new StructuredTaskValidator(new CanonicalSchemaValidator);
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::Accepted);
    $raw['covered_pages'] = [0];
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::InvalidSemantics);
    $raw['covered_pages'] = [0, 1];
    $raw['rows'][0]['source_anchor'] = 'invented';
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::InvalidSemantics);
});

test('empty page extraction требует независимый blank proof', function (): void {
    $range = new PageRange(0, 1);
    $data = new PageExtractionOutput([], [], [0]);
    expect((new ExtractionSemanticValidator($range, 1, ['page:0' => 0]))->errors($data))->toContain('empty_required_output')
        ->and((new ExtractionSemanticValidator($range, 1, ['page:0' => 0], [0]))->errors($data))->toBe([]);
});

test('discovery сохраняет все страницы и identity через split, ambiguity запрещает публикацию', function (): void {
    $task = new StructuredTask(PageDiscoveryOutput::class, new PromptSnapshot('Discover estimates.', 1), TaskPurpose::Discovery,
        new DiscoverySemanticValidator(new PageRange(2, 4), [0 => 1, 1 => 1]));
    $validator = new StructuredTaskValidator(new CanonicalSchemaValidator);
    $raw = ['pages' => [['page_index' => 2, 'estimate_ordinal' => 2], ['page_index' => 3, 'estimate_ordinal' => 2]], 'ambiguous_pages' => []];
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::Accepted);
    $raw['pages'][0]['estimate_ordinal'] = 4;
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::InvalidSemantics);
    $raw['pages'][0]['estimate_ordinal'] = 2;
    $raw['ambiguous_pages'] = [2];
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::InvalidSemantics);
    expect($validator->validate($task, '{"prefix":true}', 'MAX_TOKENS')->disposition)->toBe(StructuredDisposition::SplitRequired);
});

test('page checkpoint codec не смешивает rows и pages и проверяет полное partition', function (): void {
    $source = new PageSourceManifest(ContentHash::of('source'), 4, ['page:0' => 0, 'page:1' => 1, 'page:2' => 2, 'page:3' => 3], []);
    $checkpoint = new ExtractionCheckpoint([new PageRange(0, 2), new PageRange(2, 4)], [], 1);
    expect(ExtractionCheckpoint::restore($checkpoint->json()))->toEqual($checkpoint)
        ->and(ExtractionCheckpoint::restore($checkpoint->json())->pending[0])->toBeInstanceOf(PageRange::class);
    $discovery = new DiscoveryCheckpoint([new PageRange(2, 4)], [0 => 1, 1 => 1]);
    $discovery->assertPartition($source);
    expect(DiscoveryCheckpoint::restore(json_encode($discovery->fields(), JSON_THROW_ON_ERROR)))->toEqual($discovery)
        ->and(fn () => (new DiscoveryCheckpoint([new PageRange(1, 4)], [0 => 1, 1 => 1]))->assertPartition($source))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PageRange(0, 1)->split())->toThrow(InvalidArgumentException::class);
});

test('source manifest требует исходное полное покрытие и проверенный blank, а не model rows', function (): void {
    $text = "[page:1]\nСтрока\n[page:2]\n\n";
    $coverage = ['unit' => 'page', 'source_units' => 2, 'covered' => [[0, 2]], 'missing' => [],
        'pages' => [['page' => 1, 'status' => 'native_text'], ['page' => 2, 'status' => 'proved_blank']]];
    $reader = new AnalysisRepresentation;
    $source = $reader->source($text, ContentHash::of($text), json_encode($coverage, JSON_THROW_ON_ERROR));
    expect($source->blankPages)->toBe([1])->and($source->anchors['page:0:line:0'])->toBe(0)
        ->and(PageSourceManifest::restore(json_encode($source->fields(), JSON_THROW_ON_ERROR)))->toEqual($source)
        ->and($reader->anchored($text, new PageRange(0, 1)))->toContain('row_index:0')->not->toContain('[page:1]');
    $coverage['pages'][1]['status'] = 'unrecognized';
    expect(fn () => $reader->source($text, ContentHash::of($text), json_encode($coverage, JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reader->pages("[page:1]\nA\n[page:1]\nB"))->toThrow(InvalidArgumentException::class);
});
