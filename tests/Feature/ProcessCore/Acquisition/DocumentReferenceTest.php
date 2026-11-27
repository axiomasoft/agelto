<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Detail\DetailResult;
use App\Dto\Procurement\Acquisition\Detail\ProcurementSnapshot;
use App\Dto\Procurement\Document\Download\DocumentReference;
use App\Dto\Procurement\Document\Download\SourceDocumentKey;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Repositories\Procurement\Document\DocumentCatalogRepository;
use App\Repositories\Procurement\Document\DocumentReferenceLocks;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

pest()->group('document-storage-and-references', 'run-state-reduction');

test('detail publication atomically hands references to pending document intent without dispatch', function (): void {
    DetailFixtures::bind();
    Queue::fake();
    $f = DetailFixtures::work();
    $reference = new DocumentReference($f['request']->identity, 'attachment', 'Estimate', new SafeUrl('https://example.org/a?token=first'), new SourceDocumentKey('attachment:1', 1));
    $s = DetailFixtures::snapshot($f['request'], 'documents');
    $s = new ProcurementSnapshot($s->identity, $s->title, $s->sourceStage, [], [], [], [$reference], $s->parts);
    $lease = FinalizeFixtures::running($f['work']);
    app(WorkFinalizer::class)->finish($lease, new StageSucceeded(new DetailResult($s, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));
    $document = DB::table('prc_documents')->first();
    expect($document)->not->toBeNull()->and($document->source_url)->toBeNull()
        ->and($document->access_descriptor_version)->toBe(1)
        ->and(DB::table('prc_procurement_documents')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->value('state'))->toBe('pending')
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->value('pending_reason'))->toBe('handler_not_activated');
    Queue::assertNothingPushed();
    // URL rotation changes the access descriptor, never the logical identity or pending intent.
    $rotated = new DocumentReference($reference->procurement, 'attachment', 'Estimate', new SafeUrl('https://example.org/a?token=second'), $reference->sourceKey);
    DB::transaction(function () use ($f, $rotated): void {
        $locks = new DocumentReferenceLocks($f['p']->platform_id, $f['p']->id, [$rotated]);
        $locks->lockIdentities();
        $locks->lockSubjects();
        app(DocumentCatalogRepository::class)->upsertReference($f['p'], $rotated, ContentHash::of('access'));
    });
    expect(DB::table('prc_documents')->count())->toBe(1)
        ->and(DB::table('prc_documents')->value('access_descriptor_version'))->toBe(2)
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->count())->toBe(1)
        ->and(json_decode(DB::table('prc_documents')->value('access_descriptor'), true)['url'])->toBe('https://example.org/a?token=second');
    DB::transaction(function () use ($f, $rotated): void {
        $locks = new DocumentReferenceLocks($f['p']->platform_id, $f['p']->id, [$rotated]);
        $locks->lockIdentities();
        $locks->lockSubjects();
        app(DocumentCatalogRepository::class)->upsertReference($f['p'], $rotated, ContentHash::of('access'));
    });
    expect(DB::table('prc_documents')->value('access_descriptor_version'))->toBe(2)
        ->and(DB::table('prc_documents')->value('head_generation'))->toBe($document->head_generation);
    expect(fn () => DB::transaction(fn () => DB::table('prc_documents')->where('id', $document->id)->update(['access_descriptor_version' => null])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('prc_documents')->where('id', $document->id)->update(['source_key_version' => null])))
        ->toThrow(QueryException::class);
    DB::transaction(fn () => app(DocumentCatalogRepository::class)->reconcile($f['p']->id, []));
    expect(DB::table('prc_procurement_documents')->value('removed_at'))->not->toBeNull()
        ->and(DB::table('prc_documents')->count())->toBe(1)
        ->and(DB::table('prc_events')->where('event_type', 'document_removed')->count())->toBe(1);
});

test('failure after reference persistence rolls back document link and pending intent', function (): void {
    DetailFixtures::bind();
    $f = DetailFixtures::work();
    $reference = new DocumentReference($f['request']->identity, 'attachment', 'Estimate', new SafeUrl('https://example.org/a'), new SourceDocumentKey('attachment:1', 1));
    expect(function () use ($f, $reference): void {
        DB::transaction(function () use ($f, $reference): void {
            $locks = new DocumentReferenceLocks($f['p']->platform_id, $f['p']->id, [$reference]);
            $locks->lockIdentities();
            $locks->lockSubjects();
            app(DocumentCatalogRepository::class)->upsertReference($f['p'], $reference, ContentHash::of('access'));
            throw new RuntimeException('publication fault');
        });
    })->toThrow(RuntimeException::class, 'publication fault');
    expect(DB::table('prc_documents')->count())->toBe(0)->and(DB::table('prc_procurement_documents')->count())->toBe(0)
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->count())->toBe(0);
});
