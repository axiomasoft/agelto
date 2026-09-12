<?php

declare(strict_types=1);

use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\ExtractionRoute;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Models\Llm\LlmCall;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Extraction\ExtractionPreflight;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

// Тесты оркестровки (Scope Included P6.1): FakeLlmProvider — строгая FIFO-очередь заготовленных
// исходов, порядок вызовов ниже ЗЕРКАЛИТ цикл ExtractionOrchestrator::runWholeDocument()
// (паспорт → диапазоны строк до пустого ответа → итоги → следующая смета → паспорт-терминатор).

function extractionFixtureContent(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));
}

function seedExtractionVersion(Document $document, string $fixture = 'sample-multi-page.pdf'): DocumentVersion
{
    $content = extractionFixtureContent($fixture);
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => $fixture,
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);

    // Launch-точка боевого конвейера (§3.5 root/document-contour.md), см. ScreeningProcessorTest.
    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);

    return $version;
}

function seedExtractionRoute(FakeLlmProvider $fake): void
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
function extractionStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 500, completionTokens: 50, cachedTokens: 0, totalTokens: 550),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function extractionPassport(?string $estimateNumber, int $rowsExpected, ?array $totals = null): LlmStructuredResponse
{
    return extractionStructured([
        'passport' => ['type' => null, 'reference' => null, 'target' => null, 'address' => null, 'generator' => null],
        'estimate_number' => $estimateNumber,
        'currency' => 'RUB',
        'base_year' => null,
        'price_level' => null,
        'rows_expected' => $rowsExpected,
        'totals' => $totals,
    ]);
}

/**
 * @param  list<int>  $rowIndexes
 */
function extractionRows(array $rowIndexes): LlmStructuredResponse
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

    return extractionStructured(['rows' => $rows, 'incomplete_items' => []]);
}

function extractionTotals(): LlmStructuredResponse
{
    return extractionStructured(['totals' => ['footer' => [
        ['label' => 'Итого', 'value' => '100.00', 'kind' => null, 'children' => []],
    ]]]);
}

/**
 * `config(['ai.capabilities.<model>.<key>' => ...])` НЕ работает — имя модели само содержит
 * точки («gemini-2.5-flash»), `Arr::set()`/`Arr::get()` дробят их как сегменты пути (тот же
 * баг, что в `ExtractionPreflight`/`LlmCallRecorder::costMicros()`) — правим массив целиком.
 */
function overrideCapability(string $model, string $key, mixed $value): void
{
    $capabilities = (array) config('ai.capabilities', []);
    $capabilities[$model][$key] = $value;
    config(['ai.capabilities' => $capabilities]);
}

/**
 * Одна item-строка с искусственно раздутым `name` (> 1МБ) — переводит
 * `EstimatePersister::rawResultAttributes()` на ветку `Storage::disk(raw_disk)->put()`
 * (иначе `raw_result` пишется inline и `Storage` вообще не вызывается).
 */
function extractionHugeRow(int $index): LlmStructuredResponse
{
    return extractionStructured(['rows' => [[
        'row_index' => $index,
        'row_type' => 'item',
        'name' => str_repeat('A', 1_100_000),
        'section_title' => null,
        'code' => null,
        'unit' => null,
        'quantity' => null,
        'unit_price' => null,
        'total_price' => null,
        'resources' => [],
        'modifiers' => [],
    ]], 'incomplete_items' => []]);
}

function extractionMaxTokens(): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: [],
        usage: new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0),
        finishReason: FinishReason::MaxTokens,
        raw: '',
    );
}

test('оркестратор извлекает 2 сметы построчными диапазонами и итогами — Analysis succeeded, Document extracted', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 2))
        ->andThen(extractionRows([1, 2]))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport('2', 1))
        ->andThen(extractionRows([1]))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport(null, 0));

    seedExtractionRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    seedExtractionVersion($document);

    (new EstimateExtractionJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->result['estimates'])->toHaveCount(2)
        ->and($analysis->result['estimates'][0]['estimate_number'])->toBe('1')
        ->and($analysis->result['estimates'][0]['rows'])->toHaveCount(2)
        ->and($analysis->result['estimates'][0]['totals_done'])->toBeTrue()
        ->and($analysis->result['estimates'][1]['estimate_number'])->toBe('2')
        ->and($analysis->result['estimates'][1]['rows'])->toHaveCount(1)
        ->and($analysis->finished_at)->not->toBeNull();

    expect($document->refresh()->status)->toBe(DocumentStatus::Extracted);

    // Файл загружен ОДИН раз за весь многошаговый прогон (переиспользование Files-хэндла между
    // вызовами через ExtractionSession, не re-upload на каждый чанк).
    expect($fake->uploadedFiles())->toHaveCount(1);
});

test('MAX_TOKENS на диапазоне строк — окно уменьшается вдвое, извлечение продолжается тем же начальным row_index', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 1))
        ->andThen(extractionMaxTokens())
        ->andThen(extractionRows([1]))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport(null, 0));

    seedExtractionRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    seedExtractionVersion($document);

    config(['ai.extraction.row_range_size' => 30, 'ai.extraction.min_row_range_size' => 5]);

    (new EstimateExtractionJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->result['estimates'][0]['row_range_size'])->toBe(15)
        ->and($analysis->result['estimates'][0]['rows'])->toHaveCount(1);
});

test('checkpoint self-requeue — после M единиц прогресса job переставлен в очередь, дубля нет', function (): void {
    Storage::fake('s3');
    Queue::fake();

    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 2));
    seedExtractionRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    seedExtractionVersion($document);

    config(['ai.extraction.checkpoint_ranges' => 1]);

    (new EstimateExtractionJob($document->id))->handle();

    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job): bool => $job->documentId === $document->id);
    Queue::assertPushed(EstimateExtractionJob::class, 1);

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Running)
        ->and($analysis->progress['estimates'])->toHaveCount(1)
        ->and($analysis->progress['estimates'][0]['estimate_number'])->toBe('1');

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);
});

test('resume — второй запуск job продолжает с progress, не повторяя завершённые шаги', function (): void {
    Storage::fake('s3');
    Queue::fake();

    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 2))
        ->andThen(extractionRows([1, 2]))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport(null, 0));

    seedExtractionRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    seedExtractionVersion($document);

    config(['ai.extraction.checkpoint_ranges' => 2]);

    // Запуск 1: 2 единицы (паспорт + первый диапазон строк), затем checkpoint — self-requeue
    // перехвачен Queue::fake(), реального продолжения не происходит.
    (new EstimateExtractionJob($document->id))->handle();

    $analysisAfterFirstRun = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();
    expect($analysisAfterFirstRun->status)->toBe(AnalysisStatus::Running)
        ->and($analysisAfterFirstRun->progress['estimates'][0]['rows'])->toHaveCount(2)
        ->and($analysisAfterFirstRun->progress['estimates'][0]['rows_done'])->toBeFalse();

    // Запуск 2 (симулирует воркер, забравший ре-диспатченный job): ещё 2 единицы (пустой
    // диапазон-терминатор + итоги) — не повторяет уже извлечённые строки сметы №1.
    (new EstimateExtractionJob($document->id))->handle();

    // Запуск 3: последняя единица (паспорт-терминатор — смет больше нет) доводит до финала
    // В ТОМ ЖЕ прогоне (передышка checkpoint не требуется — 1 единица < порога 2).
    (new EstimateExtractionJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->result['estimates'])->toHaveCount(1)
        ->and($analysis->result['estimates'][0]['rows'])->toHaveCount(2);

    expect($document->refresh()->status)->toBe(DocumentStatus::Extracted);

    // Файл загружен один раз за ВСЕ 3 запуска (переиспользование через progress['file_uri']
    // между self-requeue, не повторный upload на каждый чекпоинт).
    expect($fake->uploadedFiles())->toHaveCount(1);

    expect(Analysis::query()->where('step', AnalysisStep::Extraction)->count())->toBe(1);
});

test('preflight сверх лимитов маршрута: windowed_enabled=true выбирает Windowed, false — unprocessable', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 1));
    $provider = Provider::factory()->create(['driver' => 'gemini']);
    app()->instance(PrismGeminiProvider::class, $fake);

    $route = new ResolvedRoute(
        provider: $fake,
        options: new LlmOptions(provider: 'gemini', model: 'gemini-2.5-flash', temperature: 0.0, maxTokens: 8192),
        route: LlmRoute::factory()->make(['provider_id' => $provider->id]),
    );

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    $version = seedExtractionVersion($document);
    $localPath = tempnam(sys_get_temp_dir(), 'preflight_test_').'.pdf';
    file_put_contents($localPath, Storage::disk('s3')->get($version->storage_path));

    overrideCapability('gemini-2.5-flash', 'max_pages', 1);

    config(['ai.extraction.windowed_enabled' => false]);
    $decisionUnprocessable = app(ExtractionPreflight::class)->decide($localPath, 'pdf', $route);

    config(['ai.extraction.windowed_enabled' => true]);
    $decisionWindowed = app(ExtractionPreflight::class)->decide($localPath, 'pdf', $route);

    @unlink($localPath);

    expect($decisionUnprocessable->isUnprocessable())->toBeTrue()
        ->and($decisionUnprocessable->unprocessableReason->value)->toBe('provider_limit_pages')
        ->and($decisionWindowed->isUnprocessable())->toBeFalse()
        ->and($decisionWindowed->route)->toBe(ExtractionRoute::Windowed);
});

test('preflight-отказ (сверх лимитов, windowed выключен) — документ unprocessable(provider_limit_pages), LLM не вызывается', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 1));
    seedExtractionRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    seedExtractionVersion($document);

    overrideCapability('gemini-2.5-flash', 'max_pages', 1);
    // Default `ai.extraction.windowed_enabled` — TRUE с P6.4 (WindowedChunkSource реализован) —
    // этот тест целенаправленно проверяет исход ПРИ ВЫКЛЮЧЕННОМ гейте, форсируем явно.
    config(['ai.extraction.windowed_enabled' => false]);

    (new EstimateExtractionJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('provider_limit_pages');

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();
    expect($analysis->status)->toBe(AnalysisStatus::Skipped)
        ->and($analysis->result['unprocessable_reason'])->toBe('provider_limit_pages');

    expect($fake->uploadedFiles())->toBeEmpty();
});

test('сбой EstimatePersister::persist() (Storage::put) НЕ переводит analysis/document в терминал — повтор дожимает извлечение (review-фикс P6.3 HIGH)', function (): void {
    Storage::fake('s3');

    // Сбой ловится ПОСЛЕ полного извлечения (на finalizeSuccess->persist), ДО единственного
    // checkpoint в этом сценарии — прогресс НЕ был сохранён в БД (progress персистится только
    // по checkpoint-порогу, D31/D39), поэтому retry начинает извлечение заново «с нуля»: ниже
    // ДУБЛИРУЕМ полную последовательность ответов на второй прогон.
    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 1))
        ->andThen(extractionHugeRow(1))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport(null, 0))
        ->andThen(extractionPassport('1', 1))
        ->andThen(extractionHugeRow(1))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport(null, 0));

    seedExtractionRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    seedExtractionVersion($document);

    // `LlmCallRecorder::storeRaw()` (каждый LLM-вызов) использует ТОТ ЖЕ `crawler.raw_disk`,
    // что `EstimatePersister::rawResultAttributes()` — портить конфиг С НАЧАЛА рвало бы первый
    // же вызов извлечения, а не именно persist. Флип диска — ОДНОРАЗОВЫЙ листенер на 5-е
    // созданное `LlmCall` (ровно столько вызовов даёт один проход извлечения: паспорт → строки
    // → пустой диапазон → итоги → паспорт-терминатор, СМ. FakeLlmProvider-очередь выше), т.е.
    // ПОСЛЕ того, как последний LLM-вызов уже успешно записал свой raw через `storeRaw()` —
    // ломает диск СТРОГО к моменту входа в `EstimatePersister::persist()` (finalizeSuccess),
    // не раньше. Условие `=== 5` — одноразовое: на retry счётчик уходит за 5 и не срабатывает
    // повторно, поэтому конфиг для второго прогона восстанавливается явно (см. ниже) один раз.
    $callsRecorded = 0;
    LlmCall::created(function () use (&$callsRecorded): void {
        $callsRecorded++;

        if ($callsRecorded === 5) {
            config(['crawler.raw_disk' => 'nonexistent-disk-for-test']);
        }
    });

    $thrown = null;

    try {
        (new EstimateExtractionJob($document->id))->handle();
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('nonexistent-disk-for-test');

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    // Статусы остались промежуточными — НЕ terminal (было бы document=extracted/analysis=succeeded
    // ДО фикса, статус-гейт EstimateExtractionJob::statusGuardPassed() навсегда блокировал бы retry).
    expect($document->refresh()->status)->toBe(DocumentStatus::Screened)
        ->and($analysis->status)->not->toBe(AnalysisStatus::Succeeded)
        ->and(Estimate::query()->where('analysis_id', $analysis->id)->exists())->toBeFalse();

    // Повтор (диск снова доступен) — statusGuardPassed пропускает retry, извлечение и персист
    // дожимаются идемпотентно, без дублей/зависших статусов.
    config(['crawler.raw_disk' => 's3']);

    (new EstimateExtractionJob($document->id))->handle();

    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::Succeeded);
    expect($document->refresh()->status)->toBe(DocumentStatus::Extracted);
    expect(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(1);
});

test('ошибка document update откатывает successful analysis finalize а retry сходится без дубля estimate', function (): void {
    Storage::fake('s3');
    $fake = FakeLlmProvider::succeeding(extractionPassport('1', 1))
        ->andThen(extractionHugeRow(1))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport(null, 0))
        ->andThen(extractionPassport('1', 1))
        ->andThen(extractionHugeRow(1))
        ->andThen(extractionRows([]))
        ->andThen(extractionTotals())
        ->andThen(extractionPassport(null, 0));
    seedExtractionRoute($fake);
    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    seedExtractionVersion($document);
    DB::unprepared(<<<'SQL'
CREATE FUNCTION fail_extracted_document_update() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'extracted' THEN
        RAISE EXCEPTION 'injected document finalization failure';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER fail_extracted_document_update
BEFORE UPDATE ON prc_documents
FOR EACH ROW EXECUTE FUNCTION fail_extracted_document_update();
SQL);

    expect(fn () => (new EstimateExtractionJob($document->id))->handle())
        ->toThrow(QueryException::class, 'injected document finalization failure');
    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();
    expect($analysis->status)->not->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->finished_at)->toBeNull()
        ->and($document->fresh()->status)->toBe(DocumentStatus::Screened)
        ->and(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(1);

    DB::unprepared('DROP TRIGGER fail_extracted_document_update ON prc_documents; DROP FUNCTION fail_extracted_document_update();');
    (new EstimateExtractionJob($document->id))->handle();

    expect($analysis->fresh()->status)->toBe(AnalysisStatus::Succeeded)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Extracted)
        ->and(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(1);
});
