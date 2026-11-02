<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Ai\ChunkRepository;
use App\Repositories\Procurement\Ai\OutputStorageRepository;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ChunkAssemblyFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('t12', 't15', 't22', 'p313');

test('output accounting rejects a foreign artifact owner before writing', function (): void {
    [, $lease] = ExtractionRuntimeFixtures::work();
    $foreign = DocumentStorageFixtures::upload('foreign output', DocumentStorageFixtures::domain());
    $before = DB::table('prc_storage_domains')->sum('accounted_durable_bytes');
    expect(fn () => app(OutputStorageRepository::class)->acquire($lease, $foreign))
        ->toThrow(InvalidArgumentException::class, 'another execution owner');
    expect(DB::table('prc_storage_domains')->sum('accounted_durable_bytes'))->toBe($before);
});

test('output accounting rejects a non-extraction lease before reserving capacity', function (): void {
    [, $screening] = ScreeningRuntimeFixtures::work();
    expect(fn () => app(OutputStorageRepository::class)->reserve($screening, 16))
        ->toThrow(InvalidArgumentException::class, 'extraction owner');
    expect(DB::table('prc_storage_reservations')->where('work_id', $screening->workId->value->value)->count())->toBe(0);
});

test('output spool reserves process-stop proof bytes before the first write', function (): void {
    [$work, $lease] = ExtractionRuntimeFixtures::work();
    DB::table('prc_storage_domains')->where('domain_key', app(PreparePolicy::class)->temporaryDomain)
        ->update(['capacity_bytes' => 16, 'high_watermark_bytes' => 16]);
    expect(fn () => app(OutputStorageRepository::class)->reserve($lease, 16))->toThrow(DependencyUnavailable::class, 'storage_capacity')
        ->and(DB::table('prc_storage_reservations')->where('work_id', $work->value->value)->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->count())->toBe(0);
});

test('a storage set changed by another PostgreSQL connection invalidates the pre-read before accepting any chunk', function (): void {
    [$work, $lease] = ExtractionRuntimeFixtures::work();
    $snapshot = app(AuthorityRepository::class)->readAuthoritySnapshot($work);
    $payload = base64_encode(serialize($lease));
    $code = '\Tests\Support\ProcessCore\ExtractionRuntimeFixtures::bind();'
        .'$lease=unserialize(base64_decode('.var_export($payload, true).'));'
        .'app(\App\Repositories\Procurement\Ai\OutputStorageRepository::class)->reserve($lease,16);'
        .'return ["pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $other = ConcurrentTransactions::execute($code);
    $result = DB::transaction(fn () => app(AuthorityRepository::class)->lockPrefix($snapshot, false));
    expect($other['pid'])->not->toBe(DB::selectOne('SELECT pg_backend_pid() AS id')->id)
        ->and($result)->toBe(PrefixFailure::SnapshotChanged)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('prc_storage_reservations')->where('work_id', $work->value->value)->count())->toBe(2);
});

test('an unaccounted object cannot become a durable chunk even when its JSON schema and hash are valid', function (): void {
    [, , $window] = ExtractionRuntimeFixtures::work();
    $chunk = ChunkAssemblyFixtures::chunk($window);
    Storage::disk($chunk->outputRef->disk)->put($chunk->outputRef->key, $chunk->canonicalRows);
    expect(fn () => DB::transaction(fn () => app(ChunkRepository::class)->storeLocked($chunk)))
        ->toThrow(ArtifactUnavailable::class, 'accounted object');
    expect(DB::table('prc_analysis_chunks')->count())->toBe(0);
});

test('two real PostgreSQL output writers cannot exceed the durable capacity and a failed reserve leaks no spool charge', function (): void {
    [, $lease] = ExtractionRuntimeFixtures::work();
    $canonical = '{"output":"bounded"}';
    DB::table('prc_storage_domains')->where('domain_key', 'documents.originals')
        ->update(['capacity_bytes' => strlen($canonical), 'high_watermark_bytes' => strlen($canonical)]);
    $payload = base64_encode(serialize([$lease, $canonical, Storage::disk('local')->path('')]));
    $code = '\Tests\Support\ProcessCore\ExtractionRuntimeFixtures::bind();'
        .'[$lease,$canonical,$root]=unserialize(base64_decode('.var_export($payload, true).'));'
        .'config(["filesystems.disks.local.root"=>$root]);\Illuminate\Support\Facades\Storage::forgetDisk("local");'
        .'try {app(\App\Services\Procurement\Extraction\ValidatedOutputStore::class)->put($canonical,app(\App\Services\Procurement\Process\ExecutionContextFactory::class)->open($lease));$result="stored";}'
        .'catch(\App\Exceptions\Procurement\Process\DependencyUnavailable $failure){$result=$failure->dependency;}'
        .'return ["result"=>$result,"pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $results = ConcurrentTransactions::concurrently(['one' => $code, 'two' => $code]);
    $outcomes = [$results['one']['result'], $results['two']['result']];
    sort($outcomes);
    expect($outcomes)->toBe(['storage_capacity', 'stored'])->and($results['one']['pid'])->not->toBe($results['two']['pid'])
        ->and((int) DB::table('prc_storage_domains')->where('domain_key', 'documents.originals')->soleValue('accounted_durable_bytes'))->toBe(strlen($canonical))
        ->and(DB::table('prc_storage_reservations')->where('work_id', $lease->workId->value->value)->count())->toBe(2)
        ->and(DB::table('prc_storage_reservations')->where('work_id', $lease->workId->value->value)->whereIn('status', ['reserved', 'writing', 'staged', 'cleaning'])->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0);
});

test('accepted chunk output remains pinned after terminal extraction and cannot be marked for GC', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work();
    ExtractionRuntimeFixtures::capture($lease, $window, $period, AiFixtures::raw());
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $ref = json_decode(DB::table('prc_analysis_chunks')->soleValue('output_ref'), true, flags: JSON_THROW_ON_ERROR);
    $row = DB::table('prc_document_blobs')->where('disk', $ref['disk'])->where('object_key', $ref['key'])->sole();
    DB::table('prc_document_blobs')->where('id', $row->id)->update(['verified_at' => DB::raw("clock_timestamp()-interval '1 hour'")]);
    $blob = new BlobReference(new ProcessUuid($row->id), new ProcessUuid($row->storage_domain_id), new ContentHash($ref['sha256']), $ref['disk'], $ref['key'], $row->size_bytes);
    expect(app(ArtifactRepository::class)->markDeletion($blob, 900))->toBeNull()
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->soleValue('state'))->toBe('succeeded')
        ->and(Storage::disk($blob->disk)->exists($blob->objectKey))->toBeTrue();
});
