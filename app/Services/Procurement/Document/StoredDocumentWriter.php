<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Document\VerifiedStoredArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Document\DocumentHeadChanged;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\DocumentRepresentationRepository;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Manual imports and retained format tools publish verified immutable metadata through the same blob gate. */
final readonly class StoredDocumentWriter
{
    public function __construct(
        private StorageCapacityRepository $capacity,
        private ArtifactRepository $artifacts,
        private DocumentVersionRepository $versions,
        private ProcessTransaction $transactions,
        private DocumentRepresentationRepository $representations,
    ) {}

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
            $stored = $this->artifacts->adoptStoredLocked($verified);

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
            $stored = $this->artifacts->adoptStoredLocked($verified);

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
        $recipe = new ContentHash(hex: hash('sha256', json_encode([$tool, $toolVersion, $meta], JSON_THROW_ON_ERROR)));
        $metadata = [
            'page_count' => ($attributes['page_count'] ?? null) === null ? null : $pages,
            'token_estimate' => $attributes['token_estimate'] ?? null,
            'mime' => $attributes['mime'] ?? null,
            'meta' => $meta,
        ];
        /** @var array{int,BlobReference} $committed */
        $committed = $this->transactions->run(function () use ($version, $kind, $metadata, $blob, $verified, $recipe, $toolVersion, $pages): array {
            $this->capacity->lockDomains([$blob->domainId]);
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$version->document_id]);
            DB::select('SELECT id FROM prc_document_versions WHERE id=? FOR UPDATE', [$version->id]);
            $stored = $this->artifacts->adoptStoredLocked($verified);
            $id = $this->representations->storeImportedLocked(
                versionId: $version->id,
                blob: $stored,
                recipe: $recipe,
                kind: $kind,
                toolVersion: $toolVersion,
                pages: $pages,
                metadata: $metadata,
            );

            return [$id, $stored];
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
        $domain = $this->capacity->provisionImportDomain($disk);
        $hash = new ContentHash(hex: $hex);
        $existing = $this->artifacts->storedReferenceAt(disk: $disk, objectKey: $path);
        if ($existing === null) {
            $existing = $this->artifacts->storedReferenceByHash(domain: $domain, hash: $hash);
            if ($existing !== null) {
                // Different sources/recipes can produce identical bytes under different immutable keys.
                // Verify the winning object outside locks before reusing its exact reference.
                if ($existing->disk !== $disk || $this->digestObject($disk, $existing->objectKey) !== [$hex, $size]) {
                    throw new ArtifactUnavailable('Canonical deduplicated object is unavailable.');
                }
            }
        }
        if ($existing !== null) {
            if ($existing->hash != $hash || $existing->sizeBytes !== $size) {
                throw new ArtifactUnavailable('Stored object metadata changed.');
            }

            return new VerifiedStoredArtifact(blob: $existing, catalogued: true);
        }

        return new VerifiedStoredArtifact(
            blob: new BlobReference(
                id: new ProcessUuid(value: (string) Str::uuid()),
                domainId: $domain,
                hash: $hash,
                disk: $disk,
                objectKey: $path,
                sizeBytes: $size,
            ),
            catalogued: false,
        );
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

    private function cleanupUnreferencedDuplicate(string $inputDisk, string $inputPath, BlobReference $stored): void
    {
        if (DB::transactionLevel() !== 0 || $inputDisk === '' || $inputPath === '') {
            return;
        }
        if ($inputDisk === $stored->disk && $inputPath === $stored->objectKey) {
            return;
        }
        if ($this->artifacts->isCatalogued(disk: $inputDisk, objectKey: $inputPath)) {
            return;
        }
        try {
            Storage::disk($inputDisk)->delete($inputPath);
        } catch (\Throwable) {
            // Best-effort cleanup of unreferenced duplicate object outside transaction.
        }
    }
}
