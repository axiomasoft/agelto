<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use App\Services\Procurement\Prepare\WordStrategy;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * `doc/docx/rtf/odt`→PDF (`WordStrategy`) и fidelity `xls(x)`→PDF через LibreOffice-фолбэк
 * (`SpreadsheetStrategy`, вторая ветка) — P3.4, D7. Реальный `soffice` в юнит-тестах не вызывается
 * (Code Guidance): фейковый обработчик распознаёт вызов конверсии по `--convert-to` и сам
 * подкладывает PDF-файл по конвенции именования LibreOffice; вызов `--version` отвечает отдельно.
 */
function makeDocumentVersion(string $extension, string $content, ?int $sizeBytes = null): array
{
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $disk = Storage::fake((string) config('crawler.documents_disk'));
    $storagePath = "documents/test.{$extension}";
    $disk->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => $extension,
        'storage_path' => $storagePath,
        'content_hash' => hash('sha256', $storagePath),
        'size_bytes' => $sizeBytes ?? strlen($content),
    ]);

    return [$document, $version];
}

function fakeSoffice(bool $conversionSucceeds, string $pdfContent = '%PDF-fake'): void
{
    Process::fake([
        '*soffice*' => function ($process) use ($conversionSucceeds, $pdfContent) {
            $command = $process->command;

            if (in_array('--convert-to', $command, true)) {
                if (! $conversionSucceeds) {
                    return Process::result(errorOutput: 'soffice: crashed on convert', exitCode: 1);
                }

                $outdirIndex = array_search('--outdir', $command, true);
                $outputDir = $command[$outdirIndex + 1];
                $inputPath = end($command);
                file_put_contents($outputDir.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf', $pdfContent);

                return Process::result();
            }

            return Process::result(output: 'LibreOffice 7.6.4.1 30(Build:4)');
        },
    ]);
}

// --- WordStrategy (doc/docx/rtf/odt → pdf через LibreOffice) ---

test('canProcess распознаёт doc/docx/rtf/odt и отклоняет прочие расширения', function () {
    [, $docx] = makeDocumentVersion('docx', 'dummy');
    [, $xlsx] = makeDocumentVersion('xlsx', 'dummy');

    $strategy = app(WordStrategy::class);

    expect($strategy->canProcess($docx))->toBeTrue();

    foreach (['doc', 'rtf', 'odt'] as $extension) {
        $docx->extension = $extension;
        expect($strategy->canProcess($docx))->toBeTrue();
    }

    expect($strategy->canProcess($xlsx))->toBeFalse();
});

test('docx → представление pdf с meta.tool=libreoffice, документ не терминален', function () {
    fakeSoffice(conversionSucceeds: true);
    [$document, $version] = makeDocumentVersion('docx', 'заглушка docx-содержимого');

    $result = app(WordStrategy::class)->process($document, $version);

    expect($result)->toBeTrue()
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::Pdf)
        ->first();

    expect($representation)->not->toBeNull()
        ->and($representation->mime)->toBe('application/pdf')
        ->and($representation->meta['tool'])->toBe('libreoffice');

    Storage::disk((string) config('crawler.documents_disk'))->assertExists($representation->storage_path);
});

test('падение LibreOffice-конверсии docx → unprocessable(conversion_failed), исход терминален', function () {
    fakeSoffice(conversionSucceeds: false);
    [$document, $version] = makeDocumentVersion('docx', 'заглушка docx-содержимого');

    $result = app(WordStrategy::class)->process($document, $version);

    expect($result)->toBeTrue()
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('conversion_failed');
});

test('повторный прогон WordStrategy идемпотентен — представление не пересоздаётся, soffice не вызывается', function () {
    fakeSoffice(conversionSucceeds: true);
    [$document, $version] = makeDocumentVersion('docx', 'заглушка docx-содержимого');

    app(WordStrategy::class)->process($document, $version);
    $result = app(WordStrategy::class)->process($document, $version);

    expect($result)->toBeTrue();
    Process::assertRanTimes(fn ($process) => in_array('--convert-to', $process->command, true), 1);
    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(1);
});

// --- SpreadsheetStrategy (xls/xlsx → pdf, вторая ветка — LibreOffice-фолбэк) ---

test('xlsx в пределах лимитов — представление через PhpSpreadsheet+Mpdf', function () {
    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));
    [$document, $version] = makeDocumentVersion('xlsx', $content);

    $result = app(SpreadsheetStrategy::class)->process($document, $version);

    expect($result)->toBeTrue()
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::Pdf)
        ->first();

    expect($representation)->not->toBeNull()
        ->and($representation->meta['tool'])->toBe('phpspreadsheet+mpdf');
})->skip(fn () => ! extension_loaded('gd'), 'требует gd (Mpdf) — см. docker/php/Dockerfile');

test('xlsx сверх лимита строк — ветка LibreOffice, представление создано, документ не unprocessable', function () {
    config(['ai.prepare.xlsx_max_rows' => 1]);
    fakeSoffice(conversionSucceeds: true);
    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));
    [$document, $version] = makeDocumentVersion('xlsx', $content);

    $result = app(SpreadsheetStrategy::class)->process($document, $version);

    expect($result)->toBeTrue()
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::Pdf)
        ->first();

    expect($representation)->not->toBeNull()
        ->and($representation->meta['tool'])->toBe('libreoffice');
});

test('xlsx сверх лимита размера — ветка LibreOffice, представление создано, документ не unprocessable', function () {
    config(['ai.prepare.xlsx_max_mb' => 0]);
    fakeSoffice(conversionSucceeds: true);
    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));
    [$document, $version] = makeDocumentVersion('xlsx', $content);

    $result = app(SpreadsheetStrategy::class)->process($document, $version);

    expect($result)->toBeTrue()
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable);

    $representation = DocumentRepresentation::query()
        ->where('document_version_id', $version->id)
        ->where('kind', RepresentationKind::Pdf)
        ->first();

    expect($representation->meta['tool'])->toBe('libreoffice');
});

test('обе ветки упали — unprocessable(conversion_failed)', function () {
    config(['ai.prepare.xlsx_max_mb' => 0]);
    fakeSoffice(conversionSucceeds: false);
    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));
    [$document, $version] = makeDocumentVersion('xlsx', $content);

    $result = app(SpreadsheetStrategy::class)->process($document, $version);

    expect($result)->toBeTrue()
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('conversion_failed');

    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
});

test('повторный прогон SpreadsheetStrategy идемпотентен — представление не пересоздаётся', function () {
    config(['ai.prepare.xlsx_max_mb' => 0]);
    fakeSoffice(conversionSucceeds: true);
    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));
    [$document, $version] = makeDocumentVersion('xlsx', $content);

    app(SpreadsheetStrategy::class)->process($document, $version);
    $result = app(SpreadsheetStrategy::class)->process($document, $version);

    expect($result)->toBeTrue();
    Process::assertRanTimes(fn ($process) => in_array('--convert-to', $process->command, true), 1);
    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(1);
});
