<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\ArchiveStrategy;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\FakePrepareTools;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

afterEach(fn () => FakePrepareTools::cleanup());

/**
 * `ArchiveStrategy` расширена с ZIP до трёх форматов — `7z`/`rar` через реальные
 * `7z`/`unar` (не мокаются, Code Guidance). Свободного RAR-энкодера в образе нет, поэтому RAR
 * happy path не фабрикуется: тест подтверждает реальный runtime adapter и его fail-closed путь.
 */
function build7z(array $files, array $extraArgs = []): string
{
    $workDir = sys_get_temp_dir().'/archive_test_'.Str::uuid();
    mkdir($workDir, recursive: true);

    foreach ($files as $relativePath => $content) {
        $fullPath = $workDir.'/'.$relativePath;
        @mkdir(dirname($fullPath), recursive: true);
        file_put_contents($fullPath, $content);
    }

    $archivePath = sys_get_temp_dir().'/archive_test_'.Str::uuid().'.7z';

    $result = Process::path($workDir)
        ->timeout(30)
        ->run(['7z', 'a', ...$extraArgs, $archivePath, '.']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return $archivePath;
}

function makeArchiveVersion(string $localArchivePath, string $extension): array
{
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $disk = Storage::fake((string) config('documents.disk'));
    $content = file_get_contents($localArchivePath);
    $storagePath = "documents/archive-test.{$extension}";
    $disk->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => $extension,
        'storage_path' => $storagePath,
        'content_hash' => hash('sha256', $storagePath),
        'size_bytes' => strlen($content),
    ]);

    return [$document, $version];
}

function buildZip(array $files): string
{
    $archivePath = sys_get_temp_dir().'/archive_test_'.Str::uuid().'.zip';
    $zip = new ZipArchive;
    expect($zip->open($archivePath, ZipArchive::CREATE))->toBeTrue();

    foreach ($files as $relativePath => $content) {
        expect($zip->addFromString($relativePath, $content))->toBeTrue();
    }

    $zip->close();

    return $archivePath;
}

test('ZIP metadata проходит CHECKCONS и entry извлекается потоком', function () {
    $archive = buildZip(['nested/estimate.txt' => 'содержимое сметы']);
    [$document, $version] = makeArchiveVersion($archive, 'zip');

    $result = app(ArchiveStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ExpandedContainer)
        ->and(Document::query()->where('external_id', "{$version->id}:nested/estimate.txt")->exists())->toBeTrue();
});

test('7z → дочерние документы созданы', function () {
    $archive = build7z(['estimates/sample.txt' => 'содержимое сметы']);
    [$document, $version] = makeArchiveVersion($archive, '7z');

    $result = app(ArchiveStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::ExpandedContainer)
        ->and($document->refresh()->status)->not->toBe(DocumentStatus::Unprocessable);

    $child = Document::query()
        ->where('external_id', "{$version->id}:estimates/sample.txt")
        ->first();

    expect($child)->not->toBeNull();

    $childVersion = DocumentVersion::query()->where('document_id', $child->id)->first();
    expect($childVersion->parent_version_id)->toBe($version->id)
        ->and($childVersion->filename)->toBe('sample.txt');
});

test('7z-архив с path traversal в имени entry — весь архив отклонён до записи children', function () {
    // 7z-CLI сам блокирует запись '../' при упаковке относительным путём — синтетический архив
    // с вредоносным именем entry собирается через ZipArchive-обёртку `7z rn`, переименовывающую
    // entry ПОСЛЕ упаковки (не через файловую систему, где '../' невозможен как имя внутри workDir).
    $archive = build7z(['evil.txt' => 'payload']);

    $rename = Process::timeout(30)->run(['7z', 'rn', $archive, 'evil.txt', '../../../evil.txt']);
    expect($rename->successful())->toBeTrue($rename->errorOutput());

    [$document, $version] = makeArchiveVersion($archive, '7z');

    $result = app(ArchiveStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('zip_bomb')
        ->and(Document::query()->where('external_id', 'like', '%evil.txt')->exists())->toBeFalse();
});

test('7z-листинг сверх лимита числа файлов — unprocessable(zip_bomb) без распаковки', function () {
    config(['ai.prepare.archive.max_files' => 0]);
    $archive = build7z(['a.txt' => 'x']);
    [$document, $version] = makeArchiveVersion($archive, '7z');

    $result = app(ArchiveStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('zip_bomb');
});

test('запароленный 7z (шифрование содержимого) — unprocessable(password_protected)', function () {
    $archive = build7z(['a.txt' => 'секрет'], ['-pSecret123']);
    [$document, $version] = makeArchiveVersion($archive, '7z');

    $result = app(ArchiveStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('password_protected');
});

test('битый 7z — unprocessable(archive_unsupported)', function () {
    $path = tempnam(sys_get_temp_dir(), 'broken_').'.7z';
    file_put_contents($path, 'это не архив вообще');
    [$document, $version] = makeArchiveVersion($path, '7z');

    $result = app(ArchiveStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('archive_unsupported');
});

test('битый rar (не распознан unar) — unprocessable(archive_unsupported)', function () {
    $path = tempnam(sys_get_temp_dir(), 'broken_').'.rar';
    file_put_contents($path, 'это не rar вообще');
    [$document, $version] = makeArchiveVersion($path, 'rar');

    $result = app(ArchiveStrategy::class)->process($document, $version);

    expect($result)->toBe(PreparationResult::Unprocessable)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('archive_unsupported');
});

test('RAR adapter имеет runtime evidence без сфабрикованного happy path', function () {
    $result = Process::timeout(5)->run([(string) config('ai.tools.unar_bin'), '-v']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output().$result->errorOutput())->not->toBe('');
});

test('ArchiveExtractor::listEntries принимает числовые флаги 0 и 1 и директории без XADFileSize в выводе lsar -json', function () {
    FakePrepareTools::instance()->configure(key: 'lsar_bin', code: 'print(json.dumps(data))', data: [
        'lsarFormatVersion' => 2,
        'lsarContents' => [
            [
                'XADFileName' => 'Проект/06.3-41-25-СМ.pdf',
                'XADFileSize' => 2086696,
                'XADCompressedSize' => 1746654,
                'XADIsSolid' => 0,
            ],
            [
                'XADFileName' => 'Проект',
                'XADIsDirectory' => 1,
                'XADCompressedSize' => 0,
            ],
        ],
    ]);
    $archive = PipelineTemporaryFile::create(prefix: 'lsar_fixture_', extension: 'rar');
    try {
        $entries = app(ArchiveExtractor::class)->listEntries(archivePath: $archive, format: 'rar');
    } finally {
        unlink($archive);
    }

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->path)->toBe('Проект/06.3-41-25-СМ.pdf')
        ->and($entries[0]->isDirectory)->toBeFalse()
        ->and($entries[0]->declaredSize)->toBe(2086696)
        ->and($entries[1]->path)->toBe('Проект')
        ->and($entries[1]->isDirectory)->toBeTrue()
        ->and($entries[1]->declaredSize)->toBe(0);
});

test('повторный прогон 7z — дочерний документ не дублируется', function () {
    $archive = build7z(['estimates/sample.txt' => 'содержимое сметы']);
    [$document, $version] = makeArchiveVersion($archive, '7z');

    app(ArchiveStrategy::class)->process($document, $version);
    $countAfterFirst = Document::query()->count();
    app(ArchiveStrategy::class)->process($document, $version);

    expect(Document::query()->count())->toBe($countAfterFirst);
});
