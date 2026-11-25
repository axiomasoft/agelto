<?php

declare(strict_types=1);

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Gsfx\GsfxChapter;
use App\Dto\Procurement\Prepare\Gsfx\GsfxDocument;
use App\Dto\Procurement\Prepare\Gsfx\GsfxPassport;
use App\Dto\Procurement\Prepare\Gsfx\GsfxPosition;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\Archive\ArchiveWorkspaceHelper;
use App\Services\Procurement\Prepare\Gsfx\GsfxReader;
use App\Services\Procurement\Prepare\GsfxStrategy;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use App\Services\Procurement\Prepare\TextGridRenderer;
use App\Services\Procurement\Prepare\WordStrategy;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use Tests\Support\ProcessCore\FakePrepareTools;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

uses(RefreshDatabase::class);

beforeEach(fn () => StoredDocumentFixtures::reset());

afterEach(fn () => FakePrepareTools::cleanup());

/**
 * @return resource
 */
function streamFromString(string $contents)
{
    $stream = fopen('php://temp', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

function buildXlsFile(callable $fill): string
{
    $spreadsheet = new Spreadsheet;
    $fill($spreadsheet);

    $path = PipelineTemporaryFile::create(prefix: 'grid_xls_', extension: 'xls');
    (new XlsWriter($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

test('конфигурация documents унифицирует disk, prepare.disk и download.disk через DOCUMENTS_DISK', function (): void {
    $configSource = (string) file_get_contents(config_path('documents.php'));

    expect(substr_count($configSource, "env('DOCUMENTS_DISK'"))->toBe(3)
        ->and(config('documents.disk'))->toBe('s3')
        ->and(config('documents.prepare.disk'))->toBe(config('documents.disk'))
        ->and(config('documents.download.disk'))->toBe(config('documents.disk'))
        ->and(config('documents.prepare.temporary_disk'))->toBe('local')
        ->and(config('documents.download.temporary_disk'))->toBe('local');
});

test('ArchiveWorkspaceHelper копирует файл потоком и не откатывается на безлимитный get в память при отказе readStream', function (): void {
    $localPath = PipelineTemporaryFile::create(prefix: 'workspace_no_get_', extension: 'zip');
    $budget = new ArchiveBudget(
        maxDepth: 2,
        maxEntries: 10,
        maxEntryBytes: 1024,
        maxTotalBytes: 1024,
        maxCompressionRatio: 10,
        maxPathLength: 240,
    );

    try {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('readStream')->twice()->with('documents/missing.zip')->andReturn(false);
        $disk->shouldNotReceive('get');

        expect(fn () => ArchiveWorkspaceHelper::copyToLocal($disk, 'documents/missing.zip', $localPath))
            ->toThrow(RuntimeException::class, 'Оригинал недоступен на диске')
            ->and(fn () => ArchiveWorkspaceHelper::copyToLocal($disk, 'documents/missing.zip', $localPath, $budget))
            ->toThrow(RuntimeException::class, 'Оригинал архива недоступен на диске');
    } finally {
        @unlink($localPath);
    }
});

test('WordStrategy отклоняет пустой или невалидный вывод LibreOffice без сигнатуры %PDF как conversion_failed', function (string $invalidOutput): void {
    FakePrepareTools::instance()->soffice(succeeds: true, pdfContents: [$invalidOutput]);

    $diskName = (string) config('documents.disk');
    $source = '{\rtf1 тестовый документ}';
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'disk' => $diskName,
        'extension' => 'rtf',
        'storage_path' => 'documents/source.rtf',
        'content_hash' => hash('sha256', $source),
        'size_bytes' => strlen($source),
    ]);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('readStream')
        ->once()
        ->with('documents/source.rtf')
        ->andReturnUsing(static fn () => streamFromString($source));
    $disk->shouldNotReceive('get');
    $disk->shouldNotReceive('writeStream');
    Storage::set($diskName, $disk);

    expect(app(WordStrategy::class)->process($document, $version))->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('conversion_failed')
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(0);
})->with([
    'empty file' => [''],
    'corrupt header' => ['NOT-A-PDF-PAYLOAD'],
]);

test('WordStrategy и SpreadsheetStrategy читают исходник через readStream и записывают валидный %PDF потоком через writeStream', function (): void {
    $validPdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
    FakePrepareTools::instance()->soffice(succeeds: true, pdfContents: [$validPdf, $validPdf]);

    $diskName = (string) config('documents.disk');
    $rtfSource = '{\rtf1 валидный документ}';
    $xlsxSource = (string) file_get_contents(base_path('tests/Fixtures/Documents/sample.xlsx'));

    $wordDoc = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $wordVersion = DocumentVersion::factory()->create([
        'document_id' => $wordDoc->id,
        'disk' => $diskName,
        'extension' => 'rtf',
        'storage_path' => 'documents/valid.rtf',
        'content_hash' => hash('sha256', $rtfSource),
        'size_bytes' => strlen($rtfSource),
    ]);

    $sheetDoc = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $sheetVersion = DocumentVersion::factory()->create([
        'document_id' => $sheetDoc->id,
        'disk' => $diskName,
        'extension' => 'xlsx',
        'storage_path' => 'documents/valid.xlsx',
        'content_hash' => hash('sha256', $xlsxSource),
        'size_bytes' => strlen($xlsxSource),
    ]);

    $realDisk = Storage::disk($diskName);
    $realDisk->put('documents/valid.rtf', $rtfSource);
    $realDisk->put('documents/valid.xlsx', $xlsxSource);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldNotReceive('get');
    $disk->shouldNotReceive('put');
    $disk->shouldReceive('readStream')
        ->atLeast()
        ->times(4)
        ->andReturnUsing(static fn (string $path) => $realDisk->readStream($path));
    $disk->shouldReceive('writeStream')
        ->atLeast()
        ->twice()
        ->with(
            Mockery::type('string'),
            Mockery::on(static fn (mixed $stream): bool => is_resource($stream)),
        )
        ->andReturnUsing(static fn (string $path, mixed $stream): bool => $realDisk->writeStream($path, $stream));
    $disk->shouldReceive('exists')
        ->andReturnUsing(static fn (string $path): bool => $realDisk->exists($path));
    $disk->shouldReceive('delete')
        ->andReturnUsing(static fn (string|array $paths): bool => $realDisk->delete($paths));
    $disk->shouldReceive('files')
        ->andReturnUsing(static fn (?string $directory = null, bool $recursive = false): array => $realDisk->files($directory, $recursive));
    Storage::set($diskName, $disk);

    config(['ai.prepare.xlsx_max_mb' => 0]);

    expect(app(WordStrategy::class)->process($wordDoc, $wordVersion))->toBe(PreparationResult::ReadyForScreening)
        ->and(app(SpreadsheetStrategy::class)->process($sheetDoc, $sheetVersion))->toBe(PreparationResult::ReadyForScreening);

    $wordRep = DocumentRepresentation::query()
        ->where('document_version_id', $wordVersion->id)
        ->where('kind', RepresentationKind::Pdf)
        ->first();
    $sheetRep = DocumentRepresentation::query()
        ->where('document_version_id', $sheetVersion->id)
        ->where('kind', RepresentationKind::Pdf)
        ->first();

    expect($wordRep)->not->toBeNull()
        ->and($wordRep?->size_bytes)->toBe(strlen($validPdf))
        ->and($sheetRep)->not->toBeNull()
        ->and($sheetRep?->size_bytes)->toBe(strlen($validPdf));
});

test('TextGridRenderer рендерит .xls в изолированном subprocess и соблюдает лимиты строк, ячеек и памяти для .xls', function (): void {
    $path = buildXlsFile(function (Spreadsheet $spreadsheet): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Смета XLS');
        $sheet->setCellValue('A1', 'Шапка');
        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A2', 10);
        $sheet->setCellValue('B2', '=A2*3');
    });

    try {
        $renderer = app(TextGridRenderer::class);
        $rendered = $renderer->render($path, 'xls');

        expect($rendered)->not->toBeFalse()
            ->and($rendered['sheets'])->toBe(1)
            ->and($rendered['rows'])->toBe(2)
            ->and($rendered['text'])->toContain('## SHEET: Смета XLS')
            ->and($rendered['text'])->toContain("1\tШапка\tШапка")
            ->and($rendered['text'])->toContain("2\t10\t30");

        // Превышение лимита ячеек на .xls прерывает загрузку и возвращает false.
        config(['ai.prepare.xlsx_max_cells' => 2]);
        expect($renderer->render($path, 'xls'))->toBeFalse();

        // Превышение лимита строк на .xls возвращает false до полной загрузки.
        config(['ai.prepare.xlsx_max_cells' => 2_000_000, 'ai.prepare.xlsx_max_rows' => 1]);
        expect($renderer->render($path, 'xls'))->toBeFalse();

        // Исчерпание memory_limit в изолированном subprocess возвращает false, не роняя вызывающий процесс.
        config(['ai.prepare.xlsx_max_rows' => 20_000, 'ai.prepare.xlsx_convert_memory_limit' => '2M']);
        expect($renderer->render($path, 'xls'))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

test('GsfxStrategy::renderText вычисляет итоговую сумму позиции как произведение цены за единицу на количество', function (): void {
    $document = new GsfxDocument(
        passport: new GsfxPassport(
            loc_num: 'ЛСР-01',
            object_name: 'Капитальный ремонт',
            total: '30000.00',
            calc_method: 'БИМ',
            normative_base: 'ФЕР-2020',
        ),
        chapters: [
            new GsfxChapter(
                caption: 'Раздел 1',
                positions: [
                    new GsfxPosition(
                        name: 'Раствор кладочный',
                        number: '1',
                        code: 'ФСБЦ-01',
                        unit: 'м3',
                        quantity: '4,1514',
                        price_base: '3404,62',
                        price_curr: '6741,15',
                    ),
                    new GsfxPosition(
                        name: 'Кирпич керамический',
                        number: '2',
                        code: 'ФСБЦ-02',
                        unit: '1000 шт',
                        quantity: '2,5',
                        price_base: '120,00',
                        price_curr: null,
                    ),
                    new GsfxPosition(
                        name: 'Работа без прямой цены',
                        number: '3',
                        code: 'ГЭСН-03',
                        unit: 'м2',
                        quantity: '10',
                        price_base: null,
                        price_curr: null,
                    ),
                ],
            ),
        ],
    );

    $strategy = app(GsfxStrategy::class);

    $renderMethod = new ReflectionMethod($strategy, 'renderText');
    $text = (string) $renderMethod->invoke($strategy, $document);

    expect($text)->toContain('1. Раствор кладочный [ФСБЦ-01] — 4,1514 м3 x 6741,15 = 27985.21')
        ->and($text)->not->toContain('x 6741,15 = 6741,15')
        ->and($text)->toContain('2. Кирпич керамический [ФСБЦ-02] — 2,5 1000 шт x 120,00 = 300.00')
        ->and($text)->not->toContain('x 120,00 = 120,00')
        ->and($text)->toContain('3. Работа без прямой цены [ГЭСН-03] — 10 м2 x — = —');
});

test('GsfxReader распознаёт UTF-8 пролог и BOM без повреждения кириллицы и объединяет несколько блоков Resources и Koefficients', function (): void {
    $utf8PropertiesWithBom = "\xEF\xBB\xBF".implode("\r\n", [
        'DocType=sdtSmeta',
        'Attribs.LocNum=ЛСР-UTF8-01',
        'Attribs.ObjName=Объект в UTF-8 без перекодировки из cp1251',
        'Total=50000.00',
    ]);

    $utf8Xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document>
  <Koefficients>
    <K Caption="Документный коэффициент 1" Code="ДК-1" Value_OZ="1,05" Level="1"/>
  </Koefficients>
  <Koefficients>
    <K Caption="Документный коэффициент 2" Code="ДК-2" Value_EM="1,10" Level="2"/>
  </Koefficients>
  <Chapters>
    <Chapter Caption="Раздел UTF-8" SysID="1">
      <Position Caption="Монтаж конструкций" Number="1" Code="ФЕР01-01" Units="т" Quantity="2">
        <Koefficients>
          <K Caption="Коэффициент 1" Code="К-1" Value_OZ="1,15" Level="1"/>
        </Koefficients>
        <Koefficients>
          <K Caption="Коэффициент 2" Code="К-2" Value_MT="1,20" Level="2"/>
        </Koefficients>
        <Resources>
          <Mat Caption="Сталь листовая" Code="01.1" Units="т" Quantity="1,5" IndexCode="10">
            <PriceCurr Value="500,00"/>
          </Mat>
        </Resources>
        <Resources>
          <Mch Caption="Кран башенный" Code="91.1" Units="маш.-ч" Quantity="4" IndexCode="20">
            <PriceCurr Value="250,00"/>
          </Mch>
        </Resources>
      </Position>
    </Chapter>
  </Chapters>
</Document>
XML;

    $reader = new GsfxReader;
    $parsed = $reader->parse($utf8PropertiesWithBom, $utf8Xml);

    expect($parsed->passport->loc_num)->toBe('ЛСР-UTF8-01')
        ->and($parsed->passport->object_name)->toBe('Объект в UTF-8 без перекодировки из cp1251')
        ->and($parsed->coefficients)->toHaveCount(2)
        ->and($parsed->coefficients[0]->caption)->toBe('Документный коэффициент 1')
        ->and($parsed->coefficients[1]->caption)->toBe('Документный коэффициент 2');

    $position = $parsed->chapters[0]->positions[0];
    expect($parsed->chapters[0]->caption)->toBe('Раздел UTF-8')
        ->and($position->name)->toBe('Монтаж конструкций')
        ->and($position->coefficients)->toHaveCount(2)
        ->and($position->coefficients[0]->caption)->toBe('Коэффициент 1')
        ->and($position->coefficients[1]->caption)->toBe('Коэффициент 2')
        ->and($position->resources)->toHaveCount(2)
        ->and($position->resources[0]->name)->toBe('Сталь листовая')
        ->and($position->resources[1]->name)->toBe('Кран башенный')
        ->and($position->indices)->toBe(['10', '20']);

    // Проверка UTF-8 BOM перед XML-прологом с устаревшим атрибутом encoding="windows-1251".
    $bomXml = "\xEF\xBB\xBF".str_replace('encoding="UTF-8"', 'encoding="windows-1251"', $utf8Xml);
    $parsedFromBom = $reader->parse($utf8PropertiesWithBom, $bomXml);
    expect($parsedFromBom->chapters[0]->positions[0]->name)->toBe('Монтаж конструкций')
        ->and($parsedFromBom->chapters[0]->positions[0]->resources)->toHaveCount(2)
        ->and($parsedFromBom->chapters[0]->positions[0]->coefficients)->toHaveCount(2);
});
