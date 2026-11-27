<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Storage\BlobReference;
use App\Dto\Procurement\Document\Storage\VerifiedArtifact;
use App\Dto\Procurement\Document\Storage\VerifiedStoredArtifact;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use Illuminate\Support\Facades\DB;

/** Reference acquisition and lookup for durable document blobs. No object I/O here. */
final readonly class ArtifactRepository
{
    public function __construct(private StorageCapacityRepository $capacity) {}

    /** Caller holds all capacity domains BEFORE subjects/work; transfer and reference commit in its tx. */
    public function acquireLocked(VerifiedArtifact $artifact): BlobReference
    {
        CallerTransaction::require('Verified artifact acquisition');
        $request = $artifact->reservation;
        $reservation = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$request->id->value]) ?? throw new \LogicException('Missing upload reservation.');
        $this->capacity->assertIdentity($request, $reservation);
        if (! in_array($reservation->string('status'), ['staged', 'transferred'], true)) {
            throw new ArtifactUnavailable('Upload is being cleaned or is not verified.');
        }
        $existing = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex') FOR UPDATE", [$request->domainId->value, $artifact->hash->hex]);
        if ($existing !== null) {
            if ($existing->nullableString('deletion_marked_at') !== null) {
                throw new ArtifactUnavailable('Blob deletion in progress; retry after verified cleanup.');
            }
            if ($existing->int('size_bytes') !== $artifact->sizeBytes) {
                throw new \LogicException('Content hash has inconsistent size.');
            }

            // Duplicate staged object remains charged until verified deletion of ITS unique key.
            return self::reference($existing);
        }
        if ($reservation->string('status') !== 'staged') {
            throw new ArtifactUnavailable('Previously transferred blob no longer exists.');
        }
        DB::insert("INSERT INTO prc_document_blobs(id,storage_domain_id,content_hash,disk,object_key,size_bytes,verified_at,deletion_generation)
            VALUES (?,?,decode(?,'hex'),?,?,?,clock_timestamp(),0)", [$request->id->value, $request->domainId->value,
            $artifact->hash->hex, $request->disk, $request->objectKey(), $artifact->sizeBytes]);
        DB::update('UPDATE prc_storage_domains SET accounted_durable_bytes=accounted_durable_bytes+? WHERE id=?', [$artifact->sizeBytes, $request->domainId->value]);
        DB::update("UPDATE prc_storage_reservations SET status='transferred' WHERE id=?", [$request->id->value]);

        return new BlobReference($request->id, $request->domainId, $artifact->hash, $request->disk, $request->objectKey(), $artifact->sizeBytes);
    }

    /** Stored imports have no upload reservation. Caller holds capacity before document/version locks. */
    public function adoptStoredLocked(VerifiedStoredArtifact $verified): BlobReference
    {
        CallerTransaction::require('Stored artifact adoption');
        $blob = $verified->blob;
        $existing = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex') FOR UPDATE", [$blob->domainId->value, $blob->hash->hex]);
        if ($existing !== null) {
            if (
                ($verified->catalogued && (
                    $existing->string('id') !== $blob->id->value
                    || $existing->string('object_key') !== $blob->objectKey
                ))
                || $existing->string('disk') !== $blob->disk
                || $existing->string('storage_domain_id') !== $blob->domainId->value
                || $existing->nullableString('deletion_marked_at') !== null
                || $existing->string('hash') !== $blob->hash->hex
                || $existing->int('size_bytes') !== $blob->sizeBytes
            ) {
                throw new ArtifactUnavailable('Stored object reference conflicts or is being deleted.');
            }

            return self::reference($existing);
        }
        if ($verified->catalogued) {
            throw new ArtifactUnavailable('Previously verified artifact was deleted; do not resurrect its reference.');
        }
        $this->capacity->accountStoredLocked($blob);
        DB::insert("INSERT INTO prc_document_blobs(id,storage_domain_id,content_hash,disk,object_key,size_bytes,verified_at) VALUES (?,?,decode(?,'hex'),?,?,?,clock_timestamp())", [$blob->id->value, $blob->domainId->value, $blob->hash->hex, $blob->disk, $blob->objectKey, $blob->sizeBytes]);

        return $blob;
    }

    public function storedReferenceAt(string $disk, string $objectKey): ?BlobReference
    {
        return self::storedReference(LedgerRow::one(
            "SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE disk=? AND object_key=?",
            [$disk, $objectKey],
        ));
    }

    public function storedReferenceByHash(ProcessUuid $domain, ContentHash $hash): ?BlobReference
    {
        return self::storedReference(LedgerRow::one(
            "SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex')",
            [$domain->value, $hash->hex],
        ));
    }

    /** Includes deletion-marked blobs: a catalogued key is never an unreferenced duplicate. */
    public function isCatalogued(string $disk, string $objectKey): bool
    {
        return LedgerRow::one('SELECT 1 FROM prc_document_blobs WHERE disk=? AND object_key=?', [$disk, $objectKey]) !== null;
    }

    /** Full reference gate, called after the early capacity locks, before inserting any reference. */
    public function referenceLocked(BlobReference $blob): void
    {
        CallerTransaction::require('Blob reference gate');
        $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
        if ($row === null || $row->nullableString('deletion_marked_at') !== null || self::reference($row) != $blob) {
            throw new ArtifactUnavailable('Blob unavailable for a new reference.');
        }
    }

    public static function reference(LedgerRow $row): BlobReference
    {
        return new BlobReference(new ProcessUuid($row->string('id')), new ProcessUuid($row->string('storage_domain_id')),
            new ContentHash($row->string('hash')), $row->string('disk'), $row->string('object_key'), $row->int('size_bytes'));
    }

    private static function storedReference(?LedgerRow $row): ?BlobReference
    {
        if ($row === null) {
            return null;
        }
        if ($row->nullableString('deletion_marked_at') !== null) {
            throw new ArtifactUnavailable('Stored object metadata changed.');
        }

        return self::reference($row);
    }
}
