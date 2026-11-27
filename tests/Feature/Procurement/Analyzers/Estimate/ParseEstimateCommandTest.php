<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\Tools\PdfPreparationTools;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\ProcessCore\StubPdfTools;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('команда estimate:parse в режиме dry-run компилирует wire-запрос для openai без исходящих HTTP-вызовов', function (): void {
    $xlsxPath = sys_get_temp_dir().'/parse_estimate_'.bin2hex(random_bytes(6)).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($xlsxPath);
    $writer->addRow(Row::fromValues(['№', 'Код', 'Наименование', 'Ед. изм.', 'Кол-во', 'Сумма']));
    $writer->addRow(Row::fromValues(['1', 'ФЕР01-01-001', 'Разработка грунта', '1000 м3', '2', '15000.00']));
    $writer->close();

    try {
        $exitCode = Artisan::call('estimate:parse', [
            'source' => $xlsxPath,
            '--profile' => 'openai',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['mode'])->toBe('dry-run')
            ->and($decoded['provider'])->toBe('openai')
            ->and($decoded['model'])->toBe('gpt-4.1-mini')
            ->and($decoded['endpoint_url'])->toBe('https://api.openai.com/v1/chat/completions')
            ->and($decoded['auth_header_scheme'])->toBe('Authorization: Bearer ***')
            ->and($decoded['window_plan']['window_count'])->toBeGreaterThanOrEqual(1)
            ->and($decoded['budget_upper_bound']['currency'])->toBe('USD');
    } finally {
        @unlink($xlsxPath);
    }
});

test('команда estimate:parse поддерживает gsfx и pdf в режиме dry-run по умолчанию', function (): void {
    $gsfxPath = sys_get_temp_dir().'/parse_estimate_'.bin2hex(random_bytes(6)).'.gsfx';
    $zip = new ZipArchive;
    $zip->open($gsfxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('Properties.txt', "Caption=Тестовая смета\n");
    $zip->addFromString('Data.xml', '<?xml version="1.0" encoding="utf-8"?><Document><Chapters><Chapter Caption="Раздел 1"><Position Number="1" Code="ФЕР01" Caption="Работа" Units="шт" Quantity="1" /></Chapter></Chapters></Document>');
    $zip->close();

    $pdfPath = sys_get_temp_dir().'/parse_estimate_'.bin2hex(random_bytes(6)).'.pdf';
    file_put_contents($pdfPath, "%PDF-1.4\n%stub\n");
    app()->instance(PdfPreparationTools::class, new StubPdfTools(count: 3));

    try {
        $gsfxExit = Artisan::call('estimate:parse', [
            'source' => $gsfxPath,
            '--profile' => 'openai',
            '--json' => true,
        ]);
        $gsfxDecoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($gsfxExit)->toBe(0)
            ->and($gsfxDecoded['format'])->toBe('gsfx')
            ->and($gsfxDecoded['provider'])->toBe('openai');

        $pdfExit = Artisan::call('estimate:parse', [
            'source' => $pdfPath,
            '--profile' => 'openai',
            '--json' => true,
        ]);
        $pdfOutput = Artisan::output();
        expect($pdfExit)->toBe(0, $pdfOutput);
        $pdfDecoded = json_decode($pdfOutput, true, flags: JSON_THROW_ON_ERROR);

        expect($pdfDecoded['format'])->toBe('pdf')
            ->and($pdfDecoded['page_count'])->toBe(3);
    } finally {
        @unlink($gsfxPath);
        @unlink($pdfPath);
    }
});

test('команда estimate:parse в режиме live отклоняет запуск без явного --cap', function (): void {
    $gsfxPath = sys_get_temp_dir().'/parse_estimate_live_'.bin2hex(random_bytes(6)).'.gsfx';
    $zip = new ZipArchive;
    $zip->open($gsfxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('Properties.txt', "Caption=Тестовая смета\n");
    $zip->addFromString('Data.xml', '<?xml version="1.0" encoding="utf-8"?><Document><Chapters><Chapter Caption="Раздел 1"><Position Number="1" Code="ФЕР01" Caption="Работа" /></Chapter></Chapters></Document>');
    $zip->close();

    try {
        $this->artisan('estimate:parse', [
            'source' => $gsfxPath,
            '--profile' => 'openai',
            '--live' => true,
        ])
            ->assertExitCode(1);
    } finally {
        @unlink($gsfxPath);
    }
});
