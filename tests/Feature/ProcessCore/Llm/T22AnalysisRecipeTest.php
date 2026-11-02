<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\ChunkReference;
use App\Dto\Procurement\Ai\CoverageMap;
use App\Dto\Procurement\Ai\CoverageStrategy;
use App\Dto\Procurement\Ai\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\MoneyDecimal;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\QuantityDecimal;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Ai\ValidatedChunk;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Ai\ChunkRepository;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\ExtractionContract;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

pest()->group('t22', 'p38');

test('recipe roundtrip не читает изменившиеся prompt model account options и coverage config', function (): void {
    $recipe = AiFixtures::recipe();
    config()->set('ai.extraction.window_pages', 999);
    config()->set('prism.providers.gemini.model', 'changed-after-start');
    $restored = AnalysisRecipe::restore($recipe->json, 1);
    expect($restored)->toEqual($recipe)->and($restored->llm->model)->toBe('fixture-model')
        ->and($restored->coverage->allowDegradedRepresentation)->toBeFalse()
        ->and(fn () => AnalysisRecipe::restore($recipe->json, 2))->toThrow(InvalidArgumentException::class, 'Unknown recipe');
    foreach (['provider', 'account', 'apiVersion', 'model', 'routePolicyVersion', 'options', 'thinking', 'prompt', 'schema', 'preparation', 'analyzer', 'coverage', 'currency', 'locale'] as $dimension) {
        $llm = $recipe->llm;
        $candidate = new AnalysisRecipe(
            $dimension === 'preparation' ? ContentHash::of('new') : $recipe->preparationHash,
            $dimension === 'analyzer' ? 'new' : $recipe->analyzerVersion,
            $dimension === 'prompt' ? new PromptSnapshot('changed', 2) : $recipe->prompt,
            $dimension === 'schema' ? ContentHash::of('new') : $recipe->schemaHash,
            new PinnedLlmContext($dimension === 'provider' ? 'new' : $llm->provider, $dimension === 'account' ? 'new' : $llm->account,
                $dimension === 'apiVersion' ? 'new' : $llm->apiVersion, $dimension === 'model' ? 'new' : $llm->model,
                $dimension === 'routePolicyVersion' ? 'new' : $llm->routePolicyVersion,
                $dimension === 'options' ? ['max_output_tokens' => 1] : $llm->options, $dimension === 'thinking' ? ['enabled' => true] : $llm->thinking),
            $dimension === 'coverage' ? new CoverageStrategy(4, 1, allowDegradedRepresentation: true) : $recipe->coverage,
            $dimension === 'currency' ? 'USD' : 'RUB', $dimension === 'locale' ? 'en-US' : 'ru-RU',
        );
        expect(fn () => $restored->assertSame($candidate))->toThrow(InvalidArgumentException::class, 'Recipe drift');
    }
    Http::assertNothingSent();
});

test('half-open typed ranges checkpoint codecs и hashes сохраняют input identity без rows accumulator', function (): void {
    $range = new RowRange(0, 4);
    [$left, $right] = $range->split();
    expect((new CoverageMap($range, [$left, $right]))->complete())->toBeTrue()
        ->and((new CoverageMap($range, [$left]))->complete())->toBeFalse()
        ->and((new CoverageMap($range, [new RowRange(0, 5)]))->complete())->toBeFalse();
    expect([$left->fields(), $right->fields()])->toBe([['start' => 0, 'end' => 2], ['start' => 2, 'end' => 4]])
        ->and(fn () => new RowRange(1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PageRange(-1, 4))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new RowRange(0, 1))->split())->toThrow(InvalidArgumentException::class);
    $checkpoint = new ExtractionCheckpoint([$left, $right], [new ChunkReference(LedgerFixtures::uuid(), ContentHash::of('accepted output'))], 1);
    expect(ExtractionCheckpoint::restore($checkpoint->json()))->toEqual($checkpoint)
        ->and(fn () => ExtractionCheckpoint::restore(str_replace('"version":1', '"version":2', $checkpoint->json())))->toThrow(InvalidArgumentException::class, 'Unknown checkpoint')
        ->and(fn () => new ExtractionCheckpoint([$right, $left], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ExtractionCheckpoint([], [], 256))->toThrow(InvalidArgumentException::class)
        ->and(new MoneyDecimal('-9007199254740993.01', '-9 007 199 254 740 993,01')->value)->toBe('-9007199254740993.01')
        ->and(fn () => new QuantityDecimal('1e2'))->toThrow(InvalidArgumentException::class);
    expect(CanonicalJson::hash(['b' => 2, 'a' => 1]))->toBe(CanonicalJson::hash(['a' => 1, 'b' => 2]));
});

test('canonical run window chunk rows persistent FK immutable и duplicate conflict проверяются на PostgreSQL', function (): void {
    StoredDocumentFixtures::reset();
    $window = AiFixtures::window();
    $stored = app(AnalysisRepository::class)->getRun($window->run->id);
    expect($stored)->toEqual($window->run)
        ->and(app(AnalysisRepository::class)->getWindow($window->id))->toEqual($window)
        ->and((new AnalysisRun)->getTable())->toBe('prc_ai_analysis_runs')
        ->and(app(StageRegistry::class)->handlerVersion(StageCode::Extraction))->toBeNull()
        ->and(app(StageRegistry::class)->handlerVersion(StageCode::Publication))->toBeNull();
    $task = (new ExtractionContract)->task($window->range, 1, $window->sourceAnchors);
    $result = app(StructuredTaskValidator::class)->validate($task, AiFixtures::raw(), 'STOP');
    expect($result->disposition)->toBe(StructuredDisposition::Accepted);
    $hash = ContentHash::of(CanonicalJson::encode($result->data->toArray()));
    Storage::disk('local')->put('fixture/output', CanonicalJson::encode($result->data->toArray()));
    expect(ContentHash::of(Storage::disk('local')->get('fixture/output')))->toEqual($hash);
    $chunk = new ValidatedChunk(LedgerFixtures::uuid(), $window, $window->range, 0, new ArtifactRef('local', 'fixture/output', $hash), $result);
    $reference = DB::transaction(fn () => app(ChunkRepository::class)->storeLocked($chunk));
    $duplicate = new ValidatedChunk(LedgerFixtures::uuid(), $window, $window->range, 0, $chunk->outputRef, $result);
    expect($reference)->toEqual(new ChunkReference($chunk->id, $hash))
        ->and(DB::transaction(fn () => app(ChunkRepository::class)->storeLocked($duplicate)))->toEqual($reference)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(1);
    $rows = json_decode(DB::table('prc_analysis_chunks')->value('canonical_rows'), true, flags: JSON_THROW_ON_ERROR);
    expect($rows['totals'])->toHaveCount(6)->and($rows['rows'][0]['row']['unit_price_curr'])->toBe('9007199254740993.01')
        ->and($rows['rows'][0]['source_anchor'])->toBe('p0:r0');
    $changed = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    $changed['rows'][0]['row']['name'] = 'Different validated output';
    $different = app(StructuredTaskValidator::class)->validate($task, json_encode($changed, JSON_THROW_ON_ERROR), 'STOP');
    $otherHash = ContentHash::of(CanonicalJson::encode($different->data->toArray()));
    $other = new ValidatedChunk(LedgerFixtures::uuid(), $window, $window->range, 0, new ArtifactRef('local', 'fixture/other', $otherHash), $different);
    expect(fn () => DB::transaction(fn () => app(ChunkRepository::class)->storeLocked($other)))->toThrow(InvalidArgumentException::class, 'chunk_conflict');
    foreach ([
        ['UPDATE prc_analysis_runs SET recipe_json=?::jsonb WHERE id=?', ['{}', $window->run->id->value]],
        ['UPDATE prc_analysis_windows SET input_hash=decode(?,\'hex\') WHERE id=?', [ContentHash::of('changed')->hex, $window->id->value]],
        ['UPDATE prc_analysis_chunks SET canonical_rows=?::jsonb WHERE id=?', ['{}', $chunk->id->value]],
        ['UPDATE prc_analysis_chunks SET window_id=? WHERE id=?', [LedgerFixtures::uuid()->value, $chunk->id->value]],
    ] as [$sql, $bindings]) {
        expect(LedgerFixtures::sqlState(fn () => DB::update($sql, $bindings)))->toBe('23514');
    }
    $otherWindow = AiFixtures::window();
    expect(LedgerFixtures::sqlState(fn () => DB::insert('INSERT INTO prc_analysis_runs
        SELECT ?::uuid,document_version_id,?::bigint,representation_hash,recipe_json,recipe_schema_version,recipe_hash,generation,
            expected_document_head_generation,expected_analysis_generation,requested_reason,supersedes_run_id,publication_status
        FROM prc_analysis_runs WHERE id=?', [LedgerFixtures::uuid()->value, $otherWindow->run->representationId, $window->run->id->value])))->toBe('23503')
        ->and(LedgerFixtures::sqlState(fn () => DB::insert('INSERT INTO prc_analysis_chunks
            SELECT ?::uuid,run_id,?::uuid,window_key,recipe_hash,subrange_key,1,output_ref,output_ref_schema_version,output_hash,
                canonical_rows,canonical_rows_schema_version,provenance,provenance_schema_version,completed_at
            FROM prc_analysis_chunks WHERE id=?', [LedgerFixtures::uuid()->value, $otherWindow->id->value, $chunk->id->value])))->toBe('23503')
        ->and(LedgerFixtures::sqlState(fn () => DB::insert("INSERT INTO prc_analysis_chunks
            SELECT ?::uuid,run_id,window_id,window_key,recipe_hash,subrange_key,1,output_ref,output_ref_schema_version,decode('00','hex'),
                canonical_rows,canonical_rows_schema_version,provenance,provenance_schema_version,completed_at
            FROM prc_analysis_chunks WHERE id=?", [LedgerFixtures::uuid()->value, $chunk->id->value])))->toBe('23514');
    expect(LedgerFixtures::sqlState(fn () => DB::delete('DELETE FROM prc_document_versions WHERE id=?', [$window->run->documentVersionId])))->toBe('23514');
    $drift = new AnalysisWindowSnapshot($window->id, $window->run, $window->windowKey, new RowRange(0, 1), 1, $window->sourceAnchors);
    expect($drift->inputHash)->not->toEqual($window->inputHash)
        ->and($window->subrangeInputHash(new RowRange(0, 1)))->not->toEqual($window->subrangeInputHash(new RowRange(1, 2)))
        ->and($window->subrangeKey(new RowRange(0, 1)))->not->toEqual($window->subrangeKey(new RowRange(1, 2)))
        ->and(fn () => new ValidatedChunk(LedgerFixtures::uuid(), $window, new RowRange(0, 1), 0, $chunk->outputRef, $result))->toThrow(InvalidArgumentException::class, 'drift')
        ->and(fn () => new ValidatedChunk(LedgerFixtures::uuid(), $window, $window->range, 0, new ArtifactRef('local', 'wrong', ContentHash::of('wrong')), $result))->toThrow(InvalidArgumentException::class, 'hash mismatch');
    Http::assertNothingSent();
});
