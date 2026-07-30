<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\CorePromptStep;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\PromptSuggestionKind;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Tuning\PromptCritic;
use Database\Seeders\PromptSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * P7.4 — `PromptCritic` (`ai:prompts:suggest`): дешёвый критик читает diff-отчёт (P7.3) и текущую
 * композицию промта шага, предлагает патч/hint и кладёт `pending` в очередь (D8/D59–D61/D64).
 */
function promptSuggestRoute(FakeLlmProvider $fake): void
{
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => CoreLlmPurpose::PromptCritic->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash-lite',
        'priority' => 0,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function promptSuggestResponse(array $overrides = []): LlmStructuredResponse
{
    $data = array_merge([
        'kind' => PromptSuggestionKind::PromptPatch->value,
        'rationale' => 'Поле code расходится систематически — правило кода расценки неполно.',
        'evidence' => ['rows[0].code', 'rows[2].code'],
        'proposed_content' => 'Обновлённый текст правила про коды расценок.',
        'hint' => null,
        'document_type' => null,
    ], $overrides);

    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 500, completionTokens: 80, cachedTokens: 0, totalTokens: 580),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function promptSuggestGoldDir(): string
{
    $folder = sys_get_temp_dir().'/prompt-suggest-'.Str::uuid();
    File::ensureDirectoryExists($folder);
    config(['ai.fixtures.gold_dir' => $folder]);

    return $folder;
}

beforeEach(function (): void {
    app(PromptSeeder::class)->run();
});

test('критик кладёт предложение pending с заполненными rationale/evidence', function (): void {
    $fake = FakeLlmProvider::succeeding(promptSuggestResponse());
    promptSuggestRoute($fake);

    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Extraction]);
    $fixture = Fixture::factory()->create();

    $suggestion = app(PromptCritic::class)->critique(EstimatePromptStep::ExtractionRows, ['rows' => []], $analysis->id, $fixture->id);

    expect($suggestion)->not->toBeNull()
        ->and($suggestion->status->value)->toBe('pending')
        ->and($suggestion->kind)->toBe(PromptSuggestionKind::PromptPatch)
        ->and($suggestion->rationale)->not->toBe('')
        ->and($suggestion->evidence->all())->toBe(['rows[0].code', 'rows[2].code'])
        ->and($suggestion->prompt_key)->toBe(EstimatePromptStep::ExtractionRows->value)
        ->and($suggestion->document_type)->toBe('estimate')
        ->and($suggestion->analysis_step)->toBe(AnalysisStep::Extraction)
        ->and($suggestion->analysis_id)->toBe($analysis->id)
        ->and($suggestion->fixture_id)->toBe($fixture->id)
        ->and($suggestion->llm_call_id)->not->toBeNull();
});

test('патч кросс-документного промта получает сентинел any и analysis_step=null', function (): void {
    $fake = FakeLlmProvider::succeeding(promptSuggestResponse());
    promptSuggestRoute($fake);

    $suggestion = app(PromptCritic::class)->critique(CorePromptStep::Global, ['rows' => []], null, null);

    expect($suggestion)->not->toBeNull()
        ->and($suggestion->document_type)->toBe('any')
        ->and($suggestion->analysis_step)->toBeNull();
});

test('битое значение document_type отвергается БД', function (): void {
    expect(fn () => PromptSuggestion::factory()->create(['document_type' => 'not-a-real-type']))
        ->toThrow(QueryException::class);
});

test('битое значение analysis_step отвергается БД', function (): void {
    // `analysis_step` кастуется в AnalysisStep на границе Eloquent (ValueError раньше запроса),
    // поэтому нарушение check-constraint проверяется мимо модели — сырой insert построителем.
    expect(fn () => DB::table((new PromptSuggestion)->getTable())->insert([
        'uuid' => (string) Str::uuid7(),
        'kind' => PromptSuggestionKind::PromptPatch->value,
        'status' => 'pending',
        'prompt_key' => EstimatePromptStep::ExtractionRows->value,
        'prompt_step' => EstimatePromptStep::ExtractionRows->value,
        'proposed_content' => 'text',
        'document_type' => 'estimate',
        'analysis_step' => 'not-a-real-step',
        'rationale' => 'rationale',
        'evidence' => json_encode([], JSON_THROW_ON_ERROR),
    ]))->toThrow(QueryException::class);
});

test('повторный прогон на том же diff не плодит дубль', function (): void {
    $fake = FakeLlmProvider::succeeding(promptSuggestResponse())
        ->andThen(promptSuggestResponse());
    promptSuggestRoute($fake);

    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Extraction]);

    $first = app(PromptCritic::class)->critique(EstimatePromptStep::ExtractionRows, ['rows' => []], $analysis->id, null);
    $second = app(PromptCritic::class)->critique(EstimatePromptStep::ExtractionRows, ['rows' => []], $analysis->id, null);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(PromptSuggestion::query()->count())->toBe(1);
});

test('вызов критика идёт без файлов/tools — только systemPart и userParts', function (): void {
    $fake = FakeLlmProvider::succeeding(promptSuggestResponse());
    promptSuggestRoute($fake);

    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Extraction]);

    app(PromptCritic::class)->critique(EstimatePromptStep::ExtractionRows, ['rows' => []], $analysis->id, null);

    $received = $fake->receivedPrompts();
    expect($received)->toHaveCount(1)
        ->and($received[0]->fileRefs)->toBe([])
        ->and($received[0]->systemPart)->toContain('критик')
        ->and($fake->uploadedFiles())->toBe([]);
});

test('--dry-run показывает вход критика и не делает ни одного вызова', function (): void {
    $fake = FakeLlmProvider::succeeding(promptSuggestResponse());
    promptSuggestRoute($fake);

    Storage::fake(config('ai.fixtures.disk'));
    Storage::fake(config('crawler.documents_disk'));
    $goldDir = promptSuggestGoldDir();

    $fixture = Fixture::factory()->create(['code' => 'EST-XLSX-SUGGEST-001', 'sha256' => hash('sha256', 'contenido-suggest')]);
    file_put_contents("{$goldDir}/EST-XLSX-SUGGEST-001.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));

    $document = Document::factory()->create(['procurement_id' => null, 'external_id' => 'fixture:EST-XLSX-SUGGEST-001']);
    $fixture->update(['document_id' => $document->id]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'content_hash' => $fixture->sha256]);
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'document_type' => 'estimate',
        'result' => ['estimates' => []],
    ]);

    $this->artisan('ai:prompts:suggest', [
        '--code' => ['EST-XLSX-SUGGEST-001'],
        '--step' => [EstimatePromptStep::ExtractionRows->value],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('dry-run')
        ->assertExitCode(0);

    expect(PromptSuggestion::query()->count())->toBe(0)
        ->and($fake->receivedPrompts())->toBe([]);

    File::deleteDirectory($goldDir);
});
