<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\DocumentFormatRegistry;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use App\Services\Procurement\Prepare\WordStrategy;
use Illuminate\Contracts\Filesystem\Filesystem;
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

test('registry направляет текстовые документы в typed Word handler', function () {
    $registry = app(DocumentFormatRegistry::class);

    foreach (['doc', 'docx', 'rtf', 'odt'] as $extension) {
        expect($registry->forExtension($extension)['strategy'])->toBe(WordStrategy::class);
    }

    expect($registry->forExtension('xlsx')['strategy'])->not->toBe(WordStrategy::class);
});

test('docx → представление pdf с meta.tool=libreoffice, документ не терминален', function () {
    fakeSoffice(conversionSucceeds: true);
    [$document, $version] = makeDocumentVersion('docx', 'заглушка docx-содержимого');

    $result = app(WordStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
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

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('conversion_failed');
});

test('повторный прогон WordStrategy идемпотентен — представление не пересоздаётся, soffice не вызывается', function () {
    fakeSoffice(conversionSucceeds: true);
    [$document, $version] = makeDocumentVersion('docx', 'заглушка docx-содержимого');

    app(WordStrategy::class)->process($document, $version);
    $result = app(WordStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening);
    Process::assertRanTimes(fn ($process) => in_array('--convert-to', $process->command, true), 1);
    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(1);
});

test('два документа одного исходного хеша сохраняют различающиеся результаты LibreOffice без конфликта', function () {
    $diskName = (string) config('crawler.documents_disk');
    $disk = Storage::fake($diskName);
    $source = '{\rtf1 одинаковый исходный документ}';
    $sourceHash = hash('sha256', $source);
    $pdfs = ['%PDF-first-render', '%PDF-second-render'];
    $conversionCount = 0;

    Process::fake([
        '*soffice*' => function ($process) use (&$conversionCount, $pdfs) {
            $command = $process->command;

            if (in_array('--convert-to', $command, true)) {
                $outdirIndex = array_search('--outdir', $command, true);
                $outputDir = $command[$outdirIndex + 1];
                $inputPath = end($command);
                file_put_contents(
                    $outputDir.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf',
                    $pdfs[$conversionCount++],
                );

                return Process::result();
            }

            return Process::result(output: 'LibreOffice 7.6.4.1 30(Build:4)');
        },
    ]);

    $versions = collect(['first', 'second'])->map(function (string $name) use ($disk, $source, $sourceHash): array {
        $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
        $storagePath = "documents/{$name}.rtf";
        $disk->put($storagePath, $source);

        $version = DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'extension' => 'rtf',
            'storage_path' => $storagePath,
            'content_hash' => $sourceHash,
            'size_bytes' => strlen($source),
        ]);

        return [$document, $version];
    });

    foreach ($versions as [$document, $version]) {
        expect(app(WordStrategy::class)->process($document, $version))
            ->toBe(PreparationResult::ReadyForScreening);
    }

    [$firstVersion, $secondVersion] = [$versions[0][1], $versions[1][1]];
    $firstRepresentation = DocumentRepresentation::query()
        ->where('document_version_id', $firstVersion->id)
        ->where('kind', RepresentationKind::Pdf)
        ->firstOrFail();
    $secondRepresentation = DocumentRepresentation::query()
        ->where('document_version_id', $secondVersion->id)
        ->where('kind', RepresentationKind::Pdf)
        ->firstOrFail();
    $basePath = 'documents/'.substr($sourceHash, 0, 2)."/{$sourceHash}.prepared.pdf";

    expect($firstRepresentation->storage_path)
        ->toBe(preparedRepresentationPath(path: $basePath, content: $pdfs[0]))
        ->not->toBe($secondRepresentation->storage_path)
        ->and($secondRepresentation->storage_path)
        ->toBe(preparedRepresentationPath(path: $basePath, content: $pdfs[1]))
        ->and($disk->get($firstRepresentation->storage_path))->toBe($pdfs[0])
        ->and($disk->get($secondRepresentation->storage_path))->toBe($pdfs[1]);
});

test('ошибка object storage после Word-конверсии остаётся retryable', function () {
    fakeSoffice(conversionSucceeds: true);
    $diskName = (string) config('crawler.documents_disk');
    $sourcePath = 'documents/transient.docx';
    $source = 'заглушка docx-содержимого';
    $sourceHash = hash('sha256', $source);
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => 'docx',
        'storage_path' => $sourcePath,
        'content_hash' => $sourceHash,
        'size_bytes' => strlen($source),
    ]);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('get')->once()->with($sourcePath)->andReturn($source);
    $disk->shouldReceive('writeStream')->once()->with(
        Mockery::pattern('/\.staging\./'),
        Mockery::on(static fn (mixed $stream): bool => is_resource($stream)
            && stream_get_contents($stream) === '%PDF-fake'),
    )
        ->andThrow(new RuntimeException('disk unavailable'));
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnFalse();
    Storage::set($diskName, $disk);

    expect(fn () => app(WordStrategy::class)->process($document, $version))
        ->toThrow(RuntimeException::class, 'disk unavailable');

    expect($document->refresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
});

// --- SpreadsheetStrategy (xls/xlsx → pdf, вторая ветка — LibreOffice-фолбэк) ---

test('xlsx в пределах лимитов — представление через PhpSpreadsheet+Mpdf', function () {
    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));
    [$document, $version] = makeDocumentVersion('xlsx', $content);

    $result = app(SpreadsheetStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ReadyForScreening)
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

    expect($result)->toBe(PreparationResult::ReadyForScreening)
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

    expect($result)->toBe(PreparationResult::ReadyForScreening)
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

    expect($result)->toBe(PreparationResult::Unprocessable)
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

    expect($result)->toBe(PreparationResult::ReadyForScreening);
    Process::assertRanTimes(fn ($process) => in_array('--convert-to', $process->command, true), 1);
    expect(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(1);
});

test('ошибка object storage после LibreOffice-фолбэка таблицы остаётся retryable', function () {
    config(['ai.prepare.xlsx_max_mb' => 0]);
    fakeSoffice(conversionSucceeds: true);
    $diskName = (string) config('crawler.documents_disk');
    $sourcePath = 'documents/transient.xlsx';
    $source = file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));
    $sourceHash = hash('sha256', $source);
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => 'xlsx',
        'storage_path' => $sourcePath,
        'content_hash' => $sourceHash,
        'size_bytes' => strlen($source),
    ]);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('get')->once()->with($sourcePath)->andReturn($source);
    $disk->shouldReceive('writeStream')->once()->with(
        Mockery::pattern('/\.staging\./'),
        Mockery::on(static fn (mixed $stream): bool => is_resource($stream)
            && stream_get_contents($stream) === '%PDF-fake'),
    )
        ->andThrow(new RuntimeException('disk unavailable'));
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnFalse();
    Storage::set($diskName, $disk);

    expect(fn () => app(SpreadsheetStrategy::class)->process($document, $version))
        ->toThrow(RuntimeException::class, 'disk unavailable');

    expect($document->refresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
});

function preparedRepresentationPath(string $path, string $content): string
{
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    $stem = substr($path, 0, -strlen($extension) - 1);

    return "{$stem}.".hash('sha256', $content).".{$extension}";
}
