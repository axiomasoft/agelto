<?php

declare(strict_types=1);

use App\Enums\Llm\CorePromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Prompt;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * `estimate:eval:run` (P7.4, ревью-фикс HIGH/MED): dry-run/пустой путь без единого LLM-вызова +
 * реальный прогон через `FakeLlmProvider` (та же подмена, что `ExtractionOrchestratorTest`, —
 * Intent «FakeLlm недопустим» про боевой прогон ВЛАДЕЛЬЦЕМ, не про тесты самой команды).
 * Ключевой сценарий — регрессия HIGH: повторный `estimate:eval:run` на ТОМ ЖЕ датасете обязан сделать
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

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
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
 * Заводит эталон (реестр `prc_ai_fixtures`) + пример датасета extraction, привязанный к нему через
 * `fixture_id` (P7.5, D119/D120 — фильтр `--split` резолвит подвыборку по коду ЭТОГО эталона).
 */
function evalRunSeedFixtureExample(string $code, string $form = 'KS2', string $sourceFormat = 'xlsx', GoldSource $goldSource = GoldSource::Deterministic): DatasetExample
{
    $fixture = Fixture::factory()->create([
        'code' => $code,
        'source_format' => $sourceFormat,
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => $form,
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => $goldSource->value,
        ],
    ]);

    $document = Document::factory()->create(['procurement_id' => null]);
    $documentVersion = seedEvalRunDocumentVersion($document);

    return DatasetExample::factory()->create([
        'document_version_id' => $documentVersion->id,
        'fixture_id' => $fixture->id,
        'task' => 'extraction',
        'gold' => evalRunGold(),
    ]);
}

/**
 * Файл разбиения `estimates/eval-splits.json` в ИЗОЛИРОВАННОМ временном пути (config-override,
 * D119/D120) — тесты не трогают committed-файл корпуса.
 *
 * @param  list<string>  $holdout
 * @param  list<string>  $tune
 */
function evalRunWriteSplitsFile(array $holdout, array $tune = []): void
{
    $path = storage_path('framework/testing/eval-splits-'.uniqid().'.json');

    file_put_contents($path, json_encode([
        'generated_at' => '2026-07-28T00:00:00+00:00',
        'rule' => 'test-fixture',
        'holdout' => $holdout,
        'tune' => $tune,
    ], JSON_UNESCAPED_UNICODE));

    config(['ai.fixtures.splits_path' => $path]);
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
    $this->artisan('estimate:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1);

    $run = EvalRun::query()->firstOrFail();

    expect($run->provider)->toBe('dry-run')
        ->and($run->model)->toBe('dry-run')
        ->and($run->ci_status)->toBe('pass')
        ->and($run->metrics)->toBeNull();
});

test('--dry-run пропускает реальные LLM-вызовы даже с непустым датасетом', function () {
    // Ни один LlmRoute/провайдер не заведён — если бы `--dry-run` НЕ прервал команду ДО
    // `AiRouteResolver::resolve()`, артизан-вызов упал бы `RuntimeException` («нет активного
    // маршрута»), и тест сам провалился бы. «Exit 0» здесь и есть доказательство «ни одного
    // обращения к LLM-инфраструктуре».
    DatasetExample::factory()->create(['task' => 'extraction']);

    $this->artisan('estimate:eval:run', ['--task' => 'extraction', '--dry-run' => true])->assertExitCode(0);

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

    $this->artisan('estimate:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

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

    $this->artisan('estimate:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect($fake->receivedPrompts())->toHaveCount(5);

    $firstRun = EvalRun::query()->firstOrFail();
    expect((float) $firstRun->metrics['overall']['f1'])->toBe(1.0);

    // Второй прогон — ещё 5 заготовленных исходов ПОВЕРХ уже опустошённой FIFO-очереди. Если бы
    // `estimate:eval:run` тихо переиспользовал завершённый `Analysis` прошлого прогона (HIGH до фикса),
    // цикл оркестратора завершился бы БЕЗ единого обращения к очереди — счётчик ниже это ловит.
    queueEvalRunSequence($fake);

    $this->artisan('estimate:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect($fake->receivedPrompts())->toHaveCount(10)
        ->and(EvalRun::query()->count())->toBe(2)
        ->and(Analysis::query()->count())->toBe(1);

    $secondRun = EvalRun::query()->orderByDesc('id')->firstOrFail();
    expect((float) $secondRun->metrics['overall']['f1'])->toBe(1.0);
});

test('--split=all не меняет сегодняшнее поведение прогона (дефолт, D120)', function () {
    Storage::fake('s3');

    $fake = newEvalRunFake();
    seedEvalRunRoute($fake);

    evalRunSeedFixtureExample('EST-XLSX-ALL-001');

    $this->artisan('estimate:eval:run', ['--task' => 'extraction', '--split' => 'all'])->assertExitCode(0);

    $run = EvalRun::query()->firstOrFail();

    expect($run->metrics['succeeded'])->toBe(1)
        ->and((float) $run->metrics['overall']['f1'])->toBe(1.0)
        ->and(EvalResult::query()->count())->toBe(1);
});

test('--split=holdout берёт ровно коды из файла разбиения', function () {
    Storage::fake('s3');

    $fake = newEvalRunFake();
    seedEvalRunRoute($fake);

    evalRunWriteSplitsFile(holdout: ['EST-XLSX-HOLD-001'], tune: ['EST-XLSX-TUNE-001']);

    $holdExample = evalRunSeedFixtureExample('EST-XLSX-HOLD-001');
    evalRunSeedFixtureExample('EST-XLSX-TUNE-001');

    $this->artisan('estimate:eval:run', ['--task' => 'extraction', '--split' => 'holdout'])->assertExitCode(0);

    expect(EvalResult::query()->count())->toBe(1)
        ->and(EvalResult::query()->firstOrFail()->dataset_example_id)->toBe($holdExample->id);
});

test('--split=tune берёт коды датасета, которых НЕТ в holdout, включая код, отсутствующий в ключе tune файла (D120)', function () {
    Storage::fake('s3');

    $fake = newEvalRunFake();
    queueEvalRunSequence($fake); // второй tune-пример — ещё одна полная последовательность исходов
    seedEvalRunRoute($fake);

    // Ключ `tune` файла называет только один код — второй tune-код появился в датасете уже ПОСЛЕ
    // генерации файла и в ключе `tune` не значится, но обязан попасть в `--split=tune` по семантике
    // дополнения (D120), а не по буквальному списку.
    evalRunWriteSplitsFile(holdout: ['EST-XLSX-HOLD-002'], tune: ['EST-XLSX-TUNE-002']);

    evalRunSeedFixtureExample('EST-XLSX-HOLD-002');
    evalRunSeedFixtureExample('EST-XLSX-TUNE-002');
    evalRunSeedFixtureExample('EST-XLSX-EXTRA-002');

    $this->artisan('estimate:eval:run', ['--task' => 'extraction', '--split' => 'tune'])->assertExitCode(0);

    $processedCodes = EvalResult::query()
        ->with('datasetExample.fixture')
        ->get()
        ->map(fn (EvalResult $result): ?string => $result->datasetExample?->fixture?->code)
        ->sort()
        ->values()
        ->all();

    expect($processedCodes)->toBe(['EST-XLSX-EXTRA-002', 'EST-XLSX-TUNE-002']);
});

test('--write-splits: единственный носитель формата уходит в tune, следующий кандидат — в holdout (инвариант source_format, D119)', function () {
    evalRunWriteSplitsFile(holdout: [], tune: []); // задаёт временный splits_path для этого теста

    Fixture::factory()->create([
        'code' => 'EST-GSFX-AAA-001',
        'source_format' => 'gsfx',
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => 'AAA',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic->value,
        ],
    ]);
    Fixture::factory()->create([
        'code' => 'EST-XLSX-AAA-002',
        'source_format' => 'xlsx',
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => 'AAA',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic->value,
        ],
    ]);
    Fixture::factory()->create([
        'code' => 'EST-XLSX-BBB-001',
        'source_format' => 'xlsx',
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => 'BBB',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic->value,
        ],
    ]);

    $this->artisan('estimate:eval:run', ['--write-splits' => true])->assertExitCode(0);

    $written = json_decode((string) file_get_contents((string) config('ai.fixtures.splits_path')), associative: true);

    expect($written['holdout'])->toBe(['EST-XLSX-AAA-002'])
        ->and($written['tune'])->toBe(['EST-GSFX-AAA-001', 'EST-XLSX-BBB-001']);
});

test('prompt_version_set прогона собран из фактических вызовов и непуст', function () {
    Storage::fake('s3');

    Prompt::factory()->create([
        'key' => 'global-rule',
        'step' => CorePromptStep::Global->value,
        'version' => 2,
        'is_active' => true,
    ]);

    $fake = newEvalRunFake();
    seedEvalRunRoute($fake);

    evalRunSeedFixtureExample('EST-XLSX-PVS-001');

    $this->artisan('estimate:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    $run = EvalRun::query()->firstOrFail();

    expect($run->prompt_version_set)->not->toBeNull()
        ->and($run->prompt_version_set->toArray())->toBe(['global-rule' => 2]);
});
