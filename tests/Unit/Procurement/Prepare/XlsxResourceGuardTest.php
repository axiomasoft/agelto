<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\XlsxResourceGuard;
use App\Support\Files\PipelineTemporaryFile;
use ZipArchive;

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
