<?php

declare(strict_types=1);

use App\Actions\Procurement\Document\ExpandArchiveEntryAction;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Platforms\Eis\Card\DocumentsParser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\DocumentReferenceProbe;
use Tests\Support\ProcessCore\PreparationStrategyProbe;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

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

test('format corpus: архив создаёт children, каждый проходит обычную подготовку, а parent не скрининг', function () {
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

    (new PreparationStrategyProbe(documentId: $parent->id))->handle();

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

    Queue::assertNothingPushed();
    Queue::assertNothingPushed();

    $childrenByPath = $children->keyBy('archive_entry_path');
    $supported = $childrenByPath['supported/sample.pdf']->document;
    $unsupported = $childrenByPath['unsupported/payload.exe']->document;
    $nested = $childrenByPath['nested/archive.zip']->document;

    (new PreparationStrategyProbe(documentId: $supported->id))->handle();
    (new PreparationStrategyProbe(documentId: $unsupported->id))->handle();
    (new PreparationStrategyProbe(documentId: $nested->id))->handle();

    $grandchild = DocumentVersion::query()
        ->where('parent_version_id', $childrenByPath['nested/archive.zip']->id)
        ->sole();

    expect($supported->refresh()->status)->toBe(DocumentStatus::Prepared)
        ->and($unsupported->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($unsupported->unprocessable_reason)->toBe(UnprocessableReason::UnsupportedFormat)
        ->and($nested->refresh()->status)->toBe(DocumentStatus::Expanded)
        ->and($grandchild->archive_entry_path)->toBe('deep/unsupported.bin');

    (new PreparationStrategyProbe(documentId: $grandchild->document_id))->handle();

    expect($grandchild->document->refresh()->unprocessable_reason)->toBe(UnprocessableReason::UnsupportedFormat)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $parent->procurement_id)
            ->where('event_type', ProcurementEventType::DocumentAdded)
            ->count())->toBe(4);
});

test('format corpus: retry архива сохраняет одну logical child на parent и path', function () {
    Storage::fake('s3');
    Queue::fake();

    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = archivePipelineParent(
        document: $parent,
        content: archivePipelineFixture('sample-with-xlsx.zip'),
        filename: 'sample-with-xlsx.zip',
    );

    (new PreparationStrategyProbe(documentId: $parent->id))->handle();
    $parent->update(['status' => DocumentStatus::Downloaded]);
    (new PreparationStrategyProbe(documentId: $parent->id))->handle();

    expect(DocumentVersion::query()
        ->where('parent_version_id', $parentVersion->id)
        ->where('archive_entry_path', 'estimates/sample.xlsx')
        ->count())->toBe(1)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $parent->procurement_id)
            ->where('event_type', ProcurementEventType::DocumentAdded)
            ->count())->toBe(1);

    Queue::assertNothingPushed();
});

test('format corpus: complete platform reindex не снимает child, созданный ExpandArchiveEntryAction', function () {
    Storage::fake('s3');
    Queue::fake();
    $procurement = Procurement::factory()->create();
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/documents.html'));

    if ($html === false) {
        throw new RuntimeException('Не удалось прочитать fixture documents.html');
    }

    $snapshot = app(DocumentsParser::class)->parse($html);

    app(DocumentReferenceProbe::class)->index(procurement: $procurement, snapshot: $snapshot);

    $parent = Document::query()
        ->where('procurement_id', $procurement->id)
        ->where('external_id', $snapshot->documents[0]->externalId)
        ->firstOrFail();
    $parentVersion = DocumentVersion::factory()->create(['document_id' => $parent->id]);
    $path = tempnam(sys_get_temp_dir(), 'archive-reindex-');

    if ($path === false) {
        throw new RuntimeException('Не удалось создать файл archive child fixture');
    }

    file_put_contents($path, archivePipelineFixture('sample-v1.pdf'));

    try {
        app(ExpandArchiveEntryAction::class)->execute(
            parent: $parent,
            parentVersion: $parentVersion,
            entryPath: 'estimates/sample.pdf',
            localPath: $path,
        );
    } finally {
        unlink($path);
    }

    app(DocumentReferenceProbe::class)->index(procurement: $procurement, snapshot: $snapshot);

    $child = DocumentVersion::query()
        ->where('parent_version_id', $parentVersion->id)
        ->sole()
        ->document;

    expect($parent->refresh()->removed_at)->toBeNull()
        ->and($child?->refresh()->removed_at)->toBeNull();
});

test('format corpus: concurrent archive entry materialization создаёт одну logical child и одно событие', function () {
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
    'documents.disk' => 'archive-concurrency',
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
    ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\StoredDocumentFixtures::reset();
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

test('format corpus: hostile archive завершается без children, events и follow-up jobs', function (Closure $content, UnprocessableReason $reason, array $config = []) {
    Storage::fake('s3');
    Queue::fake();
    config($config);

    $parent = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $parentVersion = archivePipelineParent(document: $parent, content: $content());

    (new PreparationStrategyProbe(documentId: $parent->id))->handle();

    expect($parent->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($parent->unprocessable_reason)->toBe($reason)
        ->and(DocumentVersion::query()->where('parent_version_id', $parentVersion->id)->count())->toBe(0)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $parent->procurement_id)
            ->where('event_type', ProcurementEventType::DocumentAdded)
            ->count())->toBe(0);

    Queue::assertNothingPushed();
})->with('hostileArchive');

test('manual archive concurrent materialization commit-ит одну child version без orphan child при NULL procurement', function (): void {
    $parent = Document::factory()->create(['procurement_id' => null]);
    $version = DocumentVersion::factory()->create(['document_id' => $parent->id]);
    $root = sys_get_temp_dir().'/manual-archive-concurrency-'.uniqid();
    mkdir($root, 0700, true);
    $payload = $root.'/payload.pdf';
    file_put_contents($payload, archivePipelineFixture('sample-v1.pdf'));
    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => archivePipelineConcurrentWorker($parent->id, $version->id, $payload, $root),
            'second' => archivePipelineConcurrentWorker($parent->id, $version->id, $payload, $root),
        ]);
        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and(Document::query()->whereNull('procurement_id')->where('external_id', $version->id.':concurrent/sample.pdf')->count())->toBe(1)
            ->and(DocumentVersion::query()->where('parent_version_id', $version->id)->count())->toBe(1);
    } finally {
        StoredDocumentFixtures::reset();
        File::deleteDirectory($root);
    }
});
