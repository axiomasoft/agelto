<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\Tools\LibreOfficeConverter;
use App\Support\Files\PipelineTemporaryFile;
use Tests\Support\ProcessCore\FakePrepareTools;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(function (): void {
    StoredDocumentFixtures::reset();
    $this->outputDir = PipelineTemporaryFile::createDirectory(prefix: 'lo_test_');
    $this->inputPath = $this->outputDir.'/source.docx';
    file_put_contents($this->inputPath, 'test input');
});

afterEach(function (): void {
    FakePrepareTools::cleanup();
    PipelineTemporaryFile::deleteDirectory($this->outputDir);
});

test('состав аргументов несёт headless, изолированный профиль и путь на выход', function () {
    $tools = FakePrepareTools::instance();
    $tools->soffice();

    app(LibreOfficeConverter::class)->toPdf(inputPath: $this->inputPath, outputDir: $this->outputDir);

    $args = $tools->calls('soffice_bin')[0];
    $workspace = $args[array_search('--outdir', $args, true) + 1];

    expect($args)->toContain('--headless', '--norestore', '--convert-to', 'pdf', $this->inputPath, "-env:UserInstallation=file://{$workspace}/profile")
        ->and($workspace)->not->toBe($this->outputDir)
        ->and(is_dir($workspace))->toBeFalse();
});

test('успешная конвертация возвращает путь к PDF в outputDir', function () {
    FakePrepareTools::instance()->soffice();

    $result = app(LibreOfficeConverter::class)->toPdf(inputPath: $this->inputPath, outputDir: $this->outputDir);

    expect($result)->toBe($this->outputDir.'/source.pdf')
        ->and(file_get_contents($result))->toBe('%PDF-fake');
});

test('ненулевой exit — RuntimeException с errorOutput инструмента в сообщении', function () {
    FakePrepareTools::instance()->soffice(succeeds: false);

    app(LibreOfficeConverter::class)->toPdf(inputPath: $this->inputPath, outputDir: $this->outputDir);
})->throws(RuntimeException::class, 'soffice: crashed on convert');

test('нулевой exit, но выходной файл не появился — тоже RuntimeException', function () {
    FakePrepareTools::instance()->soffice(pdfContents: []);

    app(LibreOfficeConverter::class)->toPdf(inputPath: $this->inputPath, outputDir: $this->outputDir);
})->throws(RuntimeException::class, 'Не удалось получить выходной файл');

test('два одновременных вызова конвертера получают разные пути профиля', function () {
    $tools = FakePrepareTools::instance();
    $tools->soffice();
    $secondInput = $this->outputDir.'/second.docx';
    file_put_contents($secondInput, 'second test input');

    app(LibreOfficeConverter::class)->toPdf(inputPath: $this->inputPath, outputDir: $this->outputDir);
    app(LibreOfficeConverter::class)->toPdf(inputPath: $secondInput, outputDir: $this->outputDir);

    $profiles = array_map(
        fn (array $args): string => collect($args)->first(fn (string $arg): bool => str_starts_with($arg, '-env:UserInstallation=')),
        $tools->calls('soffice_bin'),
    );
    expect($profiles)->toHaveCount(2)->and($profiles[0])->not->toBe($profiles[1]);
});

test('version() возвращает первую строку вывода --version', function () {
    FakePrepareTools::instance()->soffice();

    expect(app(LibreOfficeConverter::class)->version())->toBe('LibreOffice 7.6.4.1 30(Build:4)');
});

test('version() возвращает null, если бинарь не отвечает', function () {
    config(['ai.tools.soffice_bin' => $this->outputDir.'/missing-soffice']);

    expect(app(LibreOfficeConverter::class)->version())->toBeNull();
});
