<?php

declare(strict_types=1);

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Services\Procurement\Prepare\Archive\ArchiveEntryValidator;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\Archive\ArchivePasswordProtectedException;
use App\Services\Procurement\Prepare\Archive\ArchiveValidationException;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function archiveBudget(): ArchiveBudget
{
    return new ArchiveBudget(maxDepth: 2, maxEntries: 2, maxEntryBytes: 10, maxTotalBytes: 12, maxCompressionRatio: 3, maxPathLength: 20);
}

function archiveEntry(string $path, int $size = 1, ?int $compressed = 1, string $type = 'file', bool $encrypted = false): ArchiveEntry
{
    return new ArchiveEntry(path: $path, declaredSize: $size, compressedSize: $compressed, isEncrypted: $encrypted, type: $type);
}

test('нормализует допустимый путь', function () {
    $entries = app(ArchiveEntryValidator::class)->validate([
        archiveEntry('nested/file.txt'),
        archiveEntry('win\\file.txt'),
    ], archiveBudget());

    expect($entries[0]->path)->toBe('nested/file.txt')
        ->and($entries[1]->path)->toBe('win/file.txt');
});

test('отклоняет hostile пути, коллизии и special entry', function (ArchiveEntry $entry) {
    app(ArchiveEntryValidator::class)->validate([$entry], archiveBudget());
})->with([
    'absolute' => [archiveEntry('/file.txt')],
    'drive' => [archiveEntry('C:/file.txt')],
    'backslash' => [archiveEntry('\\file.txt')],
    'nul' => [archiveEntry("file\0.txt")],
    'dot dot' => [archiveEntry('../file.txt')],
    'empty segment' => [archiveEntry('nested//file.txt')],
    'long path' => [archiveEntry(str_repeat('a', 21))],
    'symlink' => [archiveEntry('link', type: 'symlink')],
    'command option' => [archiveEntry('-so')],
    'list file' => [archiveEntry('@files.txt')],
    'wildcard star' => [archiveEntry('nested/*.pdf')],
    'wildcard question' => [archiveEntry('file?.pdf')],
    'wildcard brackets' => [archiveEntry('[ab].pdf')],
    'metadata newline' => [archiveEntry("file\nSize = 0")],
    'terminal escape' => [archiveEntry("file\x1b.pdf")],
])->throws(ArchiveValidationException::class);

test('отклоняет дубликаты с case-fold коллизией', function () {
    app(ArchiveEntryValidator::class)->validate([archiveEntry('File.txt'), archiveEntry('file.txt')], archiveBudget());
})->throws(ArchiveValidationException::class);

test('отклоняет дубликаты с Unicode NFC и NFD коллизией, сохраняя исходный NFD путь для одиночной записи', function (): void {
    $nfcPath = "отч\u{0451}т.txt";
    $nfdPath = "отч\u{0435}\u{0308}т.txt";

    expect($nfcPath)->not->toBe($nfdPath);

    $single = app(ArchiveEntryValidator::class)->validate([archiveEntry($nfdPath)], archiveBudget());
    expect($single)->toHaveCount(1)
        ->and($single[0]->path)->toBe($nfdPath);

    expect(fn () => app(ArchiveEntryValidator::class)->validate([
        archiveEntry($nfcPath),
        archiveEntry($nfdPath),
    ], archiveBudget()))->toThrow(ArchiveValidationException::class);
});

test('7z с зашифрованными заголовками отклоняется неинтерактивно как ArchivePasswordProtectedException', function (): void {
    $workspace = PipelineTemporaryFile::createDirectory(prefix: 'archive_mhe_');

    try {
        file_put_contents($workspace.'/secret.txt', 'secret-payload');
        $archive = $workspace.'/encrypted-headers.7z';
        $binary = (string) config('ai.tools.sevenzip_bin');
        $result = Process::path($workspace)->run([
            $binary,
            'a',
            '-t7z',
            '-mhe=on',
            '-pSecret123',
            $archive,
            'secret.txt',
        ]);

        expect($result->successful())->toBeTrue($result->errorOutput());

        expect(fn () => app(ArchiveExtractor::class)->listEntries(
            archivePath: $archive,
            format: '7z',
        ))->toThrow(ArchivePasswordProtectedException::class);
    } finally {
        File::deleteDirectory($workspace);
    }
});

test('отклоняет encryption', function () {
    app(ArchiveEntryValidator::class)->validate([archiveEntry('file', encrypted: true)], archiveBudget());
})->throws(ArchivePasswordProtectedException::class);

test('отклоняет невозможные resource metadata', function (ArchiveEntry $entry) {
    app(ArchiveEntryValidator::class)->validate([$entry], archiveBudget());
})->with([
    'entry bytes' => [archiveEntry('file', size: 11)],
    'zero compressed' => [archiveEntry('file', size: 1, compressed: 0)],
    'ratio' => [archiveEntry('file', size: 4, compressed: 1)],
])->throws(ArchiveValidationException::class);

test('просроченный archive deadline не запускает новый внешний процесс', function (): void {
    Process::fake();

    expect(fn () => app(ArchiveExtractor::class)->listEntries(
        archivePath: '/tmp/not-read.7z',
        format: '7z',
        deadlineAt: microtime(true) - 1,
    ))->toThrow(ArchiveValidationException::class);

    Process::assertNothingRan();
});

test('solid 7z member без собственного Packed Size не считается zero-byte metadata', function (): void {
    $validator = app(ArchiveEntryValidator::class);
    $entries = [
        archiveEntry('first.txt', size: 4, compressed: 2),
        archiveEntry('second.txt', size: 4, compressed: null),
    ];
    $budget = archiveBudget();
    $validated = $validator->validate($entries, $budget, archiveSizeBytes: 3);

    expect($validated)->toHaveCount(2)
        ->and($validated[1]->compressedSize)->toBeNull()
        ->and(fn () => $validator->validate($entries, $budget))->toThrow(ArchiveValidationException::class, 'физический размер архива')
        ->and(fn () => $validator->validate($entries, $budget, archiveSizeBytes: 2))->toThrow(ArchiveValidationException::class, 'суммарный коэффициент сжатия');
});

test('реальные solid 7z, non-solid 7z и zip проходят listing, validation и bounded extraction', function (string $format, array $options): void {
    $workspace = PipelineTemporaryFile::createDirectory(prefix: 'archive_contract_');

    try {
        file_put_contents($workspace.'/first.bin', random_bytes(4096));
        file_put_contents($workspace.'/second.bin', random_bytes(4096));
        $archive = $workspace."/sample.{$format}";
        $binary = (string) config('ai.tools.sevenzip_bin');
        $result = Process::path($workspace)->run([
            $binary,
            'a',
            "-t{$format}",
            ...$options,
            $archive,
            'first.bin',
            'second.bin',
        ]);

        expect($result->successful())->toBeTrue($result->errorOutput());

        $extractor = app(ArchiveExtractor::class);
        $budget = new ArchiveBudget(
            maxDepth: 2,
            maxEntries: 10,
            maxEntryBytes: 8192,
            maxTotalBytes: 16384,
            maxCompressionRatio: 100,
            maxPathLength: 100,
        );
        $entries = app(ArchiveEntryValidator::class)->validate(
            $extractor->listEntries(archivePath: $archive, format: $format),
            $budget,
            archiveSizeBytes: filesize($archive),
        );
        $files = array_values(array_filter($entries, fn (ArchiveEntry $entry): bool => ! $entry->isDirectory));
        $totalBytes = 0;

        foreach ($files as $index => $entry) {
            $extractor->extractEntry(
                archivePath: $archive,
                format: $format,
                entry: $entry,
                targetPath: $workspace."/out-{$index}.bin",
                budget: $budget,
                totalBytes: $totalBytes,
            );
        }

        expect($files)->toHaveCount(2)
            ->and($totalBytes)->toBe(8192);
    } finally {
        File::deleteDirectory($workspace);
    }
})->with([
    'solid 7z' => ['7z', ['-ms=on']],
    'non-solid 7z' => ['7z', ['-ms=off']],
    'zip' => ['zip', []],
]);
