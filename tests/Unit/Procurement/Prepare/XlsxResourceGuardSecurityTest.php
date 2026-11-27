<?php

declare(strict_types=1);

use App\Services\Procurement\Gold\GoldBuilder;
use App\Services\Procurement\Prepare\Tools\TextGridRenderer;
use App\Services\Procurement\Prepare\Tools\XlsxResourceGuard;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Artisan;

test('проверка книги отклоняет пути с потоковыми обёртками', function (string $prefix): void {
    $path = securityTestXlsx('<?xml version="1.0" encoding="UTF-8"?><worksheet/>');

    try {
        $wrappedPath = $prefix.$path;
        $guard = app(XlsxResourceGuard::class);

        expect($guard->accepts(path: $wrappedPath, extension: 'xlsx'))->toBeFalse()
            ->and($guard->accepts(path: $wrappedPath, extension: 'xls'))->toBeFalse()
            ->and(app(TextGridRenderer::class)->render($wrappedPath, 'xlsx'))->toBeFalse();
        expect(fn () => $guard->assertSafeXmlEntries($wrappedPath))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with([
    'file' => ['file://'],
    'phar' => ['phar://'],
]);

test('конвертер PDF отклоняет потоковую обёртку входной книги', function (): void {
    $path = securityTestXlsx('<?xml version="1.0" encoding="UTF-8"?><worksheet/>');
    $output = PipelineTemporaryFile::create(prefix: 'xlsx_security_pdf_', extension: 'pdf');

    try {
        expect(Artisan::call('procurement:convert-xlsx-to-pdf', [
            'input' => 'file://'.$path,
            'output' => $output,
        ]))->toBe(1);
    } finally {
        unlink($path);
        unlink($output);
    }
});

test('сборка gold отклоняет потоковую обёртку входной книги', function (): void {
    $path = securityTestXlsx('<?xml version="1.0" encoding="UTF-8"?><worksheet/>');
    $profile = PipelineTemporaryFile::create(prefix: 'xlsx_security_profile_', extension: 'json');

    try {
        $result = app(GoldBuilder::class)->build('xlsx', 'file://'.$path, $profile);

        expect($result['action'])->toBe('rejected')
            ->and($result['reason'])->toContain('файл эталона не найден');
    } finally {
        unlink($path);
        unlink($profile);
    }
});

test('проверка книги отклоняет запрещённые кодировки XML-пролога', function (string $encoding): void {
    $path = securityTestXlsx("<?xml version=\"1.0\" {$encoding}?><worksheet/>");

    try {
        $guard = app(XlsxResourceGuard::class);

        expect($guard->accepts(path: $path, extension: 'xlsx'))->toBeFalse();
        expect(fn () => $guard->assertSafeXmlEntries($path))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with([
    'utf7' => ['encoding="UTF-7"'],
    'iso2022' => ['encoding="ISO-2022-JP"'],
    'пробелы вокруг знака равенства' => ['encoding = "UTF-7"'],
    'ucs4' => ['encoding="UCS-4"'],
]);

test('проверка книги отклоняет управляющие последовательности обходных кодировок', function (string $payload): void {
    $path = securityTestXlsx("<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet>{$payload}</worksheet>");

    try {
        expect(app(XlsxResourceGuard::class)->accepts(path: $path, extension: 'xlsx'))->toBeFalse();
    } finally {
        unlink($path);
    }
})->with([
    'escape' => ["\x1B"],
    'маркер utf7' => ['+ADw-'],
]);

test('проверка книги отклоняет DOCTYPE и ENTITY внутри листа', function (string $declaration): void {
    $path = securityTestXlsx('<?xml version="1.0" encoding="UTF-8"?>'.$declaration.'<worksheet/>');

    try {
        $guard = app(XlsxResourceGuard::class);

        expect($guard->accepts(path: $path, extension: 'xlsx'))->toBeFalse();
        expect(fn () => $guard->assertSafeXmlEntries($path))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with([
    'doctype' => ['<!DOCTYPE worksheet>'],
    'entity' => ['<!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'],
]);

test('проверка XMLReader отклоняет тип документа даже без текстового сканера', function (): void {
    $path = securityTestXlsx('<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE worksheet><worksheet/>');

    try {
        expect(app(XlsxResourceGuard::class)->accepts(path: $path, extension: 'xlsx', checkXmlEntities: false))->toBeFalse();
    } finally {
        unlink($path);
    }
});

test('проверка книги принимает минимальный XLSX с прологом UTF-8', function (): void {
    $path = securityTestXlsx('<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData/></worksheet>');

    try {
        $guard = app(XlsxResourceGuard::class);

        expect($guard->accepts(path: $path, extension: 'xlsx'))->toBeTrue();
        $guard->assertSafeXmlEntries($path);
    } finally {
        unlink($path);
    }
});

function securityTestXlsx(string $worksheetXml): string
{
    $path = PipelineTemporaryFile::create(prefix: 'xlsx_security_', extension: 'xlsx');
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::OVERWRITE))->toBeTrue();

    foreach ([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $worksheetXml,
    ] as $name => $content) {
        expect($zip->addFromString($name, $content))->toBeTrue();
    }

    $zip->close();

    return $path;
}
