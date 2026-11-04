<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\LibreOfficeConverter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

/**
 * Обёртка над `soffice --headless --convert-to pdf` — реальный `soffice` в юнит-тестах не
 * вызывается (Code Guidance), выход подставляется вручную по конвенции именования LibreOffice
 * (`<имя входного файла без расширения>.pdf` в `$outputDir`).
 */
function tempOutputDir(): string
{
    $dir = sys_get_temp_dir().'/lo_test_'.Str::uuid();
    File::ensureDirectoryExists($dir);

    return $dir;
}

function placeFakePdf(string $inputPath, string $outputDir): void
{
    file_put_contents($outputDir.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf', '%PDF-fake');
}

test('состав аргументов несёт headless, изолированный профиль и путь на выход', function () {
    Process::fake();
    $outputDir = tempOutputDir();
    $inputPath = tempnam(sys_get_temp_dir(), 'doc_').'.docx';
    placeFakePdf($inputPath, $outputDir);

    app(LibreOfficeConverter::class)->toPdf($inputPath, $outputDir);

    Process::assertRan(function ($process) use ($outputDir, $inputPath) {
        $command = $process->command;

        return $command[0] === config('ai.tools.soffice_bin')
            && in_array('--headless', $command, true)
            && in_array('--norestore', $command, true)
            && collect($command)->contains(fn ($arg) => is_string($arg) && str_starts_with($arg, '-env:UserInstallation=file://'))
            && in_array('--convert-to', $command, true)
            && in_array('pdf', $command, true)
            && in_array('--outdir', $command, true)
            && in_array($outputDir, $command, true)
            && in_array($inputPath, $command, true);
    });
});

test('успешная конвертация возвращает путь к PDF в outputDir', function () {
    Process::fake();
    $outputDir = tempOutputDir();
    $inputPath = tempnam(sys_get_temp_dir(), 'doc_').'.docx';
    placeFakePdf($inputPath, $outputDir);

    $result = app(LibreOfficeConverter::class)->toPdf($inputPath, $outputDir);

    expect($result)->toBe($outputDir.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf')
        ->and(file_exists($result))->toBeTrue();
});

test('ненулевой exit — RuntimeException с errorOutput инструмента в сообщении', function () {
    Process::fake([
        '*' => Process::result(errorOutput: 'soffice: crashed on convert', exitCode: 1),
    ]);
    $outputDir = tempOutputDir();
    $inputPath = tempnam(sys_get_temp_dir(), 'doc_').'.docx';

    app(LibreOfficeConverter::class)->toPdf($inputPath, $outputDir);
})->throws(RuntimeException::class, 'soffice: crashed on convert');

test('нулевой exit, но выходной файл не появился — тоже RuntimeException', function () {
    Process::fake();
    $outputDir = tempOutputDir();
    $inputPath = tempnam(sys_get_temp_dir(), 'doc_').'.docx';
    // Файл сознательно НЕ создаётся — как будто soffice отрапортовал успех, но PDF не написал.

    app(LibreOfficeConverter::class)->toPdf($inputPath, $outputDir);
})->throws(RuntimeException::class);

test('два одновременных вызова конвертера получают разные пути профиля', function () {
    $profiles = [];
    Process::fake(function ($process) use (&$profiles) {
        $profiles[] = collect($process->command)->first(fn ($arg) => is_string($arg) && str_starts_with($arg, '-env:UserInstallation='));

        return Process::result();
    });

    $outputDir = tempOutputDir();
    $input1 = tempnam(sys_get_temp_dir(), 'doc_').'.docx';
    $input2 = tempnam(sys_get_temp_dir(), 'doc_').'.docx';
    placeFakePdf($input1, $outputDir);
    placeFakePdf($input2, $outputDir);

    app(LibreOfficeConverter::class)->toPdf($input1, $outputDir);
    app(LibreOfficeConverter::class)->toPdf($input2, $outputDir);

    expect($profiles)->toHaveCount(2)
        ->and($profiles[0])->not->toBe($profiles[1]);
});

test('version() возвращает первую строку вывода --version', function () {
    Process::fake([
        '*' => Process::result(output: "LibreOffice 7.6.4.1 30(Build:4)\nBuild ID: abc123"),
    ]);

    expect(app(LibreOfficeConverter::class)->version())->toBe('LibreOffice 7.6.4.1 30(Build:4)');
});

test('version() возвращает null, если бинарь не отвечает', function () {
    Process::fake([
        '*' => Process::result(errorOutput: 'not found', exitCode: 127),
    ]);

    expect(app(LibreOfficeConverter::class)->version())->toBeNull();
});
