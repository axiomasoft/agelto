<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\PdfBatchText;
use App\Dto\Procurement\Document\PreparePage;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\DeferralReason;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\PrepareStorageRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Document\BlobStore;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Document\DownloadHandler;
use App\Services\Procurement\Document\PrepareWorkspace;
use App\Services\Procurement\Document\StoredDocumentWriter;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Prepare\PdfPreparationTools;
use App\Services\Procurement\Process\ProcessTransaction;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\UnableToCreateDirectory;
use Psr\Http\Message\RequestInterface;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\PrepareFixtures;

pest()->group('document-processing');

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
});

function crashRecoveryStatus(StorageReservationRequest $reservation): string
{
    return DB::table('prc_storage_reservations')->where('id', $reservation->id->value)->sole()->status;
}

function crashRecoveryS3(): FilesystemAdapter
{
    // Keep object I/O in the isolated filesystem while exercising the non-local adapter path.
    $disk = Mockery::mock(Storage::fake('crash-recovery-s3'))->makePartial();
    $disk->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    Storage::set('crash-recovery-s3', $disk);
    config()->set('filesystems.disks.crash-recovery-s3', ['driver' => 's3', 'http' => ['timeout' => 15]]);
    config()->set('documents.prepare.disk', 'crash-recovery-s3');

    return $disk;
}

test('очистка прерванной загрузки S3 ждёт срока lease и окна завершения запроса', function (): void {
    $disk = crashRecoveryS3();
    $f = new PrepareFixtures;
    $lease = $f->context()->lease();
    $reservation = app(PrepareStorageRepository::class)->reserve($lease)->output;
    app(StorageCapacityRepository::class)->beginWrite($reservation);
    expect($disk->put($reservation->objectKey(), 'прерванная запись'))->toBeTrue();

    $reconciler = app(DocumentStorageReconciler::class);
    expect($reconciler->reconcile())->toBe(0);
    expect(FinalizeFixtures::revoke($f->work, cancel: true))->toBeTrue();
    DB::update("UPDATE prc_process_attempts SET started_at=clock_timestamp()-interval '395 seconds',
        finished_at=clock_timestamp()-interval '35 seconds' WHERE id=?", [$lease->attemptId->value]);
    $reconciler->reconcile();
    expect(crashRecoveryStatus($reservation))->toBe('writing')
        ->and($disk->exists($reservation->objectKey()))->toBeTrue();

    DB::update("UPDATE prc_process_attempts SET started_at=clock_timestamp()-interval '10 minutes',
        finished_at=clock_timestamp()-interval '2 minutes' WHERE id=?", [$lease->attemptId->value]);
    $firstDelete = true;
    $disk->shouldReceive('delete')->twice()->with($reservation->objectKey())->andReturnUsing(function () use ($disk, $reservation, &$firstDelete): bool {
        if ($firstDelete) {
            $firstDelete = false;

            return false;
        }
        $disk->getDriver()->delete($reservation->objectKey());

        return true;
    });
    expect($reconciler->reconcile())->toBe(0)
        ->and(crashRecoveryStatus($reservation))->toBe('cleaning')
        ->and($disk->exists($reservation->objectKey()))->toBeTrue();
    expect($reconciler->reconcile())->toBe(1)
        ->and(crashRecoveryStatus($reservation))->toBe('cleaned')
        ->and($disk->exists($reservation->objectKey()))->toBeFalse()
        ->and($reconciler->reconcile())->toBe(0);
});

test('неподтверждённый ответ записи S3 оставляет резерв под защитой до сверки', function (): void {
    $disk = crashRecoveryS3();
    $f = new PrepareFixtures;
    $context = $f->context();
    $reservations = app(PrepareStorageRepository::class)->reserve($context->lease());
    $disk->shouldReceive('put')->with($reservations->output->objectKey(), Mockery::type('resource'), Mockery::type('array'))->once()->andReturnFalse();
    $stream = fopen('php://memory', 'w+b');
    try {
        fwrite($stream, 'данные');
        rewind($stream);
        expect(fn () => app(BlobStore::class)->put($reservations->output, $stream, $reservations->spool, $context))
            ->toThrow(ArtifactUnavailable::class, 'Object write unconfirmed.');
    } finally {
        fclose($stream);
    }

    expect(crashRecoveryStatus($reservations->output))->toBe('writing')
        ->and(crashRecoveryStatus($reservations->spool))->toBe('cleaned');
});

test('загрузка в S3 прекращается, если попытку отозвали во время проверки ключа', function (): void {
    $disk = crashRecoveryS3();
    $f = new PrepareFixtures;
    $context = $f->context();
    $reservations = app(PrepareStorageRepository::class)->reserve($context->lease());
    $disk->shouldReceive('exists')->twice()->with($reservations->output->objectKey())->andReturnUsing(function () use ($f): bool {
        expect(FinalizeFixtures::revoke($f->work, cancel: true))->toBeTrue();

        return false;
    }, fn (): bool => false);
    $disk->shouldNotReceive('put');
    $stream = fopen('php://memory', 'w+b');
    try {
        fwrite($stream, 'данные');
        rewind($stream);
        expect(fn () => app(BlobStore::class)->put($reservations->output, $stream, $reservations->spool, $context))
            ->toThrow(ArtifactUnavailable::class, 'Remote upload requires a live writer lease.');
    } finally {
        fclose($stream);
    }

    expect(crashRecoveryStatus($reservations->output))->toBe('staged');
    app(DocumentStorageReconciler::class)->reconcile();
    expect(crashRecoveryStatus($reservations->output))->toBe('cleaned');
});

test('запоздавший writer S3 после сверки завершает работу с потерей lease', function (bool $putConfirmed): void {
    $disk = crashRecoveryS3();
    $f = new PrepareFixtures;
    $context = $f->context();
    $reservations = app(PrepareStorageRepository::class)->reserve($context->lease());
    $output = $reservations->output;
    $key = $output->objectKey();
    $recover = function () use ($f, $context, $output): void {
        expect(FinalizeFixtures::revoke($f->work, cancel: true))->toBeTrue();
        DB::update("UPDATE prc_process_attempts SET started_at=clock_timestamp()-interval '10 minutes',
            finished_at=clock_timestamp()-interval '2 minutes' WHERE id=?", [$context->lease()->attemptId->value]);
        app(DocumentStorageReconciler::class)->reconcile();
        expect(crashRecoveryStatus($output))->toBe('cleaned');
    };
    if ($putConfirmed) {
        $disk->shouldReceive('put')->once()->with($key, Mockery::type('resource'), Mockery::type('array'))
            ->andReturnUsing(function () use ($disk, $key, $recover): bool {
                $disk->getDriver()->write($key, 'данные');
                $recover();

                return true;
            });
    } else {
        $disk->shouldReceive('exists')->twice()->with($key)->andReturnUsing(function () use ($recover): bool {
            $recover();

            return false;
        }, fn (): bool => false);
        $disk->shouldNotReceive('put');
    }

    $stream = fopen('php://memory', 'w+b');
    try {
        fwrite($stream, 'данные');
        rewind($stream);
        expect(fn () => app(BlobStore::class)->put($output, $stream, $reservations->spool, $context))
            ->toThrow(ArtifactUnavailable::class, 'Remote upload requires a live writer lease.');
    } finally {
        fclose($stream);
    }

    expect($disk->getDriver()->fileExists($key))->toBeFalse()
        ->and(crashRecoveryStatus($reservations->spool))->toBe('cleaned');
})->with(['до отправки' => false, 'после подтверждения S3' => true]);

test('сбой маркера временного файла освобождает резерв загрузки', function (): void {
    $output = DocumentStorageFixtures::reservation(DocumentStorageFixtures::domain(), 1024);
    $temporary = DocumentStorageFixtures::reservation(DocumentStorageFixtures::domain(), 1024, disk: 'p31_tmp');
    $disk = Mockery::mock(Storage::disk($temporary->disk))->makePartial();
    $disk->shouldReceive('makeDirectory')->once()->with(dirname($temporary->objectKey()))->andReturnFalse();
    Storage::set($temporary->disk, $disk);

    $stream = fopen('php://memory', 'w+b');
    try {
        expect(fn () => app(BlobStore::class)->put($output, $stream, $temporary))
            ->toThrow(ArtifactUnavailable::class, 'Local writer directory unavailable.');
    } finally {
        fclose($stream);
    }

    expect(crashRecoveryStatus($temporary))->toBe('cleaned')
        ->and(crashRecoveryStatus($output))->toBe('reserved');
});

test('запись большого объекта S3 укладывается в один HTTP-запрос даже при ответе 503', function (): void {
    $requests = [];
    $config = [
        'driver' => 's3',
        'bucket' => 'crash-recovery',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        'http' => ['timeout' => 15],
        'retries' => 1,
        'http_handler' => static function (RequestInterface $request, array $options) use (&$requests): RejectedPromise {
            $requests[] = [$request->getMethod(), $options['timeout']];

            return new RejectedPromise([
                'exception' => new RuntimeException('S3 unavailable'),
                'response' => new Response(status: 503),
            ]);
        },
    ];
    config()->set([
        'filesystems.disks.crash-recovery-s3' => $config,
        'documents.prepare.disk' => 'crash-recovery-s3',
        'documents.prepare.artifact_bytes' => 24 * 1024 * 1024,
    ]);
    $disk = Mockery::mock(Storage::build($config))->makePartial();
    Storage::set('crash-recovery-s3', $disk);
    $f = new PrepareFixtures;
    $context = $f->context();
    $reservations = app(PrepareStorageRepository::class)->reserve($context->lease());
    $disk->shouldReceive('exists')->once()->with($reservations->output->objectKey())->andReturnFalse();

    $stream = tmpfile();
    try {
        // Above the AWS SDK's default multipart threshold of 16 MiB.
        expect(ftruncate($stream, 17 * 1024 * 1024))->toBeTrue();
        expect(fn () => app(BlobStore::class)->put($reservations->output, $stream, $reservations->spool, $context))
            ->toThrow(ArtifactUnavailable::class, 'Object write unconfirmed.');
    } finally {
        fclose($stream);
    }

    expect($requests)->toBe([['PUT', 15]])
        ->and(crashRecoveryStatus($reservations->output))->toBe('writing');
});

test('прерванное восстановление S3 снимает блокировку после lease текущей попытки и сохраняет канонический ключ', function (): void {
    $disk = crashRecoveryS3();
    $f = new PrepareFixtures;
    $lease = $f->context()->lease();
    $source = app(PrepareStorageRepository::class)->reserve($lease)->output;
    $capacity = app(StorageCapacityRepository::class);
    $artifacts = app(ArtifactRepository::class);
    $bytes = 'канонический объект';
    $hash = ContentHash::of($bytes);
    $original = DocumentStorageFixtures::reservation($source->domainId, strlen($bytes), disk: $source->disk);
    foreach ([$original, $source] as $reservation) {
        $capacity->beginWrite($reservation);
        expect($disk->put($reservation->objectKey(), $bytes))->toBeTrue();
        $capacity->finishWrite($reservation);
    }
    $blob = app(ProcessTransaction::class)->run(function () use ($capacity, $artifacts, $original, $hash, $bytes): BlobReference {
        $capacity->lockDomains([$original->domainId]);

        return $artifacts->acquireLocked(new VerifiedArtifact($original, $hash, strlen($bytes)));
    });
    expect($disk->put($blob->objectKey, 'повреждённая запись'))->toBeTrue();
    $claim = $artifacts->beginRemoteRepair($blob, source: $source);
    expect($claim)->toBeString()
        ->and($artifacts->remoteRepairClaim($blob)?->string('repair_attempt_id'))->toBe($lease->attemptId->value);
    $reconciler = app(DocumentStorageReconciler::class);
    expect($reconciler->reconcile())->toBe(0);
    FinalizeFixtures::expire($f->work, seconds: 35);
    expect($reconciler->reconcile())->toBe(0)
        ->and(crashRecoveryStatus($original))->toBe('writing');
    FinalizeFixtures::expire($f->work, seconds: 60);
    expect($reconciler->reconcile())->toBe(1)
        ->and(crashRecoveryStatus($original))->toBe('transferred')
        ->and($disk->get($blob->objectKey))->toBe('повреждённая запись')
        ->and($artifacts->finishRemoteRepair($blob, claim: $claim))->toBeFalse();

    expect(FinalizeFixtures::revoke($f->work))->toBeTrue();
    $reconciler->reconcile();
    $retry = app(PrepareStorageRepository::class)->reserve(FinalizeFixtures::rerun($f->work))->output;
    $capacity->beginWrite($retry);
    expect($disk->put($retry->objectKey(), $bytes))->toBeTrue();
    $capacity->finishWrite($retry);
    $newClaim = $artifacts->beginRemoteRepair($blob, source: $retry);
    expect($artifacts->finishRemoteRepair($blob, claim: $claim))->toBeFalse()
        ->and(crashRecoveryStatus($original))->toBe('writing')
        ->and($artifacts->finishRemoteRepair($blob, claim: $newClaim))->toBeTrue();
    app(BlobStore::class)->verifyExisting(new VerifiedArtifact($retry, $hash, strlen($bytes)));
    expect($disk->get($blob->objectKey))->toBe($bytes)
        ->and(crashRecoveryStatus($original))->toBe('transferred');

    DB::table('prc_storage_reservations')->where('id', $original->id->value)->update(['status' => 'writing']);
    $reconciler->reconcile();
    expect(crashRecoveryStatus($original))->toBe('writing')
        ->and($artifacts->remoteRepairClaim($blob)?->string('repair_claimed_at'))->toBeString();
    DB::update("UPDATE prc_storage_reservations SET repair_claimed_at=clock_timestamp()-interval '20 minutes' WHERE id=?", [$blob->id->value]);
    $reconciler->reconcile();
    expect(crashRecoveryStatus($original))->toBe('transferred')
        ->and($disk->get($blob->objectKey))->toBe($bytes);
});

test('сбой инициализации каталога подготовки освобождает резерв до запуска инструментов', function (bool $ownerWriteFails): void {
    $reservation = DocumentStorageFixtures::reservation(DocumentStorageFixtures::domain(), 1024, phase: StoragePhase::Prepare);
    $disk = Storage::disk('local');
    if ($ownerWriteFails) {
        $disk->makeDirectory($reservation->objectKey().'/owner.json');
    } else {
        $disk->put(dirname($reservation->objectKey()), 'занятый путь');
    }

    expect(fn () => app(PrepareWorkspace::class)->open($reservation))
        ->toThrow($ownerWriteFails ? ArtifactUnavailable::class : UnableToCreateDirectory::class);
    expect(crashRecoveryStatus($reservation))->toBe('cleaned')
        ->and($disk->exists($reservation->objectKey()))->toBeFalse();
})->with(['запись владельца' => true, 'создание каталога' => false]);

test('сверка повторяет удаление каталога после временного сбоя очистки при открытии', function (): void {
    $f = new PrepareFixtures;
    $reservation = app(PrepareStorageRepository::class)->reserve($f->context()->lease())->workspace;
    $disk = Storage::disk($reservation->disk);
    $disk->makeDirectory($reservation->objectKey().'/owner.json');
    $mock = Mockery::mock($disk)->makePartial();
    $mock->shouldReceive('deleteDirectory')->once()->with($reservation->objectKey())->andReturnFalse();
    Storage::set($reservation->disk, $mock);
    expect(fn () => app(PrepareWorkspace::class)->open($reservation))->toThrow(ArtifactUnavailable::class);
    expect(crashRecoveryStatus($reservation))->toBe('cleaning');
    Storage::set($reservation->disk, $disk);
    expect(FinalizeFixtures::revoke($f->work, cancel: true))->toBeTrue();
    app(DocumentStorageReconciler::class)->reconcile();
    expect(crashRecoveryStatus($reservation))->toBe('cleaned')
        ->and($disk->exists($reservation->objectKey()))->toBeFalse();
});

test('повреждённое доказательство остановки инструмента сохраняет резерв подготовки', function (string $file): void {
    $reservation = DocumentStorageFixtures::reservation(DocumentStorageFixtures::domain(), 1024, phase: StoragePhase::Prepare);
    $workspace = app(PrepareWorkspace::class);
    $dir = $workspace->open($reservation);
    file_put_contents($dir.'/'.$file, '{');

    expect($workspace->cleanup($reservation))->toBeFalse()
        ->and(crashRecoveryStatus($reservation))->toBe('writing')
        ->and(is_dir($dir))->toBeTrue();
})->with(['owner.json', 'tool-fixture']);

test('короткий замер OCR сохраняет верхний предел размера следующей пачки', function (): void {
    $f = new PrepareFixtures;
    $tools = Mockery::mock(PdfPreparationTools::class);
    $tools->shouldReceive('pageCount')->once()->andReturn(1);
    $tools->shouldReceive('batch')->once()->andReturn(new PdfBatchText(
        anchoredText: "[page:1]\ntext\n", pages: [new PreparePage(1, PageTextStatus::NativeText)], seconds: PHP_FLOAT_MIN,
    ));

    $outcome = $f->handler($tools)->execute($f->input, $f->context());
    expect($outcome)->toBeInstanceOf(StagePartial::class)->and($outcome->checkpoint->nextBatchSize)->toBe(20);
});

test('импорт проверяет объект до установленного потолка и отклоняет лишний байт', function (): void {
    config()->set('documents.download.max_bytes', 4);
    $disk = Storage::disk('local');
    $disk->put('oversize.pdf', '12345');
    $disk->put('exact.pdf', '1234');
    $attributes = ['document_id' => Document::factory()->create()->id, 'filename' => 'file.pdf', 'mime' => 'application/pdf', 'disk' => 'local'];
    $writer = app(StoredDocumentWriter::class);

    expect(fn () => $writer->version([...$attributes, 'storage_path' => 'oversize.pdf']))
        ->toThrow(ArtifactUnavailable::class, 'Stored object exceeds the document byte ceiling.');
    expect(DB::table('prc_document_versions')->count())->toBe(0)
        ->and($writer->version([...$attributes, 'storage_path' => 'exact.pdf'])->size_bytes)->toBe(4);
});

test('неперематываемый поток откладывает загрузку, а сбой удаления тела не мешает освободить основной резерв', function (): void {
    $wire = new WireFixture;
    try {
        $f = new DownloadFixtures($wire);
        [$input, $work, $context] = $f->running();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $bytes = '%PDF-1.4';
        fwrite($write, $bytes);
        fclose($write);

        $transfer = Mockery::mock(Storage::fake('transfer'))->makePartial();
        $transfer->shouldReceive('readStream')->once()->with('body')->andReturn($read);
        $transfer->shouldReceive('delete')->once()->with('body')->andThrow(new RuntimeException('fixture delete failure'));
        Storage::set('transfer', $transfer);
        $body = new BinaryBodyArtifact(new ArtifactRef('transfer', 'body', ContentHash::of($bytes)), strlen($bytes));
        $response = new ResponseEnvelope(status: 200, headers: [], body: $body, wireBytes: strlen($bytes), decodedBytes: strlen($bytes));
        $outbound = Mockery::mock(PlatformOutbound::class);
        $outbound->shouldReceive('execute')->once()->andReturn(new TransportSucceeded($response));
        $handler = app()->makeWith(DownloadHandler::class, ['access' => $f->access, 'outbound' => $outbound]);

        $outcome = $handler->execute($input, $context);
        $reservations = DB::table('prc_storage_reservations as s')->join('prc_storage_domains as d', 'd.id', '=', 's.domain_id')
            ->where('s.work_id', $work->value->value)->pluck('s.status', 'd.domain_key');
        expect($outcome)->toBeInstanceOf(StageDeferred::class)
            ->and($outcome->reason)->toBe(DeferralReason::StorageUnavailable)
            ->and($reservations[config('documents.download.durable_domain')])->toBe('cleaned')
            ->and($reservations[config('documents.download.temporary_domain')])->toBe('reserved')
            ->and(is_resource($read))->toBeFalse();
    } finally {
        $wire->stop();
    }
});

test('сбой чтения счётчика запросов освобождает оба резерва загрузки', function (): void {
    $wire = new WireFixture;
    try {
        $f = new DownloadFixtures($wire);
        [$input, $work, $context] = $f->running();
        $outbound = Mockery::mock(PlatformOutbound::class);
        $outbound->shouldNotReceive('execute');
        $handler = app()->makeWith(DownloadHandler::class, ['access' => $f->access, 'outbound' => $outbound]);
        DB::connection()->beforeExecuting(static function (string $query): void {
            if (str_starts_with($query, 'SELECT count(*) AS sends FROM prc_request_attempts')) {
                throw new RuntimeException('fixture send counter failure');
            }
        });

        expect(fn () => $handler->execute($input, $context))->toThrow(RuntimeException::class, 'fixture send counter failure');
        expect(DB::table('prc_storage_reservations')->where('work_id', $work->value->value)->orderBy('id')->pluck('status')->all())
            ->toBe(['cleaned', 'cleaned']);
    } finally {
        $wire->stop();
    }
});
