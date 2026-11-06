<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Document\ArtifactGarbageCollector;
use App\Services\Procurement\Document\StoredDocumentWriter;
use App\Services\Procurement\Prepare\RepresentationWriter;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;
use Tests\Support\ProcessCore\StoredDocumentFixtures;
use Tests\Support\ProcessCore\StubPdfTools;

pest()->group('document-storage-and-references');
beforeEach(fn () => StoredDocumentFixtures::reset());

test('разные immutable object keys одинаковых bytes переиспользуют один canonical blob и сохраняют representations', function (): void {
    $original = '%PDF-shared-original';
    $representation = 'identical text from distinct sources';
    $versions = [];
    foreach (['source-a.pdf', 'source-b.pdf'] as $path) {
        Storage::disk('local')->put($path, $original);
        $versions[] = app(StoredDocumentWriter::class)->version([
            'document_id' => Document::factory()->create()->id, 'filename' => $path, 'mime' => 'application/pdf',
            'disk' => 'local', 'storage_path' => $path, 'content_hash' => hash('sha256', $original), 'size_bytes' => strlen($original),
        ]);
    }
    expect($versions[0]->getAttribute('blob_id'))->toBe($versions[1]->getAttribute('blob_id'))
        ->and($versions[1]->storage_path)->toBe('source-a.pdf');
    $outputs = [];
    foreach ($versions as $i => $version) {
        $outputs[] = app(RepresentationWriter::class)->write(Storage::disk('local'), $version, RepresentationKind::Text,
            "different-source-{$i}.txt", $representation, ['disk' => 'local', 'mime' => 'text/plain', 'meta' => ['tool' => 'fixture']]);
    }
    expect($outputs[0]->getAttribute('blob_id'))->toBe($outputs[1]->getAttribute('blob_id'))
        ->and($outputs[0]->storage_path)->toBe($outputs[1]->storage_path)
        ->and(DB::table('prc_document_versions')->count())->toBe(2)
        ->and(DB::table('prc_document_representations')->count())->toBe(2)
        ->and(DB::table('prc_document_blobs')->count())->toBe(2)
        ->and((int) DB::table('prc_storage_domains')->sum('accounted_durable_bytes'))->toBe(strlen($original) + strlen($representation));
    Storage::disk('local')->assertExists($outputs[1]->storage_path);
});

test('manual stored import проверяет bytes и публикует canonical metadata bytea без I/O под transaction', function (): void {
    $bytes = '%PDF-manual';
    $attributes = DocumentVersion::factory()->raw(['disk' => 'local', 'storage_path' => 'manual.pdf', 'content_hash' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
    Storage::disk('local')->put('manual.pdf', $bytes);
    expect(fn () => DB::transaction(fn () => app(StoredDocumentWriter::class)->version($attributes)))
        ->toThrow(LogicException::class, 'outside database transactions');
    $version = app(StoredDocumentWriter::class)->version($attributes);
    expect($version->content_hash)->toBe(hash('sha256', $bytes))
        ->and($version->disk)->toBe('local')
        ->and($version->getAttribute('blob_id'))->not->toBeNull()
        ->and(DB::selectOne('SELECT octet_length(content_hash) AS length FROM prc_document_versions WHERE id=?', [$version->id])->length)->toBe(32);
    Storage::disk('local')->put('manual.pdf', 'wrong');
    expect(fn () => app(StoredDocumentWriter::class)->version($attributes))->toThrow(ArtifactUnavailable::class)
        ->and(DocumentVersion::query()->count())->toBe(1);
});

test('publication другого import между unlocked lookup и capacity lock сохраняет один blob с winning key', function (): void {
    $bytes = '%PDF-interleaved-import';
    $inputs = [];
    foreach (['first.pdf', 'winner.pdf'] as $path) {
        Storage::disk('local')->put($path, $bytes);
        $inputs[] = ['document_id' => Document::factory()->create()->id, 'filename' => $path, 'mime' => 'application/pdf',
            'disk' => 'local', 'storage_path' => $path, 'content_hash' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)];
    }
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed, $inputs): void {
        if ($armed && DB::transactionLevel() === 0 && str_contains($query->sql, 'FROM prc_document_blobs WHERE storage_domain_id=?')) {
            $armed = false;
            app(StoredDocumentWriter::class)->version($inputs[1]);
        }
    });
    $version = app(StoredDocumentWriter::class)->version($inputs[0]);
    expect($armed)->toBeFalse()->and($version->storage_path)->toBe('winner.pdf')
        ->and(DB::table('prc_document_versions')->count())->toBe(2)
        ->and(DB::table('prc_document_blobs')->count())->toBe(1)
        ->and((int) DB::table('prc_storage_domains')->sum('accounted_durable_bytes'))->toBe(strlen($bytes));
});

test('GC после outside verification до locked import не позволяет resurrect удалённый blob или version', function (): void {
    $bytes = '%PDF-orphan';
    $attributes = DocumentVersion::factory()->raw(['disk' => 'local', 'storage_path' => 'orphan.pdf', 'content_hash' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
    Storage::disk('local')->put('orphan.pdf', $bytes);
    $row = DB::table('prc_document_blobs')->where('id', $attributes['blob_id'])->sole();
    DB::table('prc_document_blobs')->where('id', $row->id)->update(['verified_at' => DB::raw("clock_timestamp()-interval '1 hour'")]);
    $blob = new BlobReference(new ProcessUuid($row->id), new ProcessUuid($row->storage_domain_id), new ContentHash($attributes['content_hash']), $row->disk, $row->object_key, $row->size_bytes);
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed, $blob): void {
        if ($armed && DB::transactionLevel() === 0 && str_contains($query->sql, 'FROM prc_document_blobs WHERE disk=? AND object_key=?')) {
            $armed = false;
            $mark = app(ArtifactRepository::class)->markDeletion($blob, 900);
            expect($mark)->not->toBeNull()->and(app(ArtifactGarbageCollector::class)->deleteMarked($mark))->toBeTrue();
        }
    });
    expect(fn () => app(StoredDocumentWriter::class)->version($attributes))->toThrow(ArtifactUnavailable::class, 'deleted')
        ->and($armed)->toBeFalse()
        ->and(DocumentVersion::query()->count())->toBe(0)
        ->and(DB::table('prc_document_blobs')->where('id', $row->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing('orphan.pdf');
});

test('prepare publication и pending analysis intent откатываются вместе при mandatory insert failure', function (): void {
    $f = new PrepareFixtures;
    $tools = new StubPdfTools(1);
    $ctx = $f->context();
    app(WorkFinalizer::class)->finish($ctx->lease(), $f->handler($tools)->execute($f->input, $ctx));
    $ctx = $f->context();
    $outcome = $f->handler($tools)->execute($f->input, $ctx);
    DB::unprepared("CREATE FUNCTION p35_reject_intent() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.stage='screening' THEN RAISE EXCEPTION 'fixture mandatory insert'; END IF; RETURN NEW; END \$\$;
        CREATE TRIGGER p35_reject_intent BEFORE INSERT ON prc_operation_intents FOR EACH ROW EXECUTE FUNCTION p35_reject_intent()");
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($ctx->lease(), $outcome))->toThrow(QueryException::class);
        expect(DB::table('prc_document_representations')->count())->toBe(0)
            ->and(DB::table('prc_operation_intents')->where('stage', 'screening')->count())->toBe(0)
            ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('state'))->toBe('running');
    } finally {
        DB::unprepared('DROP TRIGGER p35_reject_intent ON prc_operation_intents; DROP FUNCTION p35_reject_intent()');
    }
});

test('idempotent storage provisioning не маскирует иной id или изменение capacity policy', function (): void {
    $id = DocumentStorageFixtures::domain(100, 'policy');
    $key = DB::table('prc_storage_domains')->where('id', $id->value)->value('domain_key');
    $capacity = app(StorageCapacityRepository::class);
    $capacity->ensureDomain($id, $key, 100, 100);
    expect(fn () => $capacity->ensureDomain(LedgerFixtures::uuid(), $key, 100, 100))->toThrow(LogicException::class)
        ->and(fn () => $capacity->ensureDomain($id, $key, 200, 200))->toThrow(LogicException::class)
        ->and(DB::table('prc_storage_domains')->count())->toBe(1)
        ->and(DB::table('prc_storage_domains')->where('id', $id->value)->value('capacity_bytes'))->toBe(100);

    config(['documents.storage' => ['documents.originals' => ['capacity_bytes' => 100, 'high_watermark_bytes' => 55]]]);
    $writer = app(StoredDocumentWriter::class);
    $document = Document::factory()->create();
    Storage::disk('local')->put('first-import.pdf', str_repeat('a', 20));
    Storage::disk('local')->put('next-import.pdf', str_repeat('b', 20));
    $attributes = ['document_id' => $document->id, 'filename' => 'import.pdf', 'mime' => 'application/pdf', 'disk' => 'local'];
    $first = $writer->version([...$attributes, 'storage_path' => 'first-import.pdf']);
    $domain = new ProcessUuid(DB::table('prc_document_blobs')->where('id', $first->blob_id)->value('storage_domain_id'));
    $reserved = DocumentStorageFixtures::reservation($domain, 5);
    $writing = DocumentStorageFixtures::reservation($domain, 5);
    $staged = DocumentStorageFixtures::reservation($domain, 5);
    $cleaning = DocumentStorageFixtures::reservation($domain, 5);
    $capacity->beginWrite($writing);
    $capacity->beginWrite($staged);
    $capacity->finishWrite($staged);
    expect(app(ArtifactRepository::class)->beginCleanup($cleaning))->toBeTrue();

    // 20 durable + four charged 5-byte reservations + 20 new bytes exceeds high=55, not capacity=100.
    expect(fn () => $writer->version([...$attributes, 'storage_path' => 'next-import.pdf']))
        ->toThrow(ArtifactUnavailable::class, 'capacity unavailable')
        ->and(DB::table('prc_document_blobs')->count())->toBe(1)
        ->and(DB::table('prc_document_versions')->count())->toBe(1)
        ->and(DB::table('prc_documents')->where('id', $document->id)->value('current_version_id'))->toBe($first->id)
        ->and(DB::table('prc_storage_domains')->where('id', $domain->value)->value('accounted_durable_bytes'))->toBe(20);
    Storage::disk('local')->assertExists('next-import.pdf');

    expect(app(ArtifactGarbageCollector::class)->cleanup($reserved))->toBeTrue();
    $next = $writer->version([...$attributes, 'storage_path' => 'next-import.pdf']);
    expect($next->id)->not->toBe($first->id)
        ->and(DB::table('prc_storage_domains')->where('id', $domain->value)->value('accounted_durable_bytes'))->toBe(40)
        ->and((int) DB::table('prc_storage_reservations')->where('domain_id', $domain->value)->whereIn('status', ['writing', 'staged', 'cleaning'])->sum('byte_upper_bound'))->toBe(15);
});
