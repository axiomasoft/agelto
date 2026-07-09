<?php

declare(strict_types=1);

use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Provider;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * `ai:eval:run` (P7.4, ревью-фикс HIGH/MED): dry-run/пустой путь без единого LLM-вызова +
 * реальный прогон через `FakeLlmProvider` (та же подмена, что `ExtractionOrchestratorTest`, —
 * Intent «FakeLlm недопустим» про боевой прогон ВЛАДЕЛЬЦЕМ, не про тесты самой команды).
 * Ключевой сценарий — регрессия HIGH: повторный `ai:eval:run` на ТОМ ЖЕ датасете обязан сделать
 * НОВЫЕ LLM-вызовы, а не тихо переиспользовать `Analysis` завершённого прошлого прогона.
 */
function evalRunFixturePath(): string
{
    return base_path('tests/Fixtures/Documents/sample-multi-page.pdf');
}

function seedEvalRunDocumentVersion(Document $document): DocumentVersion
{
    $content = file_get_contents(evalRunFixturePath());
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    return DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => 'estimate.pdf',
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);
}

function seedEvalRunRoute(FakeLlmProvider $fake): void
{
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    AiRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => LlmPurpose::ExtractRows,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function evalRunStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 100, completionTokens: 20, cachedTokens: 0, totalTokens: 120),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function evalRunPassport(?string $estimateNumber, int $rowsExpected): LlmStructuredResponse
{
    return evalRunStructured([
        'passport' => ['type' => null, 'reference' => null, 'target' => null, 'address' => null, 'generator' => null],
        'estimate_number' => $estimateNumber,
        'currency' => 'RUB',
        'base_year' => null,
        'price_level' => null,
        'rows_expected' => $rowsExpected,
        'totals' => null,
    ]);
}

/**
 * @param  list<int>  $rowIndexes
 */
function evalRunRows(array $rowIndexes): LlmStructuredResponse
{
    $rows = array_map(static fn (int $index): array => [
        'row_index' => $index,
        'row_type' => 'item',
        'name' => "Позиция {$index}",
        'section_title' => null,
        'code' => null,
        'unit' => null,
        'quantity' => null,
        'unit_price' => null,
        'total_price' => null,
        'resources' => [],
        'modifiers' => [],
    ], $rowIndexes);

    return evalRunStructured(['rows' => $rows, 'incomplete_items' => []]);
}

function evalRunTotals(): LlmStructuredResponse
{
    return evalRunStructured(['totals' => ['footer' => [
        ['label' => 'Итого', 'value' => '100.00', 'kind' => null, 'children' => []],
    ]]]);
}

/**
 * Одна смета, один паспорт, одна строка — та же последовательность заготовленных исходов, что
 * `runWholeDocument()` проходит для сметы БЕЗ итогов на строке-терминаторе (паспорт → строки →
 * пустой rows-терминатор → итоги → паспорт-терминатор всего документа), 5 LLM-вызовов.
 *
 * @return list<LlmStructuredResponse>
 */
function evalRunOutcomes(): array
{
    return [
        evalRunPassport('1', 1),
        evalRunRows([1]),
        evalRunRows([]),
        evalRunTotals(),
        evalRunPassport(null, 0),
    ];
}

function newEvalRunFake(): FakeLlmProvider
{
    $outcomes = evalRunOutcomes();
    $fake = FakeLlmProvider::succeeding(array_shift($outcomes));

    return queueEvalRunSequence($fake, $outcomes);
}

/**
 * @param  list<LlmStructuredResponse>|null  $outcomes  по умолчанию — ПОЛНАЯ последовательность
 *                                                      (Code Guidance-регрессия HIGH требует на КАЖДЫЙ повторный прогон те же 5 исходов, что
 *                                                      и на первом, — «докладка» только continuation-хвоста без паспорта воспроизводила бы
 *                                                      ложный провал, а не реальный дефект оркестратора).
 */
function queueEvalRunSequence(FakeLlmProvider $fake, ?array $outcomes = null): FakeLlmProvider
{
    foreach ($outcomes ?? evalRunOutcomes() as $outcome) {
        $fake->andThen($outcome);
    }

    return $fake;
}

/**
 * @return array<string, mixed>
 */
function evalRunGold(): array
{
    return [
        'estimates' => [
            [
                'estimate_number' => '1',
                'rows' => [
                    [
                        'row_index' => 1,
                        'row_type' => 'item',
                        'name' => 'Позиция 1',
                        'code' => null,
                        'unit' => null,
                        'quantity' => null,
                        'total_price' => null,
                    ],
                ],
            ],
        ],
    ];
}

test('пустой датасет без --dry-run пишет EvalRun-заглушку без единого LLM-вызова', function () {
    $this->artisan('ai:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1);

    $run = EvalRun::query()->firstOrFail();

    expect($run->provider)->toBe('dry-run')
        ->and($run->model)->toBe('dry-run')
        ->and($run->ci_status)->toBe('pass')
        ->and($run->metrics)->toBeNull();
});

test('--dry-run пропускает реальные LLM-вызовы даже с непустым датасетом', function () {
    // Ни один AiRoute/провайдер не заведён — если бы `--dry-run` НЕ прервал команду ДО
    // `AiRouteResolver::resolve()`, артизан-вызов упал бы `RuntimeException` («нет активного
    // маршрута»), и тест сам провалился бы. «Exit 0» здесь и есть доказательство «ни одного
    // обращения к LLM-инфраструктуре».
    DatasetExample::factory()->create(['task' => 'extraction']);

    $this->artisan('ai:eval:run', ['--task' => 'extraction', '--dry-run' => true])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1)
        ->and(EvalRun::query()->firstOrFail()->provider)->toBe('dry-run')
        ->and(EvalResult::query()->count())->toBe(0);
});

test('реальный прогон извлекает эталон и пишет метрики', function () {
    Storage::fake('s3');

    $fake = newEvalRunFake();
    seedEvalRunRoute($fake);

    $document = Document::factory()->create(['procurement_id' => null]);
    $documentVersion = seedEvalRunDocumentVersion($document);

    DatasetExample::factory()->create([
        'document_version_id' => $documentVersion->id,
        'task' => 'extraction',
        'gold' => evalRunGold(),
    ]);

    $this->artisan('ai:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    $run = EvalRun::query()->firstOrFail();

    expect($run->provider)->not->toBe('dry-run')
        ->and($run->metrics['succeeded'])->toBe(1)
        ->and((float) $run->metrics['overall']['f1'])->toBe(1.0)
        ->and(EvalResult::query()->count())->toBe(1)
        ->and(EvalResult::query()->firstOrFail()->metric)->toBe('f1');
});

test('повторный прогон на том же датасете делает НОВЫЕ LLM-вызовы, а не переиспользует завершённый Analysis', function () {
    Storage::fake('s3');

    $fake = newEvalRunFake();
    seedEvalRunRoute($fake);

    $document = Document::factory()->create(['procurement_id' => null]);
    $documentVersion = seedEvalRunDocumentVersion($document);

    DatasetExample::factory()->create([
        'document_version_id' => $documentVersion->id,
        'task' => 'extraction',
        'gold' => evalRunGold(),
    ]);

    $this->artisan('ai:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect($fake->receivedPrompts())->toHaveCount(5);

    $firstRun = EvalRun::query()->firstOrFail();
    expect((float) $firstRun->metrics['overall']['f1'])->toBe(1.0);

    // Второй прогон — ещё 5 заготовленных исходов ПОВЕРХ уже опустошённой FIFO-очереди. Если бы
    // `ai:eval:run` тихо переиспользовал завершённый `Analysis` прошлого прогона (HIGH до фикса),
    // цикл оркестратора завершился бы БЕЗ единого обращения к очереди — счётчик ниже это ловит.
    queueEvalRunSequence($fake);

    $this->artisan('ai:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect($fake->receivedPrompts())->toHaveCount(10)
        ->and(EvalRun::query()->count())->toBe(2)
        ->and(Analysis::query()->count())->toBe(1);

    $secondRun = EvalRun::query()->orderByDesc('id')->firstOrFail();
    expect((float) $secondRun->metrics['overall']['f1'])->toBe(1.0);
});
