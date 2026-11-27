<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\Tools\XlsxResourceGuard;
use App\Support\Files\PipelineTemporaryFile;

test('xlsx guard отклоняет compressed decoded-byte bomb до PhpSpreadsheet', function (): void {
    config(['ai.prepare.xlsx_max_decoded_mb' => 1, 'ai.prepare.xlsx_max_compression_ratio' => 10_000]);
    $path = hostileXlsx(['xl/worksheets/sheet1.xml' => str_repeat('A', 2 * 1024 * 1024)]);

    try {
        expect(app(XlsxResourceGuard::class)->accepts(path: $path, extension: 'xlsx'))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

test('xlsx guard считает cell nodes потоково до metadata и full load', function (): void {
    config([
        'ai.prepare.xlsx_max_cells' => 3,
        'ai.prepare.xlsx_max_compression_ratio' => 10_000,
    ]);
    $path = hostileXlsx([
        'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c r="A1"/><c r="B1"/><c r="C1"/><c r="D1"/></row></sheetData></worksheet>',
    ]);

    try {
        expect(app(XlsxResourceGuard::class)->accepts(path: $path, extension: 'xlsx'))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

test('xlsx guard не теряет cell tag на границе streaming chunk', function (): void {
    config([
        'ai.prepare.xlsx_max_cells' => 1,
        'ai.prepare.xlsx_max_compression_ratio' => 10_000,
    ]);
    $prefix = '<worksheet><sheetData>';
    $xml = $prefix.str_repeat(' ', 8191 - strlen($prefix))
        .'<c r="A1"/><c r="B1"/></sheetData></worksheet>';
    $path = hostileXlsx(['xl/worksheets/sheet1.xml' => $xml]);

    try {
        expect(strpos($xml, '<c'))->toBe(8191)
            ->and(app(XlsxResourceGuard::class)->accepts(path: $path, extension: 'xlsx'))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

test('xlsx guard отклоняет DOCTYPE и ENTITY объявления в xml и rels записях в UTF-8 и UTF-16', function (string $entryName, string $payload): void {
    $path = hostileXlsx([$entryName => $payload]);

    try {
        $guard = app(XlsxResourceGuard::class);
        expect($guard->accepts(path: $path, extension: 'xlsx'))->toBeFalse();
        expect(fn () => $guard->assertSafeXmlEntries($path))->toThrow(RuntimeException::class);
    } finally {
        @unlink($path);
    }
})->with([
    'utf8 doctype in worksheet' => [
        'xl/worksheets/sheet1.xml',
        '<?xml version="1.0"?><!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><worksheet/>',
    ],
    'utf8 entity in rels' => [
        '_rels/.rels',
        '<?xml version="1.0"?><!ENTITY lol "lol"><Relationships/>',
    ],
    'utf16le doctype in sharedStrings' => [
        'xl/sharedStrings.xml',
        "\xFF\xFE".mb_convert_encoding('<?xml version="1.0"?><!DOCTYPE sst><sst/>', 'UTF-16LE', 'UTF-8'),
    ],
]);

test('проверка XML отклоняет запись больше лимита и принимает запись ровно на границе', function (): void {
    config(['ai.prepare.xlsx_max_decoded_mb' => 1]);
    $limit = 1024 * 1024;
    $maximalXml = '<xml>'.str_repeat(' ', $limit - strlen('<xml></xml>')).'</xml>';
    $valid = hostileXlsx(['content.xml' => $maximalXml]);
    $oversize = hostileXlsx(['content.xml' => $maximalXml."\n"]);

    try {
        $guard = app(XlsxResourceGuard::class);
        $guard->assertSafeXmlEntries($valid);
        expect(fn () => $guard->assertSafeXmlEntries($oversize))->toThrow(RuntimeException::class, 'превышает лимит размера');
    } finally {
        unlink($valid);
        unlink($oversize);
    }
});

test('проверка XML отклоняет повреждённый ZIP-контейнер', function (): void {
    $path = PipelineTemporaryFile::create(prefix: 'broken_zip_', extension: 'xlsx');
    file_put_contents($path, "PK\x03\x04");

    try {
        expect(fn () => app(XlsxResourceGuard::class)->assertSafeXmlEntries($path))
            ->toThrow(RuntimeException::class, 'Не удалось открыть ZIP-контейнер');
    } finally {
        unlink($path);
    }
});

/** @param array<string, string> $entries */
function hostileXlsx(array $entries): string
{
    $path = PipelineTemporaryFile::create(prefix: 'xlsx_guard_', extension: 'xlsx');
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::OVERWRITE))->toBeTrue();

    foreach ($entries as $name => $content) {
        expect($zip->addFromString($name, $content))->toBeTrue();
    }

    $zip->close();

    return $path;
}
