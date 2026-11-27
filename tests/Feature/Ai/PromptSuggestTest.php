<?php

declare(strict_types=1);

use App\Dto\Llm\Execution\LlmStructuredResponse;
use App\Dto\Llm\Execution\LlmUsage;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\PromptSuggestionKind;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\Providers\CatalogOnlyProvider;
use App\Services\Procurement\Tuning\PromptCritic;
use Database\Seeders\PromptSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * `PromptCritic` (`estimate:prompts:suggest`): дешёвый критик читает diff-отчёт и текущую
 * композицию промта шага, предлагает патч/hint и кладёт `pending` в очередь.
 */
function promptSuggestRoute(FakeLlmProvider $fake): void
{
    app()->instance(CatalogOnlyProvider::class, $fake);

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

test('--dry-run показывает вход критика и не делает ни одного вызова', function (): void {
    $fake = FakeLlmProvider::succeeding(promptSuggestResponse());
    promptSuggestRoute($fake);

    Storage::fake(config('ai.fixtures.disk'));
    Storage::fake(config('documents.disk'));
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

    $this->artisan('estimate:prompts:suggest', [
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
