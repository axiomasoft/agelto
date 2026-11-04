<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DownloadResult;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\DownloadFinalizer;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\BlobStore;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

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
