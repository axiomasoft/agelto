<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DocumentRevision;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Exceptions\Procurement\Document\DocumentHeadChanged;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Support\Facades\DB;

/** Immutable observed revisions, independent of platform/acquisition controls after verified bytes. */
final readonly class DocumentVersionRepository
{
    public function __construct(private ArtifactRepository $artifacts) {}

    public function head(int $documentId): DocumentHead
    {
        $row = LedgerRow::one('SELECT id,head_generation,current_version_id FROM prc_documents WHERE id=?', [$documentId]) ?? throw new \LogicException('Missing document.');

        return new DocumentHead($documentId, $row->int('head_generation'), $row->nullableInt('current_version_id'));
    }

    /** Caller owns full capacity→sorted subjects→work prefix; document must already be locked. */
    public function observeLocked(DocumentHead $expected, VerifiedArtifact $artifact, SourceDocumentMetadata $metadata): DocumentRevision
    {
        CallerTransaction::require('Document revision publication');
        DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$expected->documentId]);
        $head = $this->head($expected->documentId);
        if ($head != $expected) {
            throw new DocumentHeadChanged('Document head changed before publication.');
        }
        $blob = $this->artifacts->acquireLocked($artifact);
        $current = $head->versionId === null ? null : LedgerRow::one('SELECT id,revision_no,blob_id FROM prc_document_versions WHERE id=? AND document_id=?', [$head->versionId, $head->documentId]);
        if ($metadata->parentVersionId !== null) {
            $entry = LedgerRow::one('SELECT id,document_id,revision_no,blob_id,child_ordinal FROM prc_document_versions WHERE parent_version_id=? AND normalized_entry_path=?', [$metadata->parentVersionId, $metadata->normalizedEntryPath]);
            if ($entry !== null) {
                if ($entry->int('document_id') !== $head->documentId || $entry->string('blob_id') !== $blob->id->value || $entry->int('child_ordinal') !== $metadata->childOrdinal) {
                    throw new \LogicException('Conflicting archive entry identity.');
                }

                return new DocumentRevision($entry->int('id'), $entry->int('revision_no'), $head, $blob);
            }
        }
        if ($current !== null && $current->string('blob_id') === $blob->id->value) {
            return new DocumentRevision($current->int('id'), $current->int('revision_no'), $head, $blob);
        }
        $this->artifacts->referenceLocked($blob);
        $revision = ($current?->int('revision_no') ?? 0) + 1;
        $row = LedgerRow::one('INSERT INTO prc_document_versions(document_id,revision_no,blob_id,content_hash,size_bytes,filename,extension,mime,storage_path,disk,downloaded_at,
            etag,last_modified,first_seen_at,parent_version_id,normalized_entry_path,archive_entry_path,child_ordinal,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,clock_timestamp(),?,?,clock_timestamp(),?,?,?,?,clock_timestamp(),clock_timestamp()) RETURNING id',
            [$head->documentId, $revision, $blob->id->value, $blob->hash->hex, $blob->sizeBytes, $metadata->filename,
                strtolower(pathinfo($metadata->filename, PATHINFO_EXTENSION)) ?: 'bin', $metadata->mime, $blob->objectKey, $blob->disk,
                $metadata->etag, $metadata->lastModified?->format(DATE_ATOM), $metadata->parentVersionId, $metadata->normalizedEntryPath, $metadata->normalizedEntryPath, $metadata->childOrdinal]) ?? throw new \LogicException('Revision insert failed.');
        $versionId = $row->int('id');
        $changed = DB::update('UPDATE prc_documents SET current_version_id=?,head_generation=head_generation+1,updated_at=clock_timestamp()
            WHERE id=? AND head_generation=? AND current_version_id IS NOT DISTINCT FROM ?::bigint', [$versionId, $head->documentId, $head->generation, $head->versionId]);
        if ($changed !== 1) {
            throw new DocumentHeadChanged('Document current-head CAS failed.');
        }

        return new DocumentRevision($versionId, $revision, new DocumentHead($head->documentId, $head->generation + 1, $versionId), $blob);
    }
}
