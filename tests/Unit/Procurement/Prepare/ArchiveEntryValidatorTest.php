<?php

declare(strict_types=1);

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Services\Procurement\Prepare\Archive\ArchiveEntryValidator;
use App\Services\Procurement\Prepare\Archive\ArchivePasswordProtectedException;
use App\Services\Procurement\Prepare\Archive\ArchiveValidationException;

function archiveBudget(): ArchiveBudget
{
    return new ArchiveBudget(maxDepth: 2, maxEntries: 2, maxEntryBytes: 10, maxTotalBytes: 12, maxCompressionRatio: 3, maxPathLength: 20);
}

function archiveEntry(string $path, int $size = 1, int $compressed = 1, string $type = 'file', bool $encrypted = false): ArchiveEntry
{
    return new ArchiveEntry(path: $path, declaredSize: $size, compressedSize: $compressed, isEncrypted: $encrypted, type: $type);
}

test('нормализует допустимый путь', function () {
    $entries = app(ArchiveEntryValidator::class)->validate([archiveEntry('nested/file.txt')], archiveBudget());

    expect($entries[0]->path)->toBe('nested/file.txt');
});

test('отклоняет hostile пути, коллизии и special entry', function (ArchiveEntry $entry) {
    app(ArchiveEntryValidator::class)->validate([$entry], archiveBudget());
})->with([
    'absolute' => [archiveEntry('/file.txt')],
    'drive' => [archiveEntry('C:/file.txt')],
    'backslash' => [archiveEntry('nested\\file.txt')],
    'nul' => [archiveEntry("file\0.txt")],
    'dot dot' => [archiveEntry('../file.txt')],
    'empty segment' => [archiveEntry('nested//file.txt')],
    'long path' => [archiveEntry(str_repeat('a', 21))],
    'symlink' => [archiveEntry('link', type: 'symlink')],
])->throws(ArchiveValidationException::class);

test('отклоняет дубликаты с case-fold коллизией', function () {
    app(ArchiveEntryValidator::class)->validate([archiveEntry('File.txt'), archiveEntry('file.txt')], archiveBudget());
})->throws(ArchiveValidationException::class);

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
