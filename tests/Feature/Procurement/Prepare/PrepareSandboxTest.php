<?php

declare(strict_types=1);

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Exceptions\Procurement\Prepare\OperationBudgetExceeded;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\Archive\ArchiveValidationException;
use App\Services\Procurement\Prepare\DocumentPreparer;
use App\Services\Procurement\Prepare\LibreOfficeConverter;
use App\Services\Procurement\Prepare\OcrConverter;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use App\Services\Procurement\Prepare\WordStrategy;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\FakePrepareTools;

afterEach(fn () => FakePrepareTools::cleanup());

test('повреждённые и небезопасные офисные ZIP отклоняются до запуска LibreOffice', function (string $extension, ?string $xml): void {
    $tools = FakePrepareTools::instance();
    $tools->soffice();
    $disk = Storage::fake((string) config('documents.disk'));
    $storagePath = "documents/source.{$extension}";
    $bytes = "PK\x03\x04";

    if ($xml !== null) {
        $path = PipelineTemporaryFile::create(prefix: 'office_', extension: $extension);
        try {
            $zip = new ZipArchive;
            expect($zip->open($path, ZipArchive::OVERWRITE))->toBeTrue();
            expect($zip->addFromString('content.xml', $xml))->toBeTrue()
                ->and($zip->close())->toBeTrue();
            $bytes = (string) file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    $disk->put($storagePath, $bytes);
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => $extension,
        'storage_path' => $storagePath,
        'size_bytes' => strlen($bytes),
    ]);
    $strategy = $extension === 'xlsx' ? app(SpreadsheetStrategy::class) : app(WordStrategy::class);

    expect($strategy->process(document: $document, version: $version))->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->last_error)->toContain('unreadable_format')
        ->and($tools->calls('soffice_bin'))->toBe([]);
})->with([
    'битый XLSX' => ['xlsx', null],
    'битый DOCX' => ['docx', null],
    'объявление DOCTYPE в DOCX' => ['docx', '<!DOCTYPE document><document/>'],
    'объявление ENTITY в ODT' => ['odt', '<!DOCTYPE document [<!ENTITY example "текст">]><document/>'],
]);

test('исчерпанный срок подготовки офисного файла сохраняет возможность повтора', function (string $extension): void {
    config(['ai.prepare.xlsx_max_mb' => 0]);
    $tools = FakePrepareTools::instance();
    $tools->soffice();
    $disk = Storage::fake((string) config('documents.disk'));
    $filename = "source.{$extension}";
    $bytes = $extension === 'xlsx'
        ? (string) file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'))
        : '{\\rtf1\\ansi Test document}';
    $disk->put($filename, $bytes);
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'filename' => $filename,
        'extension' => $extension,
        'storage_path' => $filename,
        'size_bytes' => strlen($bytes),
    ]);

    expect(fn () => app(DocumentPreparer::class)->prepare(document: $document, version: $version, deadlineAt: microtime(true) - 1))
        ->toThrow(OperationBudgetExceeded::class)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and($tools->calls('soffice_bin'))->toBe([]);
})->with(['rtf', 'xlsx']);

test('срок подготовки останавливает уже запущенный экспорт LibreOffice', function (): void {
    $directory = PipelineTemporaryFile::createDirectory(prefix: 'soffice_deadline_');
    $input = $directory.'/source.rtf';
    file_put_contents($input, '{\\rtf1 Test}');
    $tools = FakePrepareTools::instance();
    $tools->configure(key: 'soffice_bin', code: 'import time; time.sleep(20)');

    try {
        expect(fn () => app(LibreOfficeConverter::class)->toPdf(inputPath: $input, outputDir: $directory, deadlineAt: microtime(true) + 2.5))
            ->toThrow(OperationBudgetExceeded::class)
            ->and($tools->calls('soffice_bin'))->toHaveCount(1)
            ->and(is_file($directory.'/source.pdf'))->toBeFalse();
    } finally {
        PipelineTemporaryFile::deleteDirectory($directory);
    }
});

test('конвертеры изолируют сеть и дожидаются остановки и сбора дочерних процессов', function (string $bin): void {
    $code = <<<'PY'
import socket

assert 'APP_KEY' not in os.environ
assert os.environ.get('OMP_THREAD_LIMIT') == '1'
assert Path(args[-1]).read_bytes() == b'%PDF-test'
try:
    socket.socket(socket.AF_INET, socket.SOCK_STREAM).close()
except PermissionError:
    pass
else:
    sys.exit('network unexpectedly available')

try:
    Path(data['sibling']).read_bytes()
except PermissionError:
    pass
else:
    sys.exit('sibling workspace unexpectedly available')

child = subprocess.Popen(['/bin/sleep', '30'], start_new_session=True)
report = str(child.pid)
if '--outdir' in args:
    output = Path(args[args.index('--outdir') + 1]) / Path(args[-1]).with_suffix('.pdf').name
    output.write_text(report)
elif data['bin'] == 'pdfinfo_bin':
    print('Pages: ' + report)
else:
    print('----------\n\nPath = ' + report + '\nSize = 1\nPacked Size = 1\n')
PY;
    $directory = PipelineTemporaryFile::createDirectory(prefix: 'sandbox_test_');
    $path = $directory.'/source.pdf';
    $sibling = $directory.'/secret.txt';
    file_put_contents($path, '%PDF-test');
    file_put_contents($sibling, 'private test data');
    FakePrepareTools::instance()->configure(key: $bin, code: $code, data: ['bin' => $bin, 'sibling' => $sibling]);
    $outputDir = $directory.'/output';
    mkdir($outputDir);

    try {
        $pid = match ($bin) {
            'soffice_bin' => file_get_contents(app(LibreOfficeConverter::class)->toPdf(inputPath: $path, outputDir: $outputDir)),
            'pdfinfo_bin' => app(OcrConverter::class)->pageCount($path),
            'sevenzip_bin' => app(ArchiveExtractor::class)->listEntries(archivePath: $path, format: '7z')[0]->path,
        };

        expect(is_numeric($pid))->toBeTrue()
            ->and((int) $pid)->toBeGreaterThan(1)
            ->and(file_exists('/proc/'.(int) $pid))->toBeFalse();
    } finally {
        PipelineTemporaryFile::deleteDirectory($directory);
    }
})->with(['soffice_bin', 'pdfinfo_bin', 'sevenzip_bin']);

test('LibreOffice конвертирует книгу в PDF внутри песочницы', function (): void {
    $directory = PipelineTemporaryFile::createDirectory(prefix: 'soffice_real_');

    try {
        $pdf = app(LibreOfficeConverter::class)->toPdf(
            inputPath: base_path('tests/Fixtures/Documents/sample.xlsx'),
            outputDir: $directory,
        );

        expect((string) file_get_contents($pdf))->toStartWith('%PDF');
    } finally {
        PipelineTemporaryFile::deleteDirectory($directory);
    }
});

test('распаковщик передаёт крупную запись потоком и отклоняет превышение заявленного размера', function (): void {
    $directory = PipelineTemporaryFile::createDirectory(prefix: 'archive_sandbox_');
    $archive = $directory.'/source.7z';
    $target = $directory.'/entry.txt';
    file_put_contents($archive, 'test input');
    $size = 9 * 1024 * 1024;
    FakePrepareTools::instance()->configure(
        key: 'sevenzip_bin',
        code: "for _ in range(9):\n    sys.stdout.buffer.write(b'x' * 1048576)",
    );
    $budget = ArchiveBudget::fromConfig();
    $total = 0;
    $extractor = app(ArchiveExtractor::class);

    try {
        $entry = new ArchiveEntry(path: 'entry.txt', declaredSize: $size, compressedSize: $size, isDirectory: false, isEncrypted: false, type: 'file');
        $extractor->extractEntry(archivePath: $archive, format: '7z', entry: $entry, targetPath: $target, budget: $budget, totalBytes: $total);
        expect(filesize($target))->toBe($size)->and($total)->toBe($size);
        unlink($target);

        $entry = new ArchiveEntry(path: 'entry.txt', declaredSize: $size - 1, compressedSize: $size, isDirectory: false, isEncrypted: false, type: 'file');
        expect(fn () => $extractor->extractEntry(archivePath: $archive, format: '7z', entry: $entry, targetPath: $target, budget: $budget, totalBytes: $total))
            ->toThrow(ArchiveValidationException::class, 'превысил бюджет')
            ->and(is_file($target))->toBeFalse();
    } finally {
        PipelineTemporaryFile::deleteDirectory($directory);
    }
});

test('песочница явно отклоняет неподдерживаемую архитектуру до запуска команды', function (): void {
    $directory = PipelineTemporaryFile::createDirectory(prefix: 'sandbox_arch_');
    $receipt = $directory.'/receipt';
    $marker = $directory.'/started';
    $supervisor = app_path('Services/Procurement/Prepare/tool_supervisor.py');
    $code = <<<'PY'
import platform
import runpy
import sys

platform.machine = lambda: 'aarch64'
sys.argv = sys.argv[1:]
runpy.run_path(sys.argv[0], run_name='__main__')
PY;

    try {
        file_put_contents($receipt.'.active', 'supervised');
        $result = Process::timeout(10)->path($directory)->run([
            'python3', '-c', $code, $supervisor,
            $receipt, '1', '1048576', '4294967296', '1', '/usr/bin/touch', $marker,
        ]);
        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($result->errorOutput())->toContain('aarch64')
            ->and(json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
                'stopped' => true,
                'exit' => 126,
                'reason' => 'unsupported_sandbox_architecture',
            ])
            ->and(file_exists($marker))->toBeFalse()
            ->and(file_exists($receipt.'.active'))->toBeFalse();
    } finally {
        PipelineTemporaryFile::deleteDirectory($directory);
    }
});
