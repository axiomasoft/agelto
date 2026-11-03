<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\BlobDeletion;
use App\Dto\Procurement\Document\DocumentRange;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Document\DocumentHeadChanged;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\DocumentRepresentationRepository;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Document\ArtifactGarbageCollector;
use App\Services\Procurement\Document\BlobStore;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\DocumentStorageFixtures as Fx;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t10', 't12', 'p31');
beforeEach(fn () => Fx::reset());
afterEach(fn () => Fx::reset());

test('A B A сохраняет три source revisions и два blobs, повтор current bytes не создаёт revision', function (): void {
    $document = Fx::document();
    $domain = Fx::domain();
    $versions = app(DocumentVersionRepository::class);
    $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/Documents/storage-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $a = Fx::upload($corpus['a'], $domain);
    $b = Fx::upload($corpus['b'], $domain);
    expect($a->hash->hex)->toBe($corpus['a_sha256'])->and($b->hash->hex)->toBe($corpus['b_sha256']);
    $head0 = $versions->head($document->id);
    $r1 = Fx::observe($head0, $a);
    $r2 = Fx::observe($r1->head, $b);
    $r3 = Fx::observe($r2->head, $a);
    $repeat = Fx::observe($r3->head, $a);
    expect([$r1->revisionNo, $r2->revisionNo, $r3->revisionNo])->toBe([1, 2, 3])
        ->and($r1->id)->not->toBe($r3->id)->and($r1->blob->id)->toEqual($r3->blob->id)
        ->and($repeat->id)->toBe($r3->id)->and($repeat->head->generation)->toBe(4)
        ->and(DB::table('prc_document_versions')->count())->toBe(3)
        ->and(DB::table('prc_document_blobs')->count())->toBe(2)
        ->and(DB::table('prc_storage_domains')->where('id', $domain->value)->value('accounted_durable_bytes'))->toBe(20);
    expect(fn () => Fx::observe($head0, $b))->toThrow(DocumentHeadChanged::class);
    expect($versions->head($document->id))->toEqual($r3->head);
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_document_versions')->where('id', $r1->id)->update(['mime' => 'text/plain'])))->toBe('23514');
});

test('два процесса current head CAS принимают ровно одну competing revision', function (): void {
    $document = Fx::document();
    $domain = Fx::domain();
    $a = Fx::upload('%PDF A', $domain);
    $b = Fx::upload('%PDF B', $domain);
    $head = app(DocumentVersionRepository::class)->head($document->id);
    $worker = <<<'PHP'
        $head = unserialize(base64_decode('%s'), ['allowed_classes' => true]);
        $artifact = unserialize(base64_decode('%s'), ['allowed_classes' => true]);
        try {
            $revision = \Tests\Support\ProcessCore\DocumentStorageFixtures::observe($head,$artifact);
            return ['status'=>'accepted','id'=>$revision->id,'pid'=>\Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() pid')->pid];
        } catch (\App\Exceptions\Procurement\Document\DocumentHeadChanged) {
            return ['status'=>'stale','pid'=>\Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() pid')->pid];
        }
        PHP;
    $results = ConcurrentTransactions::concurrently(['a' => sprintf($worker, base64_encode(serialize($head)), base64_encode(serialize($a))),
        'b' => sprintf($worker, base64_encode(serialize($head)), base64_encode(serialize($b)))]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['accepted', 'stale'])->and($results['a']['pid'])->not->toBe($results['b']['pid'])
        ->and(DB::table('prc_document_versions')->count())->toBe(1)
        ->and(DB::table('prc_document_blobs')->count())->toBe(1)
        ->and(DB::table('prc_documents')->where('id', $document->id)->value('head_generation'))->toBe(2);
});

test('dedup transfer оставляет duplicate staging charged до verified cleanup', function (): void {
    $domain = Fx::domain();
    $first = Fx::upload('same bytes', $domain);
    $second = Fx::upload('same bytes', $domain);
    Fx::observe(app(DocumentVersionRepository::class)->head(Fx::document()->id), $first);
    Fx::observe(app(DocumentVersionRepository::class)->head(Fx::document()->id), $second);
    expect(DB::table('prc_document_blobs')->count())->toBe(1)
        ->and(DB::table('prc_storage_domains')->where('id', $domain->value)->value('accounted_durable_bytes'))->toBe(10)
        ->and(DB::table('prc_storage_reservations')->where('id', $second->reservation->id->value)->value('status'))->toBe('staged');
    expect(app(ArtifactGarbageCollector::class)->cleanup($second->reservation))->toBeTrue()
        ->and(app(ArtifactGarbageCollector::class)->cleanup($second->reservation))->toBeTrue()
        ->and(app(ArtifactGarbageCollector::class)->cleanup($first->reservation))->toBeFalse();
    Storage::disk('local')->assertExists($first->reservation->objectKey());
    Storage::disk('local')->assertMissing($second->reservation->objectKey());
});

test('put-before-reference сохраняет reserve, uncertain writer и incomplete cleanup не освобождают bytes', function (): void {
    $domain = Fx::domain(30);
    $artifact = Fx::upload('persisted bytes', $domain);
    expect(DB::table('prc_storage_reservations')->where('id', $artifact->reservation->id->value)->value('status'))->toBe('staged')
        ->and(DB::table('prc_document_blobs')->count())->toBe(0);
    $uncertain = Fx::reservation($domain, 10);
    app(StorageCapacityRepository::class)->beginWrite($uncertain);
    Storage::disk('local')->put($uncertain->objectKey(), 'partial');
    expect(app(ArtifactGarbageCollector::class)->cleanup($uncertain))->toBeFalse();
    app(ArtifactRepository::class)->beginCleanup($artifact->reservation);
    expect(DB::table('prc_storage_reservations')->where('id', $artifact->reservation->id->value)->value('cleanup_verified_at'))->toBeNull();
    expect(app(ArtifactGarbageCollector::class)->cleanup($artifact->reservation))->toBeTrue();
    expect(DB::table('prc_storage_reservations')->where('id', $uncertain->id->value)->value('status'))->toBe('writing');
});

test('byte ceiling прерывает upload и temporary capacity освобождается только после verified cleanup', function (): void {
    $domain = Fx::domain();
    $request = Fx::reservation($domain, 3);
    $tmp = Fx::reservation(Fx::domain(prefix: 'tmp'), 3, 'p31_tmp');
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, 'oversized');
    rewind($stream);
    try {
        expect(fn () => app(BlobStore::class)->put($request, $stream, $tmp))->toThrow(ArtifactUnavailable::class);
    } finally {
        fclose($stream);
    }
    expect(DB::table('prc_storage_reservations')->where('id', $request->id->value)->value('status'))->toBe('reserved')
        ->and(DB::table('prc_storage_reservations')->where('id', $tmp->id->value)->value('status'))->toBe('cleaned');
    Storage::disk('local')->assertMissing($request->objectKey());
});

test('archive lineage повторяется идемпотентно и запрещает ambiguous path и traversal', function (): void {
    $domain = Fx::domain();
    $parent = Fx::observe(app(DocumentVersionRepository::class)->head(Fx::document()->id), Fx::upload('archive', $domain));
    $child = Fx::document();
    $artifact = Fx::upload('entry', $domain);
    $metadata = new SourceDocumentMetadata('estimate.pdf', 'application/pdf', parentVersionId: $parent->id, normalizedEntryPath: 'folder/estimate.pdf', childOrdinal: 0);
    $r1 = Fx::observe(app(DocumentVersionRepository::class)->head($child->id), $artifact, $metadata);
    $r2 = Fx::observe($r1->head, $artifact, $metadata);
    expect($r2->id)->toBe($r1->id)->and(DB::table('prc_document_versions')->where('parent_version_id', $parent->id)->count())->toBe(1);
    expect(fn () => new SourceDocumentMetadata('x', 'text/plain', parentVersionId: $parent->id, normalizedEntryPath: '../x', childOrdinal: 0))->toThrow(RuntimeException::class);
    $conflict = Fx::upload('different entry', $domain);
    expect(fn () => Fx::observe(app(DocumentVersionRepository::class)->head(Fx::document()->id), $conflict, $metadata))->toThrow(LogicException::class);
});

test('representation фиксирует recipe range disk coverage, sources pause и default disk не меняют bytes', function (): void {
    $domain = Fx::domain();
    $revision = Fx::observe(app(DocumentVersionRepository::class)->head(Fx::document()->id), Fx::upload('source bytes', $domain));
    config(['crawler.downstream_enabled' => false, 'documents.disk' => 'unconfigured-disk']);
    $prepared = Fx::upload('native text', $domain);
    $coverage = new RepresentationCoverage(CoverageUnit::Page, 2, [new DocumentRange(0, 1)], [new DocumentRange(1, 2)]);
    $store = fn (): int => app(ProcessTransaction::class)->run(function () use ($domain, $revision, $prepared, $coverage): int {
        app(StorageCapacityRepository::class)->lockDomains([$domain]);

        return app(DocumentRepresentationRepository::class)->storeLocked($revision->head, $prepared, ContentHash::of('recipe1'), RepresentationKind::Text, 'pages:0:2', 'tool/1', 'text/plain', $coverage);
    });
    $id = $store();
    expect($store())->toBe($id)->and($coverage->complete())->toBeFalse()
        ->and(DB::table('prc_document_representations')->where('id', $id)->value('disk'))->toBe('local');
    Storage::disk('local')->assertExists($prepared->reservation->objectKey());
    expect(fn () => new RepresentationCoverage(CoverageUnit::Page, 2, [new DocumentRange(0, 1)], []))->toThrow(InvalidArgumentException::class);
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_document_representations')->where('id', $id)->update(['storage_path' => 'overwrite'])))->toBe('23514');
});

test('GC mark-before-delete barrier запрещает concurrent reference, crash replay завершает cleanup один раз', function (): void {
    $domain = Fx::domain();
    $artifact = Fx::upload('orphan blob', $domain);
    $blob = app(ProcessTransaction::class)->run(function () use ($domain, $artifact) {
        app(StorageCapacityRepository::class)->lockDomains([$domain]);

        return app(ArtifactRepository::class)->acquireLocked($artifact);
    });
    DB::update("UPDATE prc_document_blobs SET verified_at=clock_timestamp()-interval '1 hour' WHERE id=?", [$blob->id->value]);
    $ticket = app(ArtifactRepository::class)->markDeletion($blob, 900);
    expect($ticket)->not->toBeNull();
    expect(app(ArtifactGarbageCollector::class)->deleteMarked(new BlobDeletion($blob, $ticket->generation + 1)))->toBeFalse();
    Storage::disk('local')->assertExists($blob->objectKey);
    // Durable mark is the barrier: deletion is deliberately withheld until another PG process tries publication.
    $head = app(DocumentVersionRepository::class)->head(Fx::document()->id);
    $worker = sprintf(<<<'PHP'
        $head=unserialize(base64_decode('%s'),['allowed_classes'=>true]);
        $artifact=unserialize(base64_decode('%s'),['allowed_classes'=>true]);
        try { \Tests\Support\ProcessCore\DocumentStorageFixtures::observe($head,$artifact); return ['rejected'=>false]; }
        catch (\App\Exceptions\Procurement\Document\ArtifactUnavailable) { return ['rejected'=>true]; }
        PHP, base64_encode(serialize($head)), base64_encode(serialize($artifact)));
    expect(ConcurrentTransactions::execute($worker)['rejected'])->toBeTrue()
        ->and(DB::table('prc_document_versions')->count())->toBe(0);
    Storage::disk('local')->assertExists($blob->objectKey);
    expect(app(ArtifactRepository::class)->markDeletion($blob, 900))->toEqual($ticket)
        ->and(app(ArtifactGarbageCollector::class)->deleteMarked($ticket))->toBeTrue()
        ->and(app(ArtifactGarbageCollector::class)->deleteMarked($ticket))->toBeTrue()
        ->and(DB::table('prc_document_blobs')->count())->toBe(0)
        ->and(DB::table('prc_storage_domains')->where('id', $domain->value)->value('accounted_durable_bytes'))->toBe(0);
    Storage::disk('local')->assertMissing($blob->objectKey);
});

test('reference-before-GC защищает historical blob после переключения current head', function (): void {
    $domain = Fx::domain();
    $document = Fx::document();
    $r1 = Fx::observe(app(DocumentVersionRepository::class)->head($document->id), Fx::upload('history', $domain));
    Fx::observe($r1->head, Fx::upload('new head', $domain));
    DB::update("UPDATE prc_document_blobs SET verified_at=clock_timestamp()-interval '1 hour' WHERE id=?", [$r1->blob->id->value]);
    expect(app(ArtifactRepository::class)->markDeletion($r1->blob, 900))->toBeNull()
        ->and(DB::table('prc_document_versions')->where('blob_id', $r1->blob->id->value)->count())->toBe(1);
    Storage::disk('local')->assertExists($r1->blob->objectKey);
});

test('corrupt read-back не создаёт blob и сохраняет reservation, current pointer не ссылается на чужой документ', function (): void {
    $domain = Fx::domain();
    $artifact = Fx::upload('verified bytes', $domain);
    Storage::disk('local')->put($artifact->reservation->objectKey(), 'corrupt');
    expect(fn () => app(BlobStore::class)->verify($artifact->reservation, $artifact->hash, $artifact->sizeBytes))->toThrow(ArtifactUnavailable::class)
        ->and(DB::table('prc_document_blobs')->count())->toBe(0)
        ->and(DB::table('prc_storage_reservations')->where('id', $artifact->reservation->id->value)->value('status'))->toBe('staged');
    $fresh = Fx::upload('other bytes', $domain);
    $revision = Fx::observe(app(DocumentVersionRepository::class)->head(Fx::document()->id), $fresh);
    $other = Fx::document();
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_documents')->where('id', $other->id)->update(['current_version_id' => $revision->id])))->toBe('23503');
    $replacement = Fx::observe($revision->head, Fx::upload('new source bytes', $domain));
    $prepared = Fx::upload('late prepared', $domain);
    expect(fn () => app(ProcessTransaction::class)->run(function () use ($domain, $revision, $prepared): void {
        app(StorageCapacityRepository::class)->lockDomains([$domain]);
        app(DocumentRepresentationRepository::class)->storeLocked($revision->head, $prepared, ContentHash::of('recipe'), RepresentationKind::Text,
            'all', 'tool/1', 'text/plain', new RepresentationCoverage(CoverageUnit::Page, 1, [new DocumentRange(0, 1)], []));
    }))->toThrow(DocumentHeadChanged::class);
    expect(DB::table('prc_document_representations')->count())->toBe(0)
        ->and(app(DocumentVersionRepository::class)->head($revision->head->documentId))->toEqual($replacement->head)
        ->and(DB::table('prc_storage_reservations')->where('id', $prepared->reservation->id->value)->value('status'))->toBe('staged');
});
