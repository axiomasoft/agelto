<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DocumentRevision;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Document\BlobStore;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DocumentStorageFixtures
{
    public static function domain(int $capacity = 100000, string $prefix = 'blob'): ProcessUuid
    {
        $id = new ProcessUuid((string) Str::uuid());
        app(StorageCapacityRepository::class)->ensureDomain($id, $prefix.':'.$id->value, $capacity, $capacity);

        return $id;
    }

    public static function reservation(ProcessUuid $domain, int $bytes, string $disk = 'local', StoragePhase $phase = StoragePhase::Download): StorageReservationRequest
    {
        $request = new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $domain, new ProcessUuid((string) Str::uuid()), 1, $bytes, $phase, $disk);
        app(ProcessTransaction::class)->run(function () use ($request): void {
            $capacity = app(StorageCapacityRepository::class);
            $capacity->lockDomains([$request->domainId]);
            if (! $capacity->reserveLocked($request)) {
                throw new \RuntimeException('Fixture capacity exhausted.');
            }
        });

        return $request;
    }

    public static function upload(string $bytes, ProcessUuid $domain): VerifiedArtifact
    {
        $reservation = self::reservation($domain, max(1, strlen($bytes)));
        $tmp = self::reservation(self::domain(prefix: 'tmp'), max(1, strlen($bytes)), 'p31_tmp');
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Fixture stream failed.');
        }
        try {
            fwrite($stream, $bytes);
            rewind($stream);

            return app(BlobStore::class)->put($reservation, $stream, $tmp);
        } finally {
            fclose($stream);
        }
    }

    public static function observe(DocumentHead $head, VerifiedArtifact $artifact, ?SourceDocumentMetadata $metadata = null): DocumentRevision
    {
        return app(ProcessTransaction::class)->run(function () use ($head, $artifact, $metadata): DocumentRevision {
            app(StorageCapacityRepository::class)->lockDomains([$artifact->reservation->domainId]);

            return app(DocumentVersionRepository::class)->observeLocked($head, $artifact, $metadata ?? new SourceDocumentMetadata('fixture.pdf', 'application/pdf'));
        });
    }

    public static function reset(): void
    {
        DB::statement('TRUNCATE prc_documents,prc_document_versions,prc_document_representations,prc_document_blobs,prc_storage_domains,prc_storage_reservations CASCADE');
        Storage::fake('local');
        Storage::fake('s3');
        Storage::fake('p31_tmp');
    }

    public static function document(): Document
    {
        return Document::factory()->create();
    }
}
