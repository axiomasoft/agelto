<?php

declare(strict_types=1);

use App\Actions\Procurement\Document\ExpandArchiveEntryAction;
use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Prepare\Archive\ArchiveEntryPath;
use App\Services\Procurement\Prepare\Archive\ArchiveEntryValidator;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\Archive\ArchiveValidationException;
use App\Services\Procurement\Prepare\ArchiveStrategy;
use App\Services\Procurement\Prepare\RepresentationWriter;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\FakePrepareTools;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

afterEach(fn () => FakePrepareTools::cleanup());

/**
 * Создаёт ZIP-архив с точными байтами имён записей (например, CP866 или Windows-1251 без флага UTF-8).
 *
 * @param  array<string, string>  $rawEntries
 */
function buildRawEncodedZip(array $rawEntries): string
{
    $archivePath = PipelineTemporaryFile::create(prefix: 'archive_raw_', extension: 'zip');
    $zip = new ZipArchive;
    expect($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

    foreach ($rawEntries as $rawName => $data) {
        expect($zip->addFromString($rawName, $data))->toBeTrue();
    }

    expect($zip->close())->toBeTrue();

    return $archivePath;
}

test('запись архива размером 100 МиБ сохраняется потоком с определением MIME и проверкой SHA-256', function (): void {
    $diskName = (string) config('documents.disk');
    $disk = Storage::fake($diskName);

    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = DocumentVersion::factory()->create(['document_id' => $parent->id]);

    $localFile = PipelineTemporaryFile::create(prefix: 'entry_stream_', extension: 'pdf');
    $pdfPrefix = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
    $sizeBytes = 100 * 1024 * 1024;

    try {
        $file = fopen($localFile, 'wb');
        expect($file)->toBeResource();
        try {
            expect(fwrite($file, $pdfPrefix))->toBe(strlen($pdfPrefix))
                ->and(ftruncate($file, $sizeBytes))->toBeTrue();
        } finally {
            fclose($file);
        }

        $expectedHash = hash_file('sha256', $localFile);
        expect($expectedHash)->toBeString();
        memory_reset_peak_usage();
        $initialMemory = memory_get_usage();
        $childVersion = app(ExpandArchiveEntryAction::class)->execute(
            parent: $parent,
            parentVersion: $parentVersion,
            entryPath: 'Сметы/ЛСР_01.pdf',
            localPath: $localFile,
            childOrdinal: 1,
        );
        $extraMemory = memory_get_peak_usage() - $initialMemory;

        $reservation = DB::table('prc_storage_reservations')->where('id', $childVersion->blob_id)->sole();
        expect($childVersion->content_hash)->toBe($expectedHash)
            ->and($childVersion->size_bytes)->toBe($sizeBytes)
            ->and($childVersion->mime)->toContain('pdf')
            ->and($childVersion->storage_path)->toBe($reservation->object_key)
            ->and($reservation->status)->toBe('transferred')
            ->and(hash_file('sha256', $disk->path($childVersion->storage_path)))->toBe($expectedHash)
            ->and($extraMemory)->toBeLessThan(32 * 1024 * 1024);
    } finally {
        @unlink($localFile);
        if (isset($childVersion)) {
            $disk->delete($childVersion->storage_path);
        }
    }
});

test('ExpandArchiveEntryAction отклоняет загрузку при расхождении реального read-back после put', function (): void {
    $diskName = (string) config('documents.disk');
    $baseDisk = Storage::fake($diskName);

    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = DocumentVersion::factory()->create(['document_id' => $parent->id]);

    $localFile = PipelineTemporaryFile::create(prefix: 'entry_corrupt_', extension: 'txt');
    $payload = 'оригинальное содержимое сметы для проверки read-back';
    file_put_contents($localFile, $payload);
    $mockDisk = Mockery::mock($baseDisk)->makePartial();
    $mockDisk->shouldReceive('put')
        ->once()
        ->with(Mockery::on(fn (string $key): bool => str_starts_with($key, 'documents/uploads/')), Mockery::type('resource'))
        ->andReturnUsing(function (string $path) use ($baseDisk, $payload): bool {
            $baseDisk->getDriver()->write($path, str_repeat('x', strlen($payload)));

            return true;
        });
    Storage::set($diskName, $mockDisk);

    try {
        expect(fn () => app(ExpandArchiveEntryAction::class)->execute(
            parent: $parent,
            parentVersion: $parentVersion,
            entryPath: 'estimates/corrupt.txt',
            localPath: $localFile,
        ))->toThrow(RuntimeException::class, 'Object storage вернул неполную или другую запись архива');

        $reservation = DB::table('prc_storage_reservations')->sole();
        expect(DocumentVersion::query()->where('parent_version_id', $parentVersion->id)->count())->toBe(0)
            ->and($reservation->status)->toBe('cleaned')
            ->and($baseDisk->exists($reservation->object_key))->toBeFalse();
    } finally {
        @unlink($localFile);
    }
});

test('сверка освобождает резерв дочерней записи после SIGKILL до коммита или во время одиночного PUT', function (bool $duringPut): void {
    $diskName = 'archive-crash';
    $disk = Storage::fake($diskName);
    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = DocumentVersion::factory()->create(['document_id' => $parent->id]);
    $localFile = PipelineTemporaryFile::create(prefix: 'archive_crash_', extension: 'pdf');
    try {
        file_put_contents($localFile, '%PDF-1.4');
        if ($duringPut) {
            $stream = fopen($localFile, 'r+b');
            try {
                // Above the AWS SDK default multipart threshold.
                expect(ftruncate($stream, 17 * 1024 * 1024))->toBeTrue();
            } finally {
                fclose($stream);
            }
        }
        $worker = strtr(<<<'PHP'
            $localConfig = ['driver' => 'local', 'root' => __ROOT__, 'throw' => true];
            $kill = static function (): never {
                fwrite(STDERR, 'archive import interrupted at the expected boundary');
                posix_kill(getmypid(), SIGKILL);
                throw new RuntimeException('Fixture worker survived SIGKILL.');
            };
            $config = $localConfig;
            if (__DURING_PUT__) {
                $mirror = \Illuminate\Support\Facades\Storage::build($localConfig);
                $config = [
                    'driver' => 's3', 'bucket' => 'archive-crash', 'region' => 'us-east-1',
                    'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
                    'http' => ['timeout' => 15], 'retries' => 2, 'throw' => true,
                    'http_handler' => static function (\Psr\Http\Message\RequestInterface $request, array $options) use ($mirror, $kill): never {
                        if ($request->getMethod() !== 'PUT' || $request->getUri()->getQuery() !== '' || $options['timeout'] !== 15) {
                            throw new RuntimeException('Expected one bounded PutObject request.');
                        }
                        $key = ltrim($request->getUri()->getPath(), '/');
                        if (! str_starts_with($key, 'documents/uploads/') || ! $mirror->put($key, 'accepted before PHP stopped')) {
                            throw new RuntimeException('Fixture could not persist the object accepted by S3.');
                        }
                        $kill();
                    },
                ];
            } else {
                \Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function (string $query) use ($kill): void {
                    if (str_starts_with(strtolower(ltrim($query)), 'insert into prc_document_blobs')) {
                        $kill();
                    }
                });
            }
            config(['documents.disk' => 'archive-crash', 'filesystems.disks.archive-crash' => $config]);
            app(\App\Actions\Procurement\Document\ExpandArchiveEntryAction::class)->execute(
                parent: \App\Models\Procurement\Document\Document::query()->findOrFail(__PARENT__),
                parentVersion: \App\Models\Procurement\Document\DocumentVersion::query()->findOrFail(__VERSION__),
                entryPath: 'documents/child.pdf',
                localPath: __FILE_PATH__,
            );
            return [];
            PHP, [
            '__ROOT__' => var_export($disk->path(''), true),
            '__PARENT__' => var_export($parent->id, true),
            '__VERSION__' => var_export($parentVersion->id, true),
            '__FILE_PATH__' => var_export($localFile, true),
            '__DURING_PUT__' => var_export($duringPut, true),
        ]);
        expect(fn () => ConcurrentTransactions::execute($worker))
            ->toThrow(RuntimeException::class, 'PostgreSQL worker failed: archive import interrupted at the expected boundary');

        $reservation = DB::table('prc_storage_reservations')->sole();
        $status = $duringPut ? 'writing' : 'staged';
        expect($reservation->status)->toBe($status)
            ->and($disk->exists($reservation->object_key))->toBeTrue()
            ->and(DocumentVersion::query()->where('parent_version_id', $parentVersion->id)->exists())->toBeFalse();
        if ($duringPut) {
            $remoteDisk = Mockery::mock($disk)->makePartial();
            $remoteDisk->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
            Storage::set($diskName, $remoteDisk);
            config(['filesystems.disks.archive-crash' => ['driver' => 's3', 'http' => ['timeout' => 15]]]);
        }

        $reconciler = app(DocumentStorageReconciler::class);
        expect($reconciler->reconcile())->toBe(0);
        if ($duringPut) {
            DB::update("UPDATE prc_storage_reservations SET created_at=clock_timestamp()-interval '130 seconds' WHERE id=?", [$reservation->id]);
            expect($reconciler->reconcile())->toBe(0)
                ->and($disk->exists($reservation->object_key))->toBeTrue();
        }
        DB::update("UPDATE prc_storage_reservations SET created_at=clock_timestamp()-interval '3 minutes' WHERE id=?", [$reservation->id]);
        expect($reconciler->reconcile())->toBe(1)
            ->and($disk->exists($reservation->object_key))->toBeFalse()
            ->and(DB::table('prc_storage_reservations')->where('id', $reservation->id)->value('status'))->toBe('cleaned')
            ->and($reconciler->reconcile())->toBe(0);
    } finally {
        @unlink($localFile);
    }
})->with(['до коммита' => false, 'во время записи S3' => true]);

test('архив ЕИС с кириллическими именами в CP866 и Windows-1251 и обратными слэшами Windows распаковывается без ложного ZipBomb', function (): void {
    $diskName = (string) config('documents.disk');
    $disk = Storage::fake($diskName);

    $cp866Path = iconv('UTF-8', 'CP866', 'Сметы\\Раздел 1\\Локальная смета №1.txt');
    $win1251Path = iconv('UTF-8', 'Windows-1251', 'Документация\\Пояснительная записка.txt');
    expect($cp866Path)->toBeString()
        ->and($win1251Path)->toBeString();

    $archivePath = buildRawEncodedZip([
        $cp866Path => 'содержимое локальной сметы из CP866',
        $win1251Path => 'содержимое пояснительной записки из Windows-1251',
    ]);

    try {
        $archiveBytes = (string) file_get_contents($archivePath);
        $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
        $storagePath = 'documents/eis-cp866-archive.zip';
        $disk->put($storagePath, $archiveBytes);

        $version = DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'extension' => 'zip',
            'disk' => $diskName,
            'storage_path' => $storagePath,
            'content_hash' => hash('sha256', $archiveBytes),
            'size_bytes' => strlen($archiveBytes),
        ]);

        $result = app(ArchiveStrategy::class)->process($document, $version);

        expect($result)->toBe(PreparationResult::ExpandedContainer)
            ->and($document->refresh()->status)->toBe(DocumentStatus::Expanded);

        $children = DocumentVersion::query()
            ->where('parent_version_id', $version->id)
            ->orderBy('archive_entry_path')
            ->get();

        expect($children)->toHaveCount(2)
            ->and($children[0]->archive_entry_path)->toBe('Документация/Пояснительная записка.txt')
            ->and($children[0]->filename)->toBe('Пояснительная записка.txt')
            ->and($disk->get($children[0]->storage_path))->toBe('содержимое пояснительной записки из Windows-1251')
            ->and($children[1]->archive_entry_path)->toBe('Сметы/Раздел 1/Локальная смета №1.txt')
            ->and($children[1]->filename)->toBe('Локальная смета №1.txt')
            ->and($disk->get($children[1]->storage_path))->toBe('содержимое локальной сметы из CP866');
    } finally {
        @unlink($archivePath);
    }
});

test('ArchiveEntryPath нормализует Windows backslash в slash и отклоняет traversal, ведущий слэш и диски Windows', function (): void {
    expect(ArchiveEntryPath::normalize('Сметы\\Том 1\\Смета.xlsx', 240))
        ->toBe('Сметы/Том 1/Смета.xlsx')
        ->and(ArchiveEntryPath::normalize('Сметы\\Том 1\\', 240))
        ->toBe('Сметы/Том 1');

    foreach (['\\root.txt', '\\\\server\\share\\file.txt', 'C:\\evil.txt', 'Сметы\\..\\evil.txt', '..\\evil.txt', 'Сметы\\\\пустой.txt', "Сметы\\nul\0.txt"] as $hostile) {
        expect(fn () => ArchiveEntryPath::normalize($hostile, 240))
            ->toThrow(ArchiveValidationException::class);
    }
});

test('ArchiveExtractor передаёт разделитель опций -- в lsar и unar и использует настроенный lsar_bin', function (): void {
    FakePrepareTools::instance()->configure(
        key: 'lsar_bin',
        code: "assert '--' in sys.argv, 'missing -- in lsar args'\nprint(json.dumps(data))",
        data: [
            'lsarFormatVersion' => 2,
            'lsarContents' => [
                [
                    'XADFileName' => 'Сметы\\ЛСР.txt',
                    'XADFileSize' => 7,
                    'XADCompressedSize' => 7,
                ],
            ],
        ],
    );
    FakePrepareTools::instance()->configure(
        key: 'unar_bin',
        code: "assert '--' in sys.argv, 'missing -- in unar args'\nsys.stdout.write('1234567')",
    );

    $archive = PipelineTemporaryFile::create(prefix: 'rar_dash_', extension: 'rar');
    $target = PipelineTemporaryFile::create(prefix: 'rar_out_', extension: 'txt');
    unlink($target);

    try {
        $extractor = app(ArchiveExtractor::class);
        $budget = ArchiveBudget::fromConfig();
        $entries = app(ArchiveEntryValidator::class)->validate(
            entries: $extractor->listEntries(archivePath: $archive, format: 'rar'),
            budget: $budget,
            archiveSizeBytes: 64,
        );

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->path)->toBe('Сметы/ЛСР.txt');

        $totalBytes = 0;
        $extractor->extractEntry(
            archivePath: $archive,
            format: 'rar',
            entry: $entries[0],
            targetPath: $target,
            budget: $budget,
            totalBytes: $totalBytes,
        );

        expect($totalBytes)->toBe(7)
            ->and(file_get_contents($target))->toBe('1234567');
    } finally {
        @unlink($archive);
        @unlink($target);
    }
});

test('расхождение фактического размера записи не оставляет выходной файл и не расходует общий бюджет', function (string $payload): void {
    FakePrepareTools::instance()->configure(
        key: 'unar_bin',
        code: 'sys.stdout.write(data["payload"])',
        data: ['payload' => $payload],
    );
    $archive = PipelineTemporaryFile::create(prefix: 'rar_size_', extension: 'rar');
    $directory = PipelineTemporaryFile::createDirectory(prefix: 'rar_output_');
    $target = "{$directory}/entry.txt";
    $totalBytes = 2;

    try {
        expect(function () use ($archive, $target, &$totalBytes): void {
            app(ArchiveExtractor::class)->extractEntry(
                archivePath: $archive,
                format: 'rar',
                entry: new ArchiveEntry(path: 'entry.txt', declaredSize: 7, compressedSize: 7),
                targetPath: $target,
                budget: ArchiveBudget::fromConfig(),
                totalBytes: $totalBytes,
            );
        })->toThrow(ArchiveValidationException::class);

        expect(file_exists($target))->toBeFalse()
            ->and($totalBytes)->toBe(2);
    } finally {
        @unlink($archive);
        File::deleteDirectory($directory);
    }
})->with([
    'лишние байты' => ['12345678'],
    'неполная запись' => ['123456'],
]);

test('вывод распаковщика копируется на диск без накопления байтов в памяти PHP', function (): void {
    $sizeMiB = 40;
    $sizeBytes = $sizeMiB * 1024 * 1024;
    FakePrepareTools::instance()->configure(
        key: 'unar_bin',
        code: <<<'PY'
chunk = b'x' * (1024 * 1024)
for _ in range(data['size_mib']):
    sys.stdout.buffer.write(chunk)
PY,
        data: ['size_mib' => $sizeMiB],
    );
    $archive = PipelineTemporaryFile::create(prefix: 'rar_stream_', extension: 'rar');
    $directory = PipelineTemporaryFile::createDirectory(prefix: 'rar_stream_output_');
    $target = "{$directory}/entry.bin";

    try {
        $extractor = app(ArchiveExtractor::class);
        $entry = new ArchiveEntry(path: 'entry.bin', declaredSize: $sizeBytes, compressedSize: $sizeBytes);
        $budget = ArchiveBudget::fromConfig();
        $totalBytes = 0;
        memory_reset_peak_usage();
        $initialMemory = memory_get_usage();
        $extractor->extractEntry(
            archivePath: $archive,
            format: 'rar',
            entry: $entry,
            targetPath: $target,
            budget: $budget,
            totalBytes: $totalBytes,
        );
        $extraMemory = memory_get_peak_usage() - $initialMemory;

        expect($totalBytes)->toBe($sizeBytes)
            ->and(filesize($target))->toBe($sizeBytes)
            ->and(file_get_contents(filename: $target, offset: $sizeBytes - 32, length: 32))->toBe(str_repeat('x', 32))
            ->and($extraMemory)->toBeLessThan(16 * 1024 * 1024);
    } finally {
        @unlink($archive);
        File::deleteDirectory($directory);
    }
});

test('solid 7z с превышением коэффициента сжатия относительно физического размера архива и записи Hard Link отклоняются', function (): void {
    $diskName = (string) config('documents.disk');
    $disk = Storage::fake($diskName);
    $workDir = PipelineTemporaryFile::createDirectory(prefix: 'solid_7z_bomb_');

    try {
        // Первый файл маленький, второй — высокосжимаемый блок в том же solid-блоке (у второго Packed Size = null).
        file_put_contents($workDir.'/01_header.txt', 'header');
        file_put_contents($workDir.'/02_zeroes.bin', str_repeat("\0", 256 * 1024));
        $archivePath = $workDir.'/solid-bomb.7z';

        $result = Process::path($workDir)->timeout(30)->run([
            (string) config('ai.tools.sevenzip_bin'),
            'a',
            '-t7z',
            '-ms=on',
            $archivePath,
            '01_header.txt',
            '02_zeroes.bin',
        ]);
        expect($result->successful())->toBeTrue($result->errorOutput());

        $archiveSize = filesize($archivePath);
        expect($archiveSize)->toBeInt()->toBeGreaterThan(0);

        $extractor = app(ArchiveExtractor::class);
        $listed = $extractor->listEntries(archivePath: $archivePath, format: '7z');
        expect($listed)->toHaveCount(2)
            ->and($listed[1]->compressedSize)->toBeNull();

        $strictBudget = new ArchiveBudget(
            maxDepth: 2,
            maxEntries: 10,
            maxEntryBytes: 1024 * 1024,
            maxTotalBytes: 2 * 1024 * 1024,
            maxCompressionRatio: 5,
            maxPathLength: 240,
        );

        // Даже если все записи имеют compressedSize === null, физический размер архива ловит превышение ratio.
        $nullPackedEntries = [
            new ArchiveEntry(path: '01_header.txt', declaredSize: 6, compressedSize: null),
            new ArchiveEntry(path: '02_zeroes.bin', declaredSize: 256 * 1024, compressedSize: null),
        ];
        expect(fn () => app(ArchiveEntryValidator::class)->validate(
            entries: $nullPackedEntries,
            budget: $strictBudget,
            archiveSizeBytes: $archiveSize,
        ))->toThrow(ArchiveValidationException::class);

        // Полный прогон через ArchiveStrategy передаёт filesize($localArchive) и отклоняет solid 7z как ZipBomb.
        config(['ai.prepare.archive.max_ratio' => 5]);
        $archiveBytes = (string) file_get_contents($archivePath);
        $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
        $storagePath = 'documents/solid-bomb.7z';
        $disk->put($storagePath, $archiveBytes);
        $version = DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'extension' => '7z',
            'disk' => $diskName,
            'storage_path' => $storagePath,
            'content_hash' => hash('sha256', $archiveBytes),
            'size_bytes' => strlen($archiveBytes),
        ]);

        expect(app(ArchiveStrategy::class)->process($document, $version))
            ->toBe(PreparationResult::Unprocessable)
            ->and($document->refresh()->unprocessable_reason)->toBe(UnprocessableReason::ZipBomb);

        // Hard Link в выводе 7z l -slt распознаётся и отклоняется валидатором как special entry.
        FakePrepareTools::instance()->configure(
            key: 'sevenzip_bin',
            code: 'print(data["output"])',
            data: ['output' => implode("\n", [
                'Path = archive.7z', 'Type = 7z', '----------',
                'Path = original.txt', 'Size = 10', 'Packed Size = 10', 'Attributes = A', 'Encrypted = -', '',
                'Path = hardlink.txt', 'Size = 0', 'Packed Size = 0', 'Hard Link = original.txt', '',
                'Path = hardlink-empty.txt', 'Size = 0', 'Packed Size = 0', 'Hard Link = ',
            ])],
        );
        $hardLinkEntries = $extractor->listEntries(archivePath: $archivePath, format: '7z');
        expect($hardLinkEntries)->toHaveCount(3);

        foreach (array_slice($hardLinkEntries, 1) as $hardLink) {
            expect($hardLink->type)->toBe('hardlink')
                ->and(fn () => app(ArchiveEntryValidator::class)->validate(
                    entries: [$hardLink],
                    budget: $strictBudget,
                    archiveSizeBytes: $archiveSize,
                ))->toThrow(ArchiveValidationException::class, 'special entry');
        }
    } finally {
        File::deleteDirectory($workDir);
    }
});

test('солидный архив отклоняется по одному блоку при допустимом общем коэффициенте сжатия', function (): void {
    $workDir = PipelineTemporaryFile::createDirectory(prefix: 'solid_7z_blocks_');
    try {
        $files = [
            '01_header.bin' => 'header',
            '02_zeroes.bin' => str_repeat("\0", 64 * 1024),
            '03_data.bin' => random_bytes(64 * 1024),
            '04_data.bin' => random_bytes(64 * 1024),
        ];
        foreach ($files as $name => $content) {
            file_put_contents("{$workDir}/{$name}", $content);
        }
        $archivePath = "{$workDir}/solid.7z";
        $result = Process::path($workDir)->timeout(30)->run([
            config()->string('ai.tools.sevenzip_bin'), 'a', '-t7z', '-ms=2f', $archivePath, ...array_keys($files),
        ]);
        expect($result->successful())->toBeTrue($result->errorOutput());

        $entries = app(ArchiveExtractor::class)->listEntries(archivePath: $archivePath, format: '7z');
        $byPath = array_column($entries, null, 'path');
        $first = $byPath['01_header.bin'];
        $second = $byPath['02_zeroes.bin'];
        $third = $byPath['03_data.bin'];
        $archiveSize = filesize($archivePath);
        $maxRatio = 5;
        expect($first->compressionBlock)->toBeInt()->toBe($second->compressionBlock)
            ->and($third->compressionBlock)->not->toBe($first->compressionBlock)
            ->and($first->compressedSize)->toBeGreaterThan(0)
            ->and($second->compressedSize)->toBeNull()
            ->and($first->declaredSize / $first->compressedSize)->toBeLessThan($maxRatio)
            ->and(array_sum(array_column($entries, 'declaredSize')) / $archiveSize)->toBeLessThan($maxRatio);

        $budget = new ArchiveBudget(
            maxDepth: 2, maxEntries: 10, maxEntryBytes: 1024 * 1024, maxTotalBytes: 1024 * 1024,
            maxCompressionRatio: $maxRatio, maxPathLength: 240,
        );
        expect(fn () => app(ArchiveEntryValidator::class)->validate($entries, $budget, archiveSizeBytes: $archiveSize))
            ->toThrow(ArchiveValidationException::class, 'коэффициент сжатия solid-блока 7z');
    } finally {
        File::deleteDirectory($workDir);
    }
});

test('сумма Packed Size не может превысить физический размер архива в расчёте коэффициента', function (): void {
    $budget = new ArchiveBudget(maxDepth: 2, maxEntries: 2, maxEntryBytes: 10, maxTotalBytes: 10, maxCompressionRatio: 5, maxPathLength: 30);
    $entries = [
        new ArchiveEntry(path: 'first.txt', declaredSize: 4, compressedSize: 2),
        new ArchiveEntry(path: 'second.txt', declaredSize: 4, compressedSize: 2),
    ];
    $validator = app(ArchiveEntryValidator::class);

    expect($validator->validate(entries: $entries, budget: $budget, archiveSizeBytes: 4))->toHaveCount(2)
        ->and(fn () => $validator->validate(entries: $entries, budget: $budget, archiveSizeBytes: 1))
        ->toThrow(ArchiveValidationException::class, 'превышен суммарный коэффициент сжатия архива');
});

test('повторная запись представления удаляет старые staging-объекты и сохраняет свежие записи и соседние файлы', function (): void {
    $version = DocumentVersion::factory()->create();
    $disk = Storage::fake('representation-orphan-cleanup');
    $content = '%PDF-1.4 cleaned representation';
    $path = 'documents/ab/sample.prepared.pdf';
    $contentHash = hash('sha256', $content);
    $otherHash = hash('sha256', 'previous crashed attempt bytes');
    $storagePath = "documents/ab/sample.prepared.{$contentHash}.pdf";

    $orphanSameHash = "{$storagePath}.staging.11111111-1111-4111-8111-111111111111";
    $orphanOtherHash = "documents/ab/sample.prepared.{$otherHash}.pdf.staging.22222222-2222-4222-8222-222222222222";
    $activeStaging = "{$storagePath}.staging.33333333-3333-4333-8333-333333333333";
    $unrelatedFile = 'documents/ab/sample.prepared.other.pdf.staging.44444444-4444-4444-8444-444444444444';

    $disk->put($orphanSameHash, 'crashed partial 1');
    $disk->put($orphanOtherHash, 'crashed partial 2');
    $disk->put($activeStaging, 'active bytes');
    $disk->put($unrelatedFile, 'keep me');
    $stale = now()->getTimestamp() - config()->integer('documents.temporary_stale_seconds') - 1;
    foreach ([$orphanSameHash, $orphanOtherHash, $unrelatedFile] as $oldFile) {
        expect(touch($disk->path($oldFile), $stale))->toBeTrue();
    }

    $representation = app(RepresentationWriter::class)->write(
        disk: $disk,
        version: $version,
        kind: RepresentationKind::Pdf,
        path: $path,
        content: $content,
        attributes: [
            'storage_path' => $path,
            'disk' => 'representation-orphan-cleanup',
            'size_bytes' => strlen($content),
            'mime' => 'application/pdf',
            'meta' => ['tool' => 'libreoffice', 'tool_version' => '1'],
            'generated_at' => now(),
        ],
    );

    expect($representation->storage_path)->toBe($storagePath)
        ->and($disk->exists($orphanSameHash))->toBeFalse()
        ->and($disk->exists($orphanOtherHash))->toBeFalse()
        ->and($disk->get($activeStaging))->toBe('active bytes')
        ->and($disk->get($unrelatedFile))->toBe('keep me')
        ->and($disk->get($storagePath))->toBe($content)
        ->and($disk->allFiles())->toEqualCanonicalizing([$storagePath, $activeStaging, $unrelatedFile]);
});

test('старая временная запись удаляется до новой загрузки, даже если соседний объект уже исчез', function (): void {
    $version = DocumentVersion::factory()->create();
    $disk = Storage::fake('representation-retry-cleanup');
    $path = 'documents/ab/sample.pdf';
    $disappeared = "{$path}.staging.11111111-1111-4111-8111-111111111111";
    $orphan = "{$path}.staging.22222222-2222-4222-8222-222222222222";
    $disk->put($disappeared, 'first interrupted write');
    $disk->put($orphan, 'second interrupted write');
    $stale = now()->getTimestamp() - config()->integer('documents.temporary_stale_seconds') - 1;
    expect(touch($disk->path($orphan), $stale))->toBeTrue();

    $mock = Mockery::mock($disk)->makePartial();
    $mock->shouldReceive('lastModified')->once()->with($disappeared)->andReturnUsing(function () use ($disk, $disappeared): never {
        $disk->delete($disappeared);

        throw UnableToRetrieveMetadata::lastModified($disappeared);
    });
    $mock->shouldReceive('writeStream')->once()->with(Mockery::pattern('/\.staging\./'), Mockery::type('resource'))->andReturnFalse();

    expect(fn () => app(RepresentationWriter::class)->write(
        disk: $mock,
        version: $version,
        kind: RepresentationKind::Pdf,
        path: $path,
        content: '%PDF-1.4 retry fixture',
        attributes: [],
    ))->toThrow(RuntimeException::class, 'Не удалось сохранить staging representation');

    expect($disk->exists($orphan))->toBeFalse();
});
