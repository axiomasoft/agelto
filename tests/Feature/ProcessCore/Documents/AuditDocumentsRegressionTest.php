<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DownloadResult;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Exceptions\Procurement\Prepare\ToolCleanupUnconfirmed;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\DownloadFinalizer;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\BlobStore;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Document\PrepareWorkspace;
use App\Services\Procurement\Document\StoredDocumentWriter;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\ArchiveStrategy;
use App\Services\Procurement\Prepare\BoundedToolProcess;
use App\Services\Procurement\Prepare\LibreOfficeConverter;
use App\Services\Procurement\Prepare\OcrConverter;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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

function auditDocAcquireBlob(ProcessUuid $domain, string $bytes, ?string $verifiedAgeInterval = null): BlobReference
{
    $uploaded = DocumentStorageFixtures::upload(bytes: $bytes, domain: $domain);
    $blob = app(ProcessTransaction::class)->run(function () use ($domain, $uploaded): BlobReference {
        app(StorageCapacityRepository::class)->lockDomains([$domain]);

        return app(ArtifactRepository::class)->acquireLocked($uploaded);
    });
    if ($verifiedAgeInterval !== null) {
        DB::table('prc_document_blobs')->where('id', $blob->id->value)->update([
            'verified_at' => DB::raw("clock_timestamp() - interval '{$verifiedAgeInterval}'"),
        ]);
    }

    return $blob;
}

function auditDocReserveAndPut(StorageReservationRequest $reservation, string $bytes, ?ProcessUuid $tmpDomain = null): VerifiedArtifact
{
    app(ProcessTransaction::class)->run(function () use ($reservation): void {
        $capacity = app(StorageCapacityRepository::class);
        $capacity->lockDomains([$reservation->domainId]);
        expect($capacity->reserveLocked($reservation))->toBeTrue();
    });
    $tmp = DocumentStorageFixtures::reservation(
        $tmpDomain ?? DocumentStorageFixtures::domain(prefix: 'tmp'),
        max($reservation->byteUpperBound, strlen($bytes)),
        'p31_tmp',
    );
    $stream = fopen('php://memory', 'w+b');
    assert(is_resource($stream));
    fwrite($stream, $bytes);
    rewind($stream);
    try {
        return app(BlobStore::class)->put($reservation, $stream, $tmp);
    } finally {
        fclose($stream);
    }
}

/** @param array<string, string> $entries */
function auditDocMakeZipBytes(array $entries): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'audit_zip_');
    if ($tmp === false) {
        throw new RuntimeException('Не удалось создать временный ZIP.');
    }
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

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
    $artifact = auditDocReserveAndPut($reservation, $bytes);

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
    $blob = auditDocAcquireBlob($domain, 'unreferenced-bytes', '20 minutes');

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
            ['python3', '-c', 'import json, os, resource; print(json.dumps({"aws": os.environ.get("AWS_SECRET_ACCESS_KEY_FILE"), "app_key": os.environ.get("APP_KEY"), "omp": os.environ.get("OMP_THREAD_LIMIT"), "nofile": resource.getrlimit(resource.RLIMIT_NOFILE)[0]}))'],
            $dir,
            ToolContext::make(),
            hrtime(true) / 1e9 + 10,
        );
        $env = json_decode(trim($envJson), true, flags: JSON_THROW_ON_ERROR);
        expect($env['aws'])->toBeNull()
            ->and($env['app_key'])->toBeNull()
            ->and($env['omp'])->toBe('1')
            ->and($env['nofile'])->toBeLessThanOrEqual(1024);

        // Ненулевой код возврата (23) и сигнальный выход (-SIGABRT) не теряются при reap_nonblocking и живых потомках в cleanup.
        $orphanPidFile = $dir.'/orphan-cleanup.pid';
        $nonZeroCaught = null;
        try {
            $tool->run(
                ['python3', '-c', 'import os, sys, time
pid = os.fork()
if pid == 0:
    os.setsid()
    open(sys.argv[1], "w").write(str(os.getpid()))
    time.sleep(30)
while not os.path.exists(sys.argv[1]):
    time.sleep(0.005)
sys.exit(23)', $orphanPidFile],
                $dir,
                ToolContext::make(),
                hrtime(true) / 1e9 + 10,
            );
        } catch (ToolFailed $e) {
            $nonZeroCaught = $e;
        }
        expect($nonZeroCaught)->toBeInstanceOf(ToolFailed::class)
            ->and($nonZeroCaught?->getMessage())->toBe('page_ocr_error')
            ->and($nonZeroCaught?->exitCode)->toBe(23)
            ->and(is_file($orphanPidFile))->toBeTrue();
        $orphanPid = (int) trim((string) file_get_contents($orphanPidFile));
        expect($orphanPid)->toBeGreaterThan(0)
            ->and(posix_kill($orphanPid, 0))->toBeFalse();

        $signalCaught = null;
        try {
            $tool->run(
                ['python3', '-c', 'import os, signal; os.kill(os.getpid(), signal.SIGABRT)'],
                $dir,
                ToolContext::make(),
                hrtime(true) / 1e9 + 10,
            );
        } catch (ToolFailed $e) {
            $signalCaught = $e;
        }
        expect($signalCaught)->toBeInstanceOf(ToolFailed::class)
            ->and($signalCaught?->getMessage())->toBe('page_ocr_error')
            ->and($signalCaught?->exitCode)->toBe(-6);

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

test('ArtifactRepository::unreferencedCandidates и DocumentStorageReconciler пропускают блобы с активными резервациями без голодания очереди GC', function (): void {
    $domain = DocumentStorageFixtures::domain(capacity: 500_000);
    $pinnedIds = [];

    // Создаём 25 старых unreferenced блобов, удерживаемых in-flight writing резервацией на repair.
    for ($i = 0; $i < 25; $i++) {
        $blob = auditDocAcquireBlob($domain, 'pinned-blob-'.$i, '30 minutes');
        expect(app(ArtifactRepository::class)->beginRepair($blob))->toBeTrue();
        $pinnedIds[] = $blob->id->value;
    }

    // 26-й блоб моложе всех 25 удерживаемых, но старше grace-окна и не удерживается резервациями.
    $eligibleBlob = auditDocAcquireBlob($domain, 'eligible-blob-26', '20 minutes');

    $candidates = app(ArtifactRepository::class)->unreferencedCandidates(900, 25);
    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->id->value)->toBe($eligibleBlob->id->value);

    app(DocumentStorageReconciler::class)->reconcile();
    expect(DB::table('prc_document_blobs')->where('id', $eligibleBlob->id->value)->exists())->toBeFalse()
        ->and(DB::table('prc_document_blobs')->whereIn('id', $pinnedIds)->count())->toBe(25);
});

test('DocumentStorageReconciler восстанавливает и очищает прерванную запись файла с мёртвым PID, завершает прерванный repair канонического блоба и чистит резервации закрытых outbound-операций', function (): void {
    $domain = DocumentStorageFixtures::domain(capacity: 500_000);
    $workspace = app(PrepareWorkspace::class);
    $reconciler = app(DocumentStorageReconciler::class);
    $capacity = app(StorageCapacityRepository::class);

    // 1. Download writing-резервация закрытой операции с мёртвым PID в .owner очищается и переводится в released, а без .owner — остаётся в writing.
    $opHandle = app(ProcessTransaction::class)->run(fn () => app(OutboundOperationRepository::class)->open(
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
        OutboundOperationKind::LegacyBridge,
        null,
        'audit.operation.storage',
        LedgerFixtures::hash('op-policy'),
        ['system'],
        120,
        120,
    ));
    app(ProcessTransaction::class)->run(fn () => app(OutboundOperationRepository::class)->close($opHandle));

    $deadDownload = new StorageReservationRequest(
        id: LedgerFixtures::uuid(),
        domainId: $domain,
        ownerToken: $opHandle->ownerToken,
        generation: $opHandle->generation,
        byteUpperBound: 64,
        phase: StoragePhase::Download,
        disk: 'local',
        operationId: $opHandle->id,
    );
    $uncertainDownload = new StorageReservationRequest(
        id: LedgerFixtures::uuid(),
        domainId: $domain,
        ownerToken: $opHandle->ownerToken,
        generation: $opHandle->generation,
        byteUpperBound: 64,
        phase: StoragePhase::Download,
        disk: 'local',
        operationId: $opHandle->id,
    );
    app(ProcessTransaction::class)->run(function () use ($capacity, $domain, $deadDownload, $uncertainDownload): void {
        $capacity->lockDomains([$domain]);
        $capacity->reserveLocked($deadDownload);
        $capacity->reserveLocked($uncertainDownload);
    });
    $capacity->beginWrite($deadDownload);
    $capacity->beginWrite($uncertainDownload);
    Storage::disk('local')->put($deadDownload->objectKey(), 'partial-dead-writer');
    Storage::disk('local')->put($uncertainDownload->objectKey(), 'partial-uncertain-writer');
    $workspace->recordFileWriter($deadDownload);
    file_put_contents(
        Storage::disk('local')->path($deadDownload->objectKey()).'.owner',
        json_encode([
            'reservation' => $deadDownload->id->value,
            'owner_token' => $deadDownload->ownerToken->value,
            'generation' => $deadDownload->generation,
            'key' => $deadDownload->objectKey(),
            'pid' => 999_999_999,
            'start' => 'dead-start',
        ], JSON_THROW_ON_ERROR),
    );

    // 2. Прерванный repair канонического блоба с валидными байтами на диске и мёртвым PID в .repair-owner переводится в transferred без удаления блоба.
    $canonicalBlob = auditDocAcquireBlob($domain, 'canonical-repair-bytes');
    expect(app(ArtifactRepository::class)->beginRepair($canonicalBlob))->toBeTrue();
    $workspace->recordRepairWriter($canonicalBlob);
    file_put_contents(
        Storage::disk('local')->path($canonicalBlob->objectKey).'.repair-owner',
        json_encode([
            'blob' => $canonicalBlob->id->value,
            'key' => $canonicalBlob->objectKey,
            'pid' => 999_999_999,
            'start' => 'dead-start',
        ], JSON_THROW_ON_ERROR),
    );

    // 3. Staged-резервация, привязанная к закрытой outbound-операции (work_id IS NULL, operation_id IS NOT NULL), освобождается.
    $opReservation = new StorageReservationRequest(
        id: LedgerFixtures::uuid(),
        domainId: $domain,
        ownerToken: $opHandle->ownerToken,
        generation: $opHandle->generation,
        byteUpperBound: 32,
        phase: StoragePhase::Download,
        disk: 'local',
        operationId: $opHandle->id,
    );
    auditDocReserveAndPut($opReservation, 'operation-owned-bytes');

    $reconciler->reconcile();

    expect(DB::table('prc_storage_reservations')->where('id', $deadDownload->id->value)->value('status'))->toBe('cleaned')
        ->and(Storage::disk('local')->exists($deadDownload->objectKey()))->toBeFalse()
        ->and(DB::table('prc_storage_reservations')->where('id', $uncertainDownload->id->value)->value('status'))->toBe('writing')
        ->and(Storage::disk('local')->exists($uncertainDownload->objectKey()))->toBeTrue()
        ->and(DB::table('prc_storage_reservations')->where('id', $canonicalBlob->id->value)->value('status'))->toBe('transferred')
        ->and(Storage::disk('local')->exists($canonicalBlob->objectKey))->toBeTrue()
        ->and(Storage::disk('local')->exists($canonicalBlob->objectKey.'.repair-owner'))->toBeFalse()
        ->and(DB::table('prc_storage_reservations')->where('id', $opReservation->id->value)->value('status'))->toBe('cleaned')
        ->and(Storage::disk('local')->exists($opReservation->objectKey()))->toBeFalse();
});

test('невосстановимые repair и ошибка первого объекта не блокируют хвост очереди, а обход возвращается к ранним UUID', function (): void {
    $domain = DocumentStorageFixtures::domain(capacity: 500_000);
    $temporaryDomain = DocumentStorageFixtures::domain(prefix: 'tmp');
    $capacity = app(StorageCapacityRepository::class);
    $artifacts = app(ArtifactRepository::class);
    $disk = Storage::disk('local');
    $repairs = [];
    $charged = 0;
    for ($i = 1; $i <= 26; $i++) {
        $bytes = 'repair-'.$i;
        $reservation = new StorageReservationRequest(
            id: new ProcessUuid(sprintf('00000000-0000-4000-8000-%012d', $i)),
            domainId: $domain,
            ownerToken: LedgerFixtures::uuid(),
            generation: 1,
            byteUpperBound: strlen($bytes),
            phase: StoragePhase::Download,
            disk: 'local',
        );
        $artifact = auditDocReserveAndPut($reservation, $bytes, $temporaryDomain);
        $blob = app(ProcessTransaction::class)->run(function () use ($capacity, $artifacts, $artifact) {
            $capacity->lockDomains([$artifact->reservation->domainId]);

            return $artifacts->acquireLocked($artifact);
        });
        expect($artifacts->beginRepair($blob))->toBeTrue();
        // Odd candidates have no writer-stop proof; even candidates have corrupt stopped writes.
        // The first candidate reaches object I/O to exercise the recoverable error policy.
        if ($i === 1 || $i % 2 === 0) {
            file_put_contents($disk->path($blob->objectKey).'.repair-owner', json_encode([
                'blob' => $blob->id->value, 'key' => $blob->objectKey, 'pid' => 999_999_999, 'start' => 'dead-start',
            ], JSON_THROW_ON_ERROR));
        }
        if ($i < 26) {
            $disk->put($blob->objectKey, 'partial');
        }
        $repairs[] = $blob;
        $charged += strlen($bytes);
    }
    $failed = false;
    $mock = Mockery::mock($disk)->makePartial();
    $mock->shouldReceive('exists')->with($repairs[0]->objectKey)->andReturnUsing(function () use ($disk, $repairs, &$failed): bool {
        if (! $failed) {
            $failed = true;
            throw new RuntimeException('First canonical repair read is temporarily unavailable.');
        }

        return $disk->exists($repairs[0]->objectKey);
    });
    Storage::partialMock()->shouldReceive('disk')->with('local')->andReturn($mock);
    $reconciler = app(DocumentStorageReconciler::class);

    expect($reconciler->reconcile())->toBe(0)->and($failed)->toBeTrue()
        ->and(DB::table('prc_storage_reservations')->where('domain_id', $domain->value)->where('status', 'writing')->count())->toBe(26);
    expect($reconciler->reconcile())->toBe(1)
        ->and(DB::table('prc_storage_reservations')->where('id', $repairs[25]->id->value)->value('status'))->toBe('transferred')
        ->and(DB::table('prc_storage_reservations')->where('domain_id', $domain->value)->where('status', 'writing')->count())->toBe(25)
        ->and((int) DB::table('prc_storage_reservations')->where('domain_id', $domain->value)->where('status', 'writing')->sum('byte_upper_bound'))->toBe($charged - strlen('repair-26'))
        ->and(DB::table('prc_storage_domains')->where('id', $domain->value)->value('accounted_durable_bytes'))->toBe($charged);

    $disk->put($repairs[0]->objectKey, 'repair-1');
    expect($reconciler->reconcile())->toBe(1)
        ->and(DB::table('prc_storage_reservations')->where('id', $repairs[0]->id->value)->value('status'))->toBe('transferred')
        ->and(DB::table('prc_storage_reservations')->where('domain_id', $domain->value)->where('status', 'writing')->count())->toBe(24)
        ->and(DB::table('prc_document_blobs')->where('storage_domain_id', $domain->value)->count())->toBe(26);
});

test('DocumentStorageReconciler изолирует сбой удаления отдельного объекта и продолжает очистку остальных элементов в том же тике', function (): void {
    config()->set('filesystems.disks.failing_audit_disk', ['driver' => 'failing_audit_driver']);
    Storage::extend('failing_audit_driver', static fn () => throw new RuntimeException('Simulated storage driver outage'));

    $domain = DocumentStorageFixtures::domain(capacity: 500_000);
    $brokenBlob = auditDocAcquireBlob($domain, 'broken-blob', '30 minutes');
    $healthyBlob = auditDocAcquireBlob($domain, 'healthy-blob', '20 minutes');

    DB::table('prc_document_blobs')->where('id', $brokenBlob->id->value)->update([
        'disk' => 'failing_audit_disk',
    ]);

    $deleted = app(DocumentStorageReconciler::class)->reconcile();

    expect($deleted)->toBe(1)
        ->and(DB::table('prc_document_blobs')->where('id', $brokenBlob->id->value)->exists())->toBeTrue()
        ->and(DB::table('prc_document_blobs')->where('id', $healthyBlob->id->value)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($healthyBlob->objectKey))->toBeFalse();
});

test('tool_supervisor принудительно убивает дерево процессов с причиной nproc_limit при превышении лимита --max-procs', function (): void {
    $dir = storage_path('app/testing/tool-nproc-'.bin2hex(random_bytes(6)));
    mkdir($dir, 0700, true);

    try {
        $tool = new BoundedToolProcess;
        $pidsFile = $dir.'/spawned.pids';
        $caught = null;

        try {
            $tool->run(
                [
                    '--max-procs',
                    '2',
                    'python3',
                    '-c',
                    'import os, sys, time
pids = []
for _ in range(4):
    pid = os.fork()
    if pid == 0:
        os.setsid()
        time.sleep(30)
    pids.append(str(pid))
open(sys.argv[1], "w").write("\n".join(pids))
time.sleep(30)',
                    $pidsFile,
                ],
                $dir,
                ToolContext::make(),
                hrtime(true) / 1e9 + 10,
            );
        } catch (ToolFailed $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(ToolFailed::class)
            ->and($caught?->reason)->toBe('nproc_limit')
            ->and(is_file($pidsFile))->toBeTrue();

        foreach (array_filter(explode("\n", trim((string) file_get_contents($pidsFile)))) as $pidStr) {
            $pid = (int) $pidStr;
            expect($pid)->toBeGreaterThan(0)
                ->and(posix_kill($pid, 0))->toBeFalse();
        }
    } finally {
        File::deleteDirectory($dir);
    }
});

test('ArchiveStrategy учитывает кумулятивный бюджет вложенных архивов от корня, а SpreadsheetStrategy терминально отклоняет XLSX с DOCTYPE/ENTITY без фолбэка в LibreOffice', function (): void {
    StoredDocumentFixtures::reset();
    $diskName = (string) config('documents.disk');
    $disk = Storage::fake($diskName);

    // 1. Вложенный архив не может обойти max_files за счёт разбиения файлов по уровням вложенности.
    config([
        'ai.prepare.archive.max_depth' => 3,
        'ai.prepare.archive.max_files' => 3,
    ]);

    $nestedZipBytes = auditDocMakeZipBytes([
        'inner-1.txt' => 'first nested payload',
        'inner-2.txt' => 'second nested payload',
    ]);
    $rootZipBytes = auditDocMakeZipBytes([
        'outer.txt' => 'outer sibling payload',
        'nested.zip' => $nestedZipBytes,
    ]);

    $rootDoc = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $rootPath = 'documents/audit-root.zip';
    $disk->put($rootPath, $rootZipBytes);
    $rootVersion = DocumentVersion::factory()->create([
        'document_id' => $rootDoc->id,
        'extension' => 'zip',
        'disk' => $diskName,
        'storage_path' => $rootPath,
        'content_hash' => hash('sha256', $rootZipBytes),
        'size_bytes' => strlen($rootZipBytes),
    ]);

    expect(app(ArchiveStrategy::class)->process($rootDoc, $rootVersion))
        ->toBe(PreparationResult::ExpandedContainer);

    $nestedVersion = DocumentVersion::query()
        ->where('parent_version_id', $rootVersion->id)
        ->where('archive_entry_path', 'nested.zip')
        ->firstOrFail();
    $nestedDoc = Document::query()->findOrFail($nestedVersion->document_id);

    // Корневой архив уже породил 2 записи (outer.txt и nested.zip) при лимите max_files=3;
    // распаковка nested.zip с ещё 2 записями (2 + 2 = 4 > 3) должна быть отклонена.
    expect(app(ArchiveStrategy::class)->process($nestedDoc, $nestedVersion))
        ->toBe(PreparationResult::Unprocessable)
        ->and($nestedDoc->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($nestedDoc->unprocessable_reason)->toBe(UnprocessableReason::ZipBomb)
        ->and(DocumentVersion::query()->where('parent_version_id', $nestedVersion->id)->count())->toBe(0);

    // 2. XLSX с запрещённой декларацией <!DOCTYPE / <!ENTITY терминально отклоняется без вызова LibreOffice.
    Process::fake();
    $maliciousXlsxBytes = auditDocMakeZipBytes([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><worksheet/>',
    ]);
    $xlsxDoc = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $xlsxPath = 'documents/malicious.xlsx';
    $disk->put($xlsxPath, $maliciousXlsxBytes);
    $xlsxVersion = DocumentVersion::factory()->create([
        'document_id' => $xlsxDoc->id,
        'extension' => 'xlsx',
        'disk' => $diskName,
        'storage_path' => $xlsxPath,
        'content_hash' => hash('sha256', $maliciousXlsxBytes),
        'size_bytes' => strlen($maliciousXlsxBytes),
    ]);

    expect(app(SpreadsheetStrategy::class)->process($xlsxDoc, $xlsxVersion))
        ->toBe(PreparationResult::Unprocessable)
        ->and($xlsxDoc->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($xlsxDoc->unprocessable_reason)->toBe(UnprocessableReason::UnreadableFormat);

    Process::assertNothingRan();
});

test('BoundedToolProcess изолирует конвертер через Landlock и Seccomp-BPF и защищает протокол подтверждения остановки от подделки receipt дочерним процессом', function (): void {
    $dir = storage_path('app/testing/tool-sandbox-'.bin2hex(random_bytes(6)));
    $siblingDir = storage_path('app/testing/tool-sibling-'.bin2hex(random_bytes(6)));
    mkdir($dir, 0700, true);
    mkdir($siblingDir, 0700, true);
    $siblingSecret = $siblingDir.'/secret.txt';
    file_put_contents($siblingSecret, 'sibling-workspace-secret');
    $escapeTmpFile = sys_get_temp_dir().'/escape-probe-'.bin2hex(random_bytes(6));

    try {
        $tool = new BoundedToolProcess;

        // 1-3. Проверка запрета чтения /run, .env*, соседнего workspace, записи вне $dir и любых TCP/UDP/Unix сокетов.
        $probeOutput = $tool->run(
            [
                'python3',
                '-c',
                'import errno, json, os, socket, sys
sibling_file, escape_tmp, env_example = sys.argv[1], sys.argv[2], sys.argv[3]
results = {}
def check_denied(name, fn):
    try:
        fn()
        results[name] = "allowed"
    except PermissionError as e:
        results[name] = "eacces" if e.errno == errno.EACCES else f"perm_{e.errno}"
    except OSError as e:
        results[name] = f"oserror_{e.errno}"

check_denied("read_run", lambda: os.listdir("/run"))
check_denied("read_env", lambda: open(env_example, "rb").read(16))
check_denied("read_sibling_file", lambda: open(sibling_file, "rb").read())
check_denied("list_sibling_dir", lambda: os.listdir(os.path.dirname(sibling_file)))
check_denied("write_outside_tmp", lambda: open(escape_tmp, "wb").write(b"x"))
check_denied("write_sibling", lambda: open(sibling_file + ".pwn", "wb").write(b"x"))
check_denied("tcp_socket", lambda: socket.socket(socket.AF_INET, socket.SOCK_STREAM))
check_denied("udp_socket", lambda: socket.socket(socket.AF_INET, socket.SOCK_DGRAM))
check_denied("ipv6_socket", lambda: socket.socket(socket.AF_INET6, socket.SOCK_STREAM))
check_denied("unix_connect", lambda: socket.socket(socket.AF_UNIX, socket.SOCK_STREAM).connect("/run/NonExistent.sock"))
check_denied("unix_bind", lambda: socket.socket(socket.AF_UNIX, socket.SOCK_STREAM).bind(os.path.join(os.getcwd(), "test.sock")))

a, b = socket.socketpair()
a.close()
b.close()
results["socketpair"] = "ok"
print(json.dumps(results))',
                $siblingSecret,
                $escapeTmpFile,
                base_path('.env.example'),
            ],
            $dir,
            ToolContext::make(),
            hrtime(true) / 1e9 + 10,
        );

        $probes = json_decode(trim($probeOutput), true, flags: JSON_THROW_ON_ERROR);
        expect($probes)->toBe([
            'read_run' => 'eacces',
            'read_env' => 'eacces',
            'read_sibling_file' => 'eacces',
            'list_sibling_dir' => 'eacces',
            'write_outside_tmp' => 'eacces',
            'write_sibling' => 'eacces',
            'tcp_socket' => 'eacces',
            'udp_socket' => 'eacces',
            'ipv6_socket' => 'eacces',
            'unix_connect' => 'eacces',
            'unix_bind' => 'eacces',
            'socketpair' => 'ok',
        ])->and(file_exists($escapeTmpFile))->toBeFalse();

        // 4a. Попытка подделать receipt и удалить .active перед ненулевым выходом перезаписывается supervisor-ом с настоящим кодом выхода.
        $forgedExitCaught = null;
        try {
            $tool->run(
                [
                    'python3',
                    '-c',
                    'import glob, json, os, sys
for active in glob.glob(os.path.join(os.getcwd(), "*.active")):
    receipt = active[:-7]
    os.unlink(active)
    with open(receipt, "w") as f:
        json.dump({"stopped": True, "exit": 0, "reason": None, "pgid": os.getpid()}, f)
sys.exit(17)',
                ],
                $dir,
                ToolContext::make(),
                hrtime(true) / 1e9 + 10,
            );
        } catch (ToolFailed $e) {
            $forgedExitCaught = $e;
        }
        expect($forgedExitCaught)->toBeInstanceOf(ToolFailed::class)
            ->and($forgedExitCaught?->exitCode)->toBe(17);

        // 4b. Если дочерний процесс подделывает receipt без секретного токена, удаляет .active и убивает supervisor до финальной записи, BoundedToolProcess отвергает подделку.
        $unconfirmedCaught = null;
        try {
            $tool->run(
                [
                    'python3',
                    '-c',
                    'import glob, json, os, signal, sys
for active in glob.glob(os.path.join(os.getcwd(), "*.active")):
    receipt = active[:-7]
    os.unlink(active)
    with open(receipt, "w") as f:
        json.dump({"stopped": True, "exit": 0, "reason": None, "pgid": os.getpid()}, f)
        f.flush()
os.kill(os.getppid(), signal.SIGKILL)
sys.exit(0)',
                ],
                $dir,
                ToolContext::make(),
                hrtime(true) / 1e9 + 10,
            );
        } catch (ToolCleanupUnconfirmed $e) {
            $unconfirmedCaught = $e;
        }
        expect($unconfirmedCaught)->toBeInstanceOf(ToolCleanupUnconfirmed::class);

        // 5. Legacy-конвертеры очищают секреты окружения при запуске Process.
        $prevAppKey = getenv('APP_KEY');
        putenv('APP_KEY=base64:secret-audit-key');
        try {
            Process::fake([
                '*' => Process::result(output: "tesseract 5.5.0\n"),
            ]);
            app(LibreOfficeConverter::class)->version();
            app(OcrConverter::class)->tesseractVersion();
            try {
                app(ArchiveExtractor::class)->listEntries($dir.'/missing.7z', '7z');
            } catch (Throwable) {
            }

            Process::assertRan(fn (PendingProcess $process): bool => ($process->environment['APP_KEY'] ?? null) === false
                && ($process->environment['OMP_THREAD_LIMIT'] ?? null) === '1');
        } finally {
            if ($prevAppKey === false) {
                putenv('APP_KEY');
            } else {
                putenv('APP_KEY='.$prevAppKey);
            }
        }
    } finally {
        @unlink($escapeTmpFile);
        File::deleteDirectory($dir);
        File::deleteDirectory($siblingDir);
    }
});
