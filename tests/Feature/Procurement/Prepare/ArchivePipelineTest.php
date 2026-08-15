<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\ProcurementEventType;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\ProcurementEvent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use ZipArchive;

function archivePipelineFixture(string $name): string
{
    $content = file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));

    if ($content === false) {
        throw new RuntimeException("Не удалось прочитать fixture {$name}");
    }

    return $content;
}

/** @param array<string, string> $entries */
function archivePipelineZip(array $entries, ?string $encryptedEntry = null, bool $symlink = false): string
{
    $path = tempnam(sys_get_temp_dir(), 'archive-pipeline-');
    if ($path === false) {
        throw new RuntimeException('Не удалось создать ZIP fixture');
    }

    $zip = new ZipArchive;
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось открыть ZIP fixture');
    }

    foreach ($entries as $entryPath => $content) {
        if (! $zip->addFromString($entryPath, $content)) {
            throw new RuntimeException("Не удалось добавить ZIP entry {$entryPath}");
        }
    }

    if ($encryptedEntry !== null && ! $zip->setEncryptionName($encryptedEntry, ZipArchive::EM_TRAD_PKWARE, 'archive-password')) {
        throw new RuntimeException("Не удалось зашифровать ZIP entry {$encryptedEntry}");
    }

    if ($symlink && ! $zip->setExternalAttributesName('linked.pdf', ZipArchive::OPSYS_UNIX, 0120777 << 16)) {
        throw new RuntimeException('Не удалось пометить ZIP entry как symbolic link');
    }

    if (! $zip->close()) {
        throw new RuntimeException('Не удалось закрыть ZIP fixture');
    }

    $content = file_get_contents($path);
    unlink($path);

    if ($content === false) {
        throw new RuntimeException('Не удалось прочитать ZIP fixture');
    }

    return $content;
}

function archivePipelineParent(Document $document, string $content, string $filename = 'archive.zip'): DocumentVersion
{
    $hash = hash(algo: 'sha256', data: $content);
    $path = "documents/{$hash}";

    Storage::disk('s3')->put($path, $content);

    return DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => $filename,
        'extension' => 'zip',
        'mime' => 'application/zip',
        'storage_path' => $path,
        'downloaded_at' => now(),
    ]);
}

test('архив создаёт children, каждый проходит обычную подготовку, а parent не скрининг', function () {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.screening.enabled' => true]);

    $nestedArchive = archivePipelineZip(['deep/unsupported.bin' => 'nested unsupported']);
    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = archivePipelineParent(
        document: $parent,
        content: archivePipelineZip([
            'supported/sample.pdf' => archivePipelineFixture('sample-v1.pdf'),
            'unsupported/payload.exe' => 'not an executable',
            'nested/archive.zip' => $nestedArchive,
        ]),
    );

    (new PrepareDocumentJob(documentId: $parent->id))->handle();

    $children = DocumentVersion::query()
        ->where('parent_version_id', $parentVersion->id)
        ->orderBy('archive_entry_path')
        ->get();

    expect($parent->refresh()->status)->toBe(DocumentStatus::Expanded)
        ->and($children)->toHaveCount(3)
        ->and($children->pluck('archive_entry_path')->all())->toBe([
            'nested/archive.zip',
            'supported/sample.pdf',
            'unsupported/payload.exe',
        ])
        ->and($children->every(fn (DocumentVersion $version): bool => $version->disk === 's3'))->toBeTrue();

    Queue::assertPushed(PrepareDocumentJob::class, 3);
    Queue::assertNotPushed(ScreeningJob::class, fn (ScreeningJob $job): bool => $job->documentId === $parent->id);

    $childrenByPath = $children->keyBy('archive_entry_path');
    $supported = $childrenByPath['supported/sample.pdf']->document;
    $unsupported = $childrenByPath['unsupported/payload.exe']->document;
    $nested = $childrenByPath['nested/archive.zip']->document;

    (new PrepareDocumentJob(documentId: $supported->id))->handle();
    (new PrepareDocumentJob(documentId: $unsupported->id))->handle();
    (new PrepareDocumentJob(documentId: $nested->id))->handle();

    $grandchild = DocumentVersion::query()
        ->where('parent_version_id', $childrenByPath['nested/archive.zip']->id)
        ->sole();

    expect($supported->refresh()->status)->toBe(DocumentStatus::Prepared)
        ->and($unsupported->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($unsupported->unprocessable_reason)->toBe(UnprocessableReason::UnsupportedFormat)
        ->and($nested->refresh()->status)->toBe(DocumentStatus::Expanded)
        ->and($grandchild->archive_entry_path)->toBe('deep/unsupported.bin');

    (new PrepareDocumentJob(documentId: $grandchild->document_id))->handle();

    expect($grandchild->document->refresh()->unprocessable_reason)->toBe(UnprocessableReason::UnsupportedFormat)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $parent->procurement_id)
            ->where('event_type', ProcurementEventType::DocumentAdded)
            ->count())->toBe(4);
});

test('retry архива сохраняет одну logical child на parent и path', function () {
    Storage::fake('s3');
    Queue::fake();

    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = archivePipelineParent(
        document: $parent,
        content: archivePipelineFixture('sample-with-xlsx.zip'),
        filename: 'sample-with-xlsx.zip',
    );

    (new PrepareDocumentJob(documentId: $parent->id))->handle();
    $parent->update(['status' => DocumentStatus::Downloaded]);
    (new PrepareDocumentJob(documentId: $parent->id))->handle();

    expect(DocumentVersion::query()
        ->where('parent_version_id', $parentVersion->id)
        ->where('archive_entry_path', 'estimates/sample.xlsx')
        ->count())->toBe(1)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $parent->procurement_id)
            ->where('event_type', ProcurementEventType::DocumentAdded)
            ->count())->toBe(1);

    Queue::assertPushed(PrepareDocumentJob::class, 1);
});

test('concurrent archive entry materialization создаёт одну logical child и одно событие', function () {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$platform = \App\Models\Procurement\Platform\Platform::factory()->create(attributes: [
    'code' => 'archive-pipeline-concurrency',
]);
$registry = \App\Models\Procurement\Platform\Registry::factory()
    ->for($platform)
    ->create(attributes: ['code' => 'archive-pipeline-concurrency']);
$procurement = \App\Models\Procurement\Procurement::factory()->create(attributes: [
    'registry_id' => $registry->id,
]);
$parent = \App\Models\Procurement\Document\Document::factory()->create(attributes: [
    'procurement_id' => $procurement->id,
    'platform_id' => $platform->id,
]);
$parentVersion = \App\Models\Procurement\Document\DocumentVersion::factory()->create(attributes: [
    'document_id' => $parent->id,
]);

return [
    'parent_id' => $parent->id,
    'parent_version_id' => $parentVersion->id,
];
PHP);
    $root = sys_get_temp_dir().'/archive-concurrency-'.uniqid();
    $payloadPath = "{$root}/payload.pdf";
    mkdir($root, recursive: true);
    file_put_contents($payloadPath, archivePipelineFixture('sample-v1.pdf'));

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => archivePipelineConcurrentWorker(
                parentId: $fixture['parent_id'],
                parentVersionId: $fixture['parent_version_id'],
                payloadPath: $payloadPath,
                diskRoot: $root,
            ),
            'second' => archivePipelineConcurrentWorker(
                parentId: $fixture['parent_id'],
                parentVersionId: $fixture['parent_version_id'],
                payloadPath: $payloadPath,
                diskRoot: $root,
            ),
        ]);
        $state = archivePipelineConcurrentState(
            parentId: $fixture['parent_id'],
            parentVersionId: $fixture['parent_version_id'],
        );

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($state['child_count'])->toBe(1)
            ->and($state['version_count'])->toBe(1)
            ->and($state['event_count'])->toBe(1);
    } finally {
        archivePipelineConcurrentCleanup(
            parentId: $fixture['parent_id'],
            parentVersionId: $fixture['parent_version_id'],
        );
        File::deleteDirectory($root);
    }
});

function archivePipelineConcurrentWorker(int $parentId, int $parentVersionId, string $payloadPath, string $diskRoot): string
{
    $parentId = var_export($parentId, true);
    $parentVersionId = var_export($parentVersionId, true);
    $payloadPath = var_export($payloadPath, true);
    $diskRoot = var_export($diskRoot, true);

    return <<<PHP
config([
    'crawler.documents_disk' => 'archive-concurrency',
    'filesystems.disks.archive-concurrency' => [
        'driver' => 'local',
        'root' => {$diskRoot},
        'throw' => true,
    ],
    'queue.default' => 'sync',
]);

\$parent = \App\Models\Procurement\Document\Document::query()->findOrFail({$parentId});
\$parentVersion = \App\Models\Procurement\Document\DocumentVersion::query()->findOrFail({$parentVersionId});
\$version = app(\App\Actions\Procurement\Document\ExpandArchiveEntryAction::class)->execute(
    parent: \$parent,
    parentVersion: \$parentVersion,
    entryPath: 'concurrent/sample.pdf',
    localPath: {$payloadPath},
);

return [
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
    'version_id' => \$version->id,
];
PHP;
}

/** @return array{child_count: int, version_count: int, event_count: int} */
function archivePipelineConcurrentState(int $parentId, int $parentVersionId): array
{
    return ConcurrentTransactions::execute(<<<PHP
\$versions = \App\Models\Procurement\Document\DocumentVersion::query()
    ->where('parent_version_id', {$parentVersionId})
    ->where('archive_entry_path', 'concurrent/sample.pdf')
    ->get();

return [
    'child_count' => \$versions->pluck('document_id')->unique()->count(),
    'version_count' => \$versions->count(),
    'event_count' => \App\Models\Procurement\ProcurementEvent::query()
        ->where('procurement_id', \App\Models\Procurement\Document\Document::query()->findOrFail({$parentId})->procurement_id)
        ->where('event_type', \App\Enums\Procurement\ProcurementEventType::DocumentAdded)
        ->count(),
];
PHP);
}

function archivePipelineConcurrentCleanup(int $parentId, int $parentVersionId): void
{
    ConcurrentTransactions::execute(<<<PHP
\$versions = \App\Models\Procurement\Document\DocumentVersion::query()
    ->where('parent_version_id', {$parentVersionId})
    ->get();
\$childIds = \$versions->pluck('document_id')->all();
\$parent = \App\Models\Procurement\Document\Document::query()->findOrFail({$parentId});
\$procurement = \App\Models\Procurement\Procurement::query()->findOrFail(\$parent->procurement_id);
\$registryId = \$procurement->registry_id;
\$platformId = \$parent->platform_id;

\App\Models\Procurement\ProcurementEvent::query()
    ->where('procurement_id', \$parent->procurement_id)
    ->where('event_type', \App\Enums\Procurement\ProcurementEventType::DocumentAdded)
    ->delete();
\App\Models\Procurement\Document\DocumentVersion::query()->whereIn('id', \$versions->pluck('id'))->delete();
\App\Models\Procurement\Document\Document::query()->whereIn('id', \$childIds)->delete();
\App\Models\Procurement\Document\DocumentVersion::query()->whereKey({$parentVersionId})->delete();
\$parent->delete();
\$procurement->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey(\$registryId)->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey(\$platformId)->delete();

return ['cleaned' => true];
PHP);
}

dataset('hostileArchive', [
    'path traversal' => [
        fn (): string => archivePipelineZip(['../escape.pdf' => archivePipelineFixture('sample-v1.pdf')]),
        UnprocessableReason::ZipBomb,
    ],
    'symbolic link' => [
        fn (): string => archivePipelineZip(['linked.pdf' => 'target'], symlink: true),
        UnprocessableReason::ZipBomb,
    ],
    'entry count bomb' => [
        fn (): string => archivePipelineZip(['safe.pdf' => archivePipelineFixture('sample-v1.pdf')]),
        UnprocessableReason::ZipBomb,
        ['ai.prepare.archive.max_files' => 0],
    ],
    'password protected' => [
        fn (): string => archivePipelineZip(['secret.pdf' => archivePipelineFixture('sample-v1.pdf')], encryptedEntry: 'secret.pdf'),
        UnprocessableReason::PasswordProtected,
    ],
    'corrupt bytes' => [
        fn (): string => 'not a ZIP archive',
        UnprocessableReason::FormatMismatch,
    ],
]);

test('hostile archive завершается без children, events и follow-up jobs', function (Closure $content, UnprocessableReason $reason, array $config = []) {
    Storage::fake('s3');
    Queue::fake();
    config($config);

    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = archivePipelineParent(document: $parent, content: $content());

    (new PrepareDocumentJob(documentId: $parent->id))->handle();

    expect($parent->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($parent->unprocessable_reason)->toBe($reason)
        ->and(DocumentVersion::query()->where('parent_version_id', $parentVersion->id)->count())->toBe(0)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $parent->procurement_id)
            ->where('event_type', ProcurementEventType::DocumentAdded)
            ->count())->toBe(0);

    Queue::assertNothingPushed();
})->with('hostileArchive');
