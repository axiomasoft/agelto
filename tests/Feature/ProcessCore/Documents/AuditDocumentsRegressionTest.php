<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DownloadResult;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\DownloadFinalizer;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\BlobStore;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Document\StoredDocumentWriter;
use App\Services\Procurement\Prepare\BoundedToolProcess;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\StoredDocumentFixtures;
use Tests\Support\ProcessCore\ToolContext;

pest()->group('document-processing', 'document-storage-and-references');

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $this->artisan('process:sync-documents')->assertSuccessful();
});

test('DownloadFinalizer переводит активный manual-retry download intent в terminal, когда input ссылается на исходный intentId', function (): void {
    FinalizeFixtures::authority([StageCode::Download]);
    $document = Document::factory()->create();
    $domain = DocumentStorageFixtures::domain();

    $initialIntent = LedgerFixtures::uuid();
    $retryIntent = LedgerFixtures::uuid();
    $input = LedgerFixtures::downloadInput($document->id, $initialIntent);

    $work = app(ProcessTransaction::class)->run(function () use ($document, $retryIntent, $input) {
        app(WorkStoreRepository::class)->storeIntent(
            id: $retryIntent,
            intentKey: LedgerFixtures::hash('dl-retry:'.$retryIntent->value),
            stage: StageCode::Download,
            subject: new SubjectKey(SubjectKind::Document, $document->id),
            semanticPolicyHash: LedgerFixtures::hash('sem'),
            accessPolicyHash: $input->accessPolicyHash,
            cycleSeq: 2,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        );

        return LedgerFixtures::storeWork(input: $input, intentId: $retryIntent);
    });
    DB::table('prc_process_work')->where('id', $work->value->value)->update([
        'work_identity' => json_encode([
            'stage' => 'download',
            'input' => ['acquisition_intent_id' => $initialIntent->value],
            'manual_retry' => ['intent_id' => $retryIntent->value],
        ], JSON_THROW_ON_ERROR),
    ]);
    $lease = FinalizeFixtures::running($work);

    $bytes = "%PDF-1.4\nmanual-retry\n%%EOF";
    $reservation = new StorageReservationRequest(
        id: LedgerFixtures::uuid(),
        domainId: $domain,
        ownerToken: $lease->token,
        generation: $lease->generation,
        byteUpperBound: strlen($bytes),
        phase: StoragePhase::Download,
        disk: 'local',
        workId: $work->value,
        attemptId: $lease->attemptId,
    );
    app(ProcessTransaction::class)->run(function () use ($reservation): void {
        $capacity = app(StorageCapacityRepository::class);
        $capacity->lockDomains([$reservation->domainId]);
        $capacity->reserveLocked($reservation);
    });
    $tmp = DocumentStorageFixtures::reservation(DocumentStorageFixtures::domain(prefix: 'tmp'), strlen($bytes), 'p31_tmp');
    $stream = fopen('php://memory', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, $bytes);
    rewind($stream);
    $artifact = app(BlobStore::class)->put($reservation, $stream, $tmp);
    fclose($stream);

    $result = new DownloadResult(
        head: new DocumentHead(documentId: $document->id, generation: 1, versionId: null),
        artifact: $artifact,
        metadata: new SourceDocumentMetadata('retry.pdf', 'application/pdf'),
    );

    app(ProcessTransaction::class)->run(function () use ($domain, $input, $result): void {
        app(StorageCapacityRepository::class)->lockDomains([$domain]);
        app(DownloadFinalizer::class)->storeResult($input, $result);
    });

    expect(DB::table('prc_operation_intents')->where('id', $retryIntent->value)->value('state'))->toBe('terminal')
        ->and(DB::table('prc_documents')->where('id', $document->id)->value('current_version_id'))->not->toBeNull();
});

test('DocumentStorageReconciler удаляет unreferenced blobs старше grace-окна, а DocumentFeeder пропускает удалённые документы', function (): void {
    $domain = DocumentStorageFixtures::domain();
    $uploaded = DocumentStorageFixtures::upload(bytes: 'unreferenced-bytes', domain: $domain);
    $blob = app(ProcessTransaction::class)->run(function () use ($domain, $uploaded) {
        app(StorageCapacityRepository::class)->lockDomains([$domain]);

        return app(ArtifactRepository::class)->acquireLocked($uploaded);
    });
    DB::table('prc_document_blobs')->where('id', $blob->id->value)->update([
        'verified_at' => DB::raw("clock_timestamp() - interval '20 minutes'"),
    ]);

    expect(app(ArtifactRepository::class)->unreferencedCandidates(900, 25))->toHaveCount(1);
    app(DocumentStorageReconciler::class)->reconcile();

    expect(DB::table('prc_document_blobs')->where('id', $blob->id->value)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($blob->objectKey))->toBeFalse();

    // Документ с removed_at или без current_version_id не должен порождать prepare intent в DocumentFeeder.
    $removed = Document::factory()->create(['removed_at' => now()]);
    $version = DocumentVersion::factory()->create(['document_id' => $removed->id]);
    DB::table('prc_documents')->where('id', $removed->id)->update(['current_version_id' => $version->id]);

    expect(app(DocumentFeeder::class)->reconcile())->toBe(0);
});

test('BoundedToolProcess и tool_supervisor очищают секреты окружения, убивают дочерние процессы в новой сессии и ловят быстрый tmp_limit с причиной в ToolFailed', function (): void {
    $dir = storage_path('app/testing/tool-audit-'.bin2hex(random_bytes(6)));
    mkdir($dir, 0700, true);
    $prevSecret = getenv('AWS_SECRET_ACCESS_KEY_FILE');
    putenv('AWS_SECRET_ACCESS_KEY_FILE=/run/secrets/aws_test_secret');

    try {
        $tool = new BoundedToolProcess;
        $envJson = $tool->run(
            ['python3', '-c', 'import json, os; print(json.dumps({"aws": os.environ.get("AWS_SECRET_ACCESS_KEY_FILE"), "app_key": os.environ.get("APP_KEY"), "omp": os.environ.get("OMP_THREAD_LIMIT")}))'],
            $dir,
            ToolContext::make(),
            hrtime(true) / 1e9 + 10,
        );
        $env = json_decode(trim($envJson), true, flags: JSON_THROW_ON_ERROR);
        expect($env['aws'])->toBeNull()
            ->and($env['app_key'])->toBeNull()
            ->and($env['omp'])->toBe('1');

        // Быстрый процесс (<20мс) создаёт два файла в рабочей директории сверх tmpBytes и сразу завершается с кодом 0.
        $caught = null;
        try {
            $tool->run(
                ['python3', '-c', 'import os, sys; open(os.path.join(sys.argv[1], "a.bin"), "wb").write(b"x" * 600); open(os.path.join(sys.argv[1], "b.bin"), "wb").write(b"y" * 600)', $dir],
                $dir,
                ToolContext::make(),
                hrtime(true) / 1e9 + 10,
                tmpBytes: 1000,
            );
        } catch (ToolFailed $e) {
            $caught = $e;
        }
        expect($caught)->toBeInstanceOf(ToolFailed::class)
            ->and($caught?->getMessage())->toBe('page_ocr_error')
            ->and($caught?->reason)->toBe('tmp_limit')
            ->and($caught?->exitCode)->toBe(0);

        // Дочерний процесс уходит в новую сессию os.setsid(), но supervisor находит его через /proc и убивает.
        $pidFile = $dir.'/detached.pid';
        try {
            $tool->run(
                ['python3', '-c', 'import os, sys, time
pid = os.fork()
if pid == 0:
    os.setsid()
    open(sys.argv[1], "w").write(str(os.getpid()))
    time.sleep(30)
time.sleep(30)', $pidFile],
                $dir,
                ToolContext::make(),
                hrtime(true) / 1e9 + 0.3,
            );
        } catch (ToolFailed $e) {
            expect($e->reason)->toBe('page_timeout');
        }
        expect(is_file($pidFile))->toBeTrue();
        $detachedPid = (int) trim((string) file_get_contents($pidFile));
        expect($detachedPid)->toBeGreaterThan(0)
            ->and(posix_kill($detachedPid, 0))->toBeFalse();
    } finally {
        if ($prevSecret === false) {
            putenv('AWS_SECRET_ACCESS_KEY_FILE');
        } else {
            putenv('AWS_SECRET_ACCESS_KEY_FILE='.$prevSecret);
        }
        File::deleteDirectory($dir);
    }
});

test('StoredDocumentWriter удаляет неиспользуемый дубликат объекта на диске при дедупликации к каноническому блобу в version, archiveEntry и representation', function (): void {
    StoredDocumentFixtures::reset();
    $writer = app(StoredDocumentWriter::class);

    $bytes = '%PDF-1.4 canonical deduplicated payload';
    $hash = hash('sha256', $bytes);
    $size = strlen($bytes);

    Storage::disk('local')->put('imports/canonical.pdf', $bytes);
    Storage::disk('local')->put('imports/dup-version.pdf', $bytes);
    Storage::disk('local')->put('imports/dup-archive.pdf', $bytes);
    Storage::disk('local')->put('imports/dup-repr.txt', $bytes);

    $doc1 = Document::factory()->create();
    $v1 = $writer->version([
        'document_id' => $doc1->id,
        'filename' => 'canonical.pdf',
        'mime' => 'application/pdf',
        'disk' => 'local',
        'storage_path' => 'imports/canonical.pdf',
        'content_hash' => $hash,
        'size_bytes' => $size,
    ]);

    $doc2 = Document::factory()->create();
    $v2 = $writer->version([
        'document_id' => $doc2->id,
        'filename' => 'dup-version.pdf',
        'mime' => 'application/pdf',
        'disk' => 'local',
        'storage_path' => 'imports/dup-version.pdf',
        'content_hash' => $hash,
        'size_bytes' => $size,
    ]);

    $archiveVer = $writer->archiveEntry($doc1, $v1, 'nested/entry.pdf', 0, [
        'filename' => 'entry.pdf',
        'mime' => 'application/pdf',
        'disk' => 'local',
        'storage_path' => 'imports/dup-archive.pdf',
        'content_hash' => $hash,
        'size_bytes' => $size,
    ]);

    $repr = $writer->representation($v1, RepresentationKind::Text, [
        'disk' => 'local',
        'storage_path' => 'imports/dup-repr.txt',
        'content_hash' => $hash,
        'size_bytes' => $size,
        'mime' => 'text/plain',
        'page_count' => 1,
        'meta' => ['tool' => 'audit-test', 'tool_version' => '1'],
    ]);

    expect($v2->storage_path)->toBe('imports/canonical.pdf')
        ->and($archiveVer->storage_path)->toBe('imports/canonical.pdf')
        ->and($repr->storage_path)->toBe('imports/canonical.pdf')
        ->and(Storage::disk('local')->exists('imports/canonical.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->exists('imports/dup-version.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/dup-archive.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/dup-repr.txt'))->toBeFalse();
});
