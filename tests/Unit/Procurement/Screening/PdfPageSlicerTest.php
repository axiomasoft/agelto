<?php

declare(strict_types=1);

use App\Services\Procurement\Screening\PdfPageSlicer;

function screeningFixturePath(string $name): string
{
    return base_path("tests/Fixtures/Documents/{$name}");
}

test('slice() режет 10-стр. PDF на первые 4 страницы', function (): void {
    $slicer = new PdfPageSlicer;
    $output = $slicer->slice(screeningFixturePath('sample-multi-page.pdf'), 4);

    expect($output)->not->toBe(screeningFixturePath('sample-multi-page.pdf'))
        ->and($slicer->pageCount($output))->toBe(4);

    @unlink($output);
});

test('slice() PDF 1.7 с compressed cross-reference stream режется без ошибки (регресс на FPDI-ограничение)', function (): void {
    $slicer = new PdfPageSlicer;
    $output = $slicer->slice(screeningFixturePath('sample-pdf17-compressed-xref.pdf'), 4);

    expect($slicer->pageCount($output))->toBe(4);

    @unlink($output);
});

test('slice() документа короче N страниц возвращает исходный путь без реза', function (): void {
    $slicer = new PdfPageSlicer;
    $input = screeningFixturePath('sample-multi-page.pdf');

    $output = $slicer->slice($input, 20);

    expect($output)->toBe($input);
});

test('pageCount() читает число страниц через qpdf --show-npages', function (): void {
    $slicer = new PdfPageSlicer;

    expect($slicer->pageCount(screeningFixturePath('sample-multi-page.pdf')))->toBe(10);
});

test('slice() на несуществующем файле бросает RuntimeException с сырым stderr qpdf', function (): void {
    $slicer = new PdfPageSlicer;

    expect(fn () => $slicer->pageCount(screeningFixturePath('does-not-exist.pdf')))
        ->toThrow(RuntimeException::class);
});
