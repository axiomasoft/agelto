<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Exceptions\Procurement\Prepare\OperationBudgetExceeded;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\DocumentFormatRegistry;
use App\Services\Procurement\Prepare\OcrConverter;
use App\Services\Procurement\Prepare\ScanStrategy;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\PreparationStrategyProbe;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

/** OCR-инструменты подменены: тест проверяет аргументы и обработку результатов без реального OCR. */
function makeScanVersion(string $extension = 'pdf', string $content = '%PDF-скан'): array
{
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $disk = Storage::fake((string) config('documents.disk'));
    $storagePath = "documents/scan.{$extension}";
    $disk->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => $extension,
        'storage_path' => $storagePath,
        'content_hash' => hash('sha256', $storagePath),
        'size_bytes' => strlen($content),
    ]);

    return [$document, $version];
}

/**
 * @param  float  $charsPerPage  сколько символов текстового слоя «увидит» pdftotext на страницу
 */
function fakeOcrTools(float $charsPerPage = 0.0, bool $ocrSucceeds = true, string $sidecarText = "Наименование работ\nМонтаж двери\n"): void
{
    Process::fake([
        '*pdftotext*' => Process::result(output: str_repeat('a', (int) round($charsPerPage * 3))),
        '*pdfinfo*' => Process::result(output: "Title:\nPages:          2\nEncrypted:      no\n"),
        '*tesseract*' => Process::result(output: "tesseract 5.5.0\n leptonica-1.85.0"),
        '*ocrmypdf*' => function ($process) use ($ocrSucceeds, $sidecarText) {
            if (! $ocrSucceeds) {
                return Process::result(errorOutput: 'ocrmypdf: TaggedPDFError', exitCode: 4);
            }

            $command = $process->command;
            $sidecarIndex = array_search('--sidecar', $command, true);
            file_put_contents($command[$sidecarIndex + 1], $sidecarText);
            file_put_contents(end($command), '%PDF-с-текстовым-слоем');

            return Process::result();
        },
    ]);
}

test('registry направляет PDF в typed scan handler', function () {
    $registry = app(DocumentFormatRegistry::class);

    expect($registry->forExtension('pdf')['strategy'])->toBe(ScanStrategy::class);

    foreach (['jpg', 'jpeg', 'png', 'tiff', 'xlsx', 'docx', 'gsfx', 'zip'] as $extension) {
        expect($registry->forExtension($extension)['strategy'])->not->toBe(ScanStrategy::class);
    }
});

test('PDF с текстовым слоем — стратегия вернула false, OCR не запускался', function () {
    fakeOcrTools(charsPerPage: 900.0);
    [$document, $version] = makeScanVersion();

    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);

    Process::assertNotRan(fn ($process) => str_contains((string) implode(' ', (array) $process->command), 'ocrmypdf'));
});

test('зонд не прочитал файл как PDF — стратегия вернула false, OCR не запускался', function () {
    fakeOcrTools();
    Process::fake([
        '*pdftotext*' => Process::result(errorOutput: 'Syntax Error: Couldn\'t find trailer dictionary', exitCode: 1),
        '*ocrmypdf*' => Process::result(),
    ]);
    [$document, $version] = makeScanVersion('pdf', 'битые байты, не PDF');

    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);

    Process::assertNotRan(fn ($process) => str_contains((string) ($process->command[0] ?? ''), 'ocrmypdf'));
});

test('PDF без текстового слоя — созданы два представления, pdf_ocr и text', function () {
    fakeOcrTools(sidecarText: "Наименование работ\nМонтаж дверного блока\n");
    [$document, $version] = makeScanVersion();

    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable);

    $pdfOcr = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::PdfOcr)
        ->first();

    $text = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::Text)
        ->first();

    expect($pdfOcr)->not->toBeNull()
        ->and($pdfOcr->mime)->toBe('application/pdf')
        ->and($pdfOcr->page_count)->toBe(2)
        ->and($pdfOcr->meta['tool'])->toBe('ocrmypdf')
        ->and($pdfOcr->meta['languages'])->toBe('rus+eng')
        ->and($pdfOcr->meta['tesseract_version'])->toBe('tesseract 5.5.0')
        ->and($pdfOcr->meta['duration_ms'])->toBeInt()
        ->and($text)->not->toBeNull()
        ->and($text->mime)->toBe('text/plain')
        ->and($text->token_estimate)->toBeGreaterThan(0);

    $disk = Storage::disk((string) config('documents.disk'));
    $disk->assertExists($pdfOcr->storage_path);
    expect($disk->get($text->storage_path))->toContain('Монтаж дверного блока');
});

test('состав аргументов ocrmypdf несёт --language rus+eng, --sidecar и --skip-text', function () {
    fakeOcrTools();
    [$document, $version] = makeScanVersion();

    app(ScanStrategy::class)->process($document, $version);

    Process::assertRan(function ($process) {
        $command = (array) $process->command;

        if (! str_contains((string) ($command[0] ?? ''), 'ocrmypdf')) {
            return false;
        }

        $languageIndex = array_search('--language', $command, true);
        $sidecarIndex = array_search('--sidecar', $command, true);

        return $languageIndex !== false
            && $command[$languageIndex + 1] === 'rus+eng'
            && $sidecarIndex !== false
            && str_ends_with($command[$sidecarIndex + 1], '.txt')
            && in_array('--skip-text', $command, true)
            && in_array('--output-type', $command, true);
    });
});

test('падение ocrmypdf — unprocessable(ocr_failed), представлений нет', function () {
    fakeOcrTools(ocrSucceeds: false);
    [$document, $version] = makeScanVersion();

    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('ocr_failed')
        ->and($document->last_error)->toContain('TaggedPDFError')
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
});

test('пустой sidecar — unprocessable(ocr_failed), представлений нет', function () {
    fakeOcrTools(sidecarText: "  \n\n");
    [$document, $version] = makeScanVersion();

    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('ocr_failed')
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
});

test('повторный прогон идемпотентен — ocrmypdf не вызывается второй раз, представлений остаётся два', function () {
    fakeOcrTools();
    [$document, $version] = makeScanVersion();

    app(ScanStrategy::class)->process($document, $version);
    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(2);

    Process::assertRanTimes(fn ($process) => str_contains((string) ($process->command[0] ?? ''), 'ocrmypdf'), 1);
});

test('изображение — OCR запускается без зонда текстового слоя, представления созданы', function () {
    fakeOcrTools(sidecarText: "Ведомость дверей\n");
    [$document, $version] = makeScanVersion('jpg', 'jpeg-байты');

    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(2);

    Process::assertNotRan(fn ($process) => str_contains((string) ($process->command[0] ?? ''), 'pdftotext'));
});

test('падение OCR на изображении НЕ терминально — false, документ уходит в vision-ветку', function () {
    fakeOcrTools(ocrSucceeds: false);
    [$document, $version] = makeScanVersion('jpg', 'jpeg-байты');

    $result = app(ScanStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
});

test('OCR не начинается, если после text-layer probe не помещается весь обязательный хвост', function (): void {
    Process::fake();
    $operations = config('documents.format_tools');
    $deadlineAt = microtime(as_float: true)
        + $operations['ocr_seconds']
        + $operations['tesseract_version_seconds']
        + $operations['pdf_page_count_seconds']
        + $operations['persistence_seconds']
        - 2;

    expect(fn (): mixed => app(OcrConverter::class)->run(
        inputPath: '/tmp/source.pdf',
        outputPdfPath: '/tmp/output.pdf',
        sidecarTxtPath: '/tmp/output.txt',
        deadlineAt: $deadlineAt,
    ))->toThrow(OperationBudgetExceeded::class);

    Process::assertNotRan(fn ($process): bool => str_contains((string) ($process->command[0] ?? ''), 'ocrmypdf'));
});

test('text-layer probe допускается после двух секунд source setup', function (): void {
    Process::fake(['*pdftotext*' => Process::result(output: '')]);

    app(OcrConverter::class)->textCharsPerPage(
        pdfPath: '/tmp/source.pdf',
        deadlineAt: microtime(as_float: true) + config('process.stages.prepare.slice') - 2,
    );

    Process::assertRan(fn ($process): bool => str_contains((string) ($process->command[0] ?? ''), 'pdftotext'));
});

test('исчерпание prepare deadline остаётся retriable ошибкой, а не unprocessable PDF', function (): void {
    Process::fake();
    [$document, $version] = makeScanVersion(content: '%PDF-1.7\nскан');

    expect(fn (): mixed => (new PreparationStrategyProbe(documentId: $document->id, budgetSeconds: 1))->handle())
        ->toThrow(OperationBudgetExceeded::class);

    expect($document->refresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and($document->attempts)->toBe(0)
        ->and($document->last_error)->toBeNull()
        ->and($document->next_retry_at)->toBeNull()
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);

    Process::assertNotRan(fn ($process): bool => str_contains((string) ($process->command[0] ?? ''), 'pdftotext'));
});
