<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\DocumentRange;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Document\VerifiedStoredArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Document\DocumentHeadChanged;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Manual imports and retained format tools publish verified immutable metadata through the same blob gate. */
final readonly class StoredDocumentWriter
{
    public function __construct(private StorageCapacityRepository $capacity, private ArtifactRepository $artifacts,
        private DocumentVersionRepository $versions, private ProcessTransaction $transactions) {}

    /** @param array<string,mixed> $attributes */
    public function version(array $attributes): DocumentVersion
    {
        $documentId = $attributes['document_id'] ?? null;
        $filename = $attributes['filename'] ?? null;
        $mime = $attributes['mime'] ?? null;
        if (! is_int($documentId) || ! is_string($filename) || ! is_string($mime)) {
            throw new \InvalidArgumentException('Typed document id, filename and MIME are required.');
        }
        $inputDisk = is_string($attributes['disk'] ?? null) ? $attributes['disk'] : '';
        $inputPath = is_string($attributes['storage_path'] ?? null) ? $attributes['storage_path'] : '';
        $verified = $this->verify($attributes);
        $blob = $verified->blob;
        $parent = $attributes['parent_version_id'] ?? null;
        $path = $attributes['archive_entry_path'] ?? null;
        if (($parent !== null && ! is_int($parent)) || ($path !== null && ! is_string($path))) {
            throw new \InvalidArgumentException('Invalid archive lineage.');
        }
        $ordinal = $parent === null ? null : ($attributes['child_ordinal'] ?? null);
        if ($ordinal !== null && ! is_int($ordinal)) {
            throw new \InvalidArgumentException('Explicit archive ordinal required.');
        }
        $metadata = new SourceDocumentMetadata($filename, $mime, parentVersionId: $parent,
            normalizedEntryPath: $path, childOrdinal: $ordinal);
        /** @var array{int,BlobReference} $committed */
        $committed = $this->transactions->run(function () use ($blob, $verified, $documentId, $metadata): array {
            $this->capacity->lockDomains([$blob->domainId]);
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$documentId]);
            $stored = $this->acquire($verified);

            return [$this->versions->observeBlobLocked($this->versions->head($documentId), $stored, $metadata)->id, $stored];
        });
        [$id, $stored] = $committed;
        $this->cleanupUnreferencedDuplicate($inputDisk, $inputPath, $stored);

        return DocumentVersion::query()->findOrFail($id);
    }

    /** @param array<string,mixed> $attributes */
    public function archiveEntry(Document $parent, DocumentVersion $parentVersion, string $entryPath, int $ordinal, array $attributes): DocumentVersion
    {
        $inputDisk = is_string($attributes['disk'] ?? null) ? $attributes['disk'] : '';
        $inputPath = is_string($attributes['storage_path'] ?? null) ? $attributes['storage_path'] : '';
        $verified = $this->verify($attributes);
        $filename = $attributes['filename'] ?? null;
        $mime = $attributes['mime'] ?? null;
        if (! is_string($filename) || ! is_string($mime) || $parentVersion->document_id !== $parent->id) {
            throw new \InvalidArgumentException('Archive parent/metadata mismatch.');
        }
        $metadata = new SourceDocumentMetadata($filename, $mime, parentVersionId: $parentVersion->id, normalizedEntryPath: $entryPath, childOrdinal: $ordinal);
        /** @var array{int,BlobReference} $committed */
        $committed = $this->transactions->run(function () use ($parent, $parentVersion, $entryPath, $verified, $metadata): array {
            $this->capacity->lockDomains([$verified->blob->domainId]);
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$parent->id]);
            if (! DB::table('prc_document_versions')->where('id', $parentVersion->id)->where('document_id', $parent->id)->exists()) {
                throw new DocumentHeadChanged('Archive parent mapping changed.');
            }
            $child = Document::query()->firstOrCreate([
                'procurement_id' => $parent->procurement_id, 'external_id' => $parentVersion->id.':'.$entryPath,
            ], ['platform_id' => $parent->platform_id, 'title' => basename($entryPath), 'status' => DocumentStatus::Downloaded]);
            // Parent lock serializes the identity even for NULL procurement_id; child+version commit together.
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$child->id]);
            $stored = $this->acquire($verified);

            return [$this->versions->observeBlobLocked($this->versions->head($child->id), $stored, $metadata)->id, $stored];
        });
        [$id, $stored] = $committed;
        $this->cleanupUnreferencedDuplicate($inputDisk, $inputPath, $stored);

        return DocumentVersion::query()->findOrFail($id);
    }

    /** @param array<string,mixed> $attributes */
    public function representation(DocumentVersion $version, RepresentationKind $kind, array $attributes): DocumentRepresentation
    {
        $inputDisk = is_string($attributes['disk'] ?? null) ? $attributes['disk'] : '';
        $inputPath = is_string($attributes['storage_path'] ?? null) ? $attributes['storage_path'] : '';
        $verified = $this->verify($attributes);
        $blob = $verified->blob;
        $meta = $attributes['meta'] ?? [];
        if (! is_array($meta)) {
            throw new \InvalidArgumentException('Representation metadata must be an object.');
        }
        $tool = $meta['tool'] ?? 'stored-format';
        $toolVersion = $meta['tool_version'] ?? '1';
        $pages = $attributes['page_count'] ?? 1;
        if (! is_string($tool) || ! is_string($toolVersion) || ! is_int($pages)) {
            throw new \InvalidArgumentException('Typed tool metadata and page count required.');
        }
        $recipe = hash('sha256', json_encode([$tool, $toolVersion, $meta], JSON_THROW_ON_ERROR));
        /** @var array{int,BlobReference} $committed */
        $committed = $this->transactions->run(function () use ($version, $kind, $attributes, $blob, $verified, $recipe, $toolVersion, $pages): array {
            $this->capacity->lockDomains([$blob->domainId]);
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$version->document_id]);
            DB::select('SELECT id FROM prc_document_versions WHERE id=? FOR UPDATE', [$version->id]);
            $stored = $this->acquire($verified);
            $this->artifacts->referenceLocked($stored);
            $existing = LedgerRow::one("SELECT id,blob_id FROM prc_document_representations WHERE document_version_id=? AND kind=? AND preparation_recipe_hash=decode(?,'hex') AND range_key='complete'", [$version->id, $kind->value, $recipe]);
            if ($existing !== null) {
                if ($existing->string('blob_id') !== $stored->id->value) {
                    throw new \LogicException('Immutable representation recipe returned different bytes.');
                }

                return [$existing->int('id'), $stored];
            }
            // Format output does not prove original-page coverage. Never label it automatic complete coverage.
            $units = max(1, $pages);
            $coverage = (new RepresentationCoverage(CoverageUnit::Page, $units, [], [new DocumentRange(0, $units)]))->json();
            $row = LedgerRow::one("INSERT INTO prc_document_representations(document_version_id,kind,blob_id,preparation_recipe_hash,content_hash,range_key,tool_version,coverage_manifest,coverage_manifest_schema_version,storage_path,disk,size_bytes,page_count,token_estimate,mime,meta,generated_at,created_at,updated_at)
                VALUES (?,?,?,decode(?,'hex'),decode(?,'hex'),'complete',?,?::jsonb,1,?,?,?,?,?,?,?::jsonb,clock_timestamp(),clock_timestamp(),clock_timestamp()) RETURNING id", [
                $version->id, $kind->value, $stored->id->value, $recipe, $stored->hash->hex, $toolVersion, $coverage,
                $stored->objectKey, $stored->disk, $stored->sizeBytes, $attributes['page_count'] ?? null, $attributes['token_estimate'] ?? null,
                $attributes['mime'], json_encode($attributes['meta'] ?? [], JSON_THROW_ON_ERROR)]);

            return [$row?->int('id') ?? throw new \LogicException('Representation insert failed.'), $stored];
        });
        [$id, $stored] = $committed;
        $this->cleanupUnreferencedDuplicate($inputDisk, $inputPath, $stored);

        return DocumentRepresentation::query()->findOrFail($id);
    }

    /** @param array<string,mixed> $attributes */
    private function verify(array $attributes): VerifiedStoredArtifact
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Stored object verification must run outside database transactions.');
        }
        $disk = $attributes['disk'] ?? null;
        $path = $attributes['storage_path'] ?? null;
        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            throw new \InvalidArgumentException('Explicit disk and immutable object key are required.');
        }
        [$hex, $size] = $this->digestObject($disk, $path);
        if (($attributes['content_hash'] ?? $hex) !== $hex || ($attributes['size_bytes'] ?? $size) !== $size) {
            throw new ArtifactUnavailable('Stored object hash or size mismatch.');
        }
        $key = 'documents.import:'.$disk;
        $domain = ListingStoreRepository::id($key);
        // Dotted storage keys are literal array keys.
        $policies = config('documents.storage');
        $limits = is_array($policies) ? ($policies['documents.originals'] ?? null) : null;
        if (! is_array($limits) || ! is_int($limits['capacity_bytes'] ?? null) || ! is_int($limits['high_watermark_bytes'] ?? null)) {
            throw new \LogicException('Missing documents storage policy.');
        }
        $this->capacity->ensureDomain($domain, $key, $limits['capacity_bytes'], $limits['high_watermark_bytes']);
        $id = $domain->value;

        $existing = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE disk=? AND object_key=?", [$disk, $path]);
        if ($existing === null) {
            $existing = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex')", [$id, $hex]);
            if ($existing !== null) {
                // Different sources/recipes can produce identical bytes under different immutable keys.
                // Verify the winning object outside locks before reusing its exact reference.
                if ($existing->nullableString('deletion_marked_at') !== null || $existing->string('disk') !== $disk
                    || $this->digestObject($disk, $existing->string('object_key')) !== [$hex, $size]) {
                    throw new ArtifactUnavailable('Canonical deduplicated object is unavailable.');
                }
            }
        }
        if ($existing !== null) {
            if ($existing->string('hash') !== $hex || $existing->int('size_bytes') !== $size || $existing->nullableString('deletion_marked_at') !== null) {
                throw new ArtifactUnavailable('Stored object metadata changed.');
            }

            return new VerifiedStoredArtifact(new BlobReference(new ProcessUuid($existing->string('id')), new ProcessUuid($existing->string('storage_domain_id')), new ContentHash($hex), $existing->string('disk'), $existing->string('object_key'), $size), true);
        }

        return new VerifiedStoredArtifact(new BlobReference(new ProcessUuid((string) Str::uuid()), new ProcessUuid($id), new ContentHash($hex), $disk, $path, $size), false);
    }

    /** @return array{string,int} */
    private function digestObject(string $disk, string $path): array
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new ArtifactUnavailable('Stored object stream unavailable.');
        }
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);

            return [hash_final($hash), $size];
        } finally {
            fclose($stream);
        }
    }

    /** Caller holds the capacity authority; reference acquisition and GC serialize here. */
    private function acquire(VerifiedStoredArtifact $verified): BlobReference
    {
        $blob = $verified->blob;
        $existing = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex') FOR UPDATE", [$blob->domainId->value, $blob->hash->hex]);
        if ($existing !== null) {
            if (($verified->catalogued && ($existing->string('id') !== $blob->id->value || $existing->string('object_key') !== $blob->objectKey)) || $existing->string('disk') !== $blob->disk || $existing->string('storage_domain_id') !== $blob->domainId->value || $existing->nullableString('deletion_marked_at') !== null || $existing->string('hash') !== $blob->hash->hex || $existing->int('size_bytes') !== $blob->sizeBytes) {
                throw new ArtifactUnavailable('Stored object reference conflicts or is being deleted.');
            }

            return new BlobReference(new ProcessUuid($existing->string('id')), new ProcessUuid($existing->string('storage_domain_id')), $blob->hash,
                $existing->string('disk'), $existing->string('object_key'), $blob->sizeBytes);
        }
        if ($verified->catalogued) {
            throw new ArtifactUnavailable('Previously verified artifact was deleted; do not resurrect its reference.');
        }
        $changed = DB::update("UPDATE prc_storage_domains SET accounted_durable_bytes=accounted_durable_bytes+? WHERE id=? AND accounted_durable_bytes+?+
            (SELECT coalesce(sum(byte_upper_bound),0) FROM prc_storage_reservations WHERE domain_id=? AND status IN ('reserved','writing','staged','cleaning')) <= high_watermark_bytes", [$blob->sizeBytes, $blob->domainId->value, $blob->sizeBytes, $blob->domainId->value]);
        if ($changed !== 1) {
            throw new ArtifactUnavailable('Stored object capacity unavailable.');
        }
        DB::insert("INSERT INTO prc_document_blobs(id,storage_domain_id,content_hash,disk,object_key,size_bytes,verified_at) VALUES (?,?,decode(?,'hex'),?,?,?,clock_timestamp())", [$blob->id->value, $blob->domainId->value, $blob->hash->hex, $blob->disk, $blob->objectKey, $blob->sizeBytes]);

        return $blob;
    }

    private function cleanupUnreferencedDuplicate(string $inputDisk, string $inputPath, BlobReference $stored): void
    {
        if (DB::transactionLevel() !== 0 || $inputDisk === '' || $inputPath === '') {
            return;
        }
        if ($inputDisk === $stored->disk && $inputPath === $stored->objectKey) {
            return;
        }
        if (LedgerRow::one('SELECT 1 FROM prc_document_blobs WHERE disk=? AND object_key=?', [$inputDisk, $inputPath]) !== null) {
            return;
        }
        try {
            Storage::disk($inputDisk)->delete($inputPath);
        } catch (\Throwable) {
            // Best-effort cleanup of unreferenced duplicate object outside transaction.
        }
    }
}
