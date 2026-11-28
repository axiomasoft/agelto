<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Download\DocumentHead;
use App\Dto\Procurement\Document\Download\DocumentRange;
use App\Dto\Procurement\Document\Prepare\RepresentationCoverage;
use App\Dto\Procurement\Document\Storage\BlobReference;
use App\Dto\Procurement\Document\Storage\VerifiedArtifact;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Exceptions\Procurement\Document\DocumentHeadChanged;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use Illuminate\Support\Facades\DB;

final readonly class DocumentRepresentationRepository
{
    public function __construct(private ArtifactRepository $artifacts, private DocumentVersionRepository $versions) {}

    /** Caller holds full early capacity and subject/work prefix. No converter or storage I/O. */
    public function storeLocked(DocumentHead $head, VerifiedArtifact $artifact, ContentHash $recipe, RepresentationKind $kind,
        string $rangeKey, string $toolVersion, string $mime, RepresentationCoverage $coverage): int
    {
        CallerTransaction::require('Immutable representation publication');
        DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$head->documentId]);
        if ($head->versionId === null || $this->versions->head($head->documentId) != $head) {
            throw new DocumentHeadChanged('Representation input is no longer current.');
        }
        if ($rangeKey === '' || strlen($rangeKey) > 200 || $toolVersion === '' || strlen($toolVersion) > 200 || $mime === '') {
            throw new \InvalidArgumentException('Invalid representation identity.');
        }
        $blob = $this->artifacts->acquireLocked($artifact);
        $existing = LedgerRow::one("SELECT id,blob_id,tool_version,mime,coverage_manifest::text AS coverage FROM prc_document_representations
            WHERE document_version_id=? AND preparation_recipe_hash=decode(?,'hex') AND kind=? AND range_key=?", [$head->versionId, $recipe->hex, $kind->value, $rangeKey]);
        if ($existing !== null) {
            if ($existing->string('blob_id') !== $blob->id->value || $existing->string('tool_version') !== $toolVersion
                || $existing->string('mime') !== $mime || json_decode($existing->string('coverage'), true, flags: JSON_THROW_ON_ERROR) != json_decode($coverage->json(), true, flags: JSON_THROW_ON_ERROR)) {
                throw new \LogicException('Immutable representation key has conflicting content.');
            }

            return $existing->int('id');
        }
        $this->artifacts->referenceLocked($blob);
        $row = LedgerRow::one("INSERT INTO prc_document_representations(document_version_id,kind,blob_id,preparation_recipe_hash,content_hash,range_key,tool_version,coverage_manifest,
            coverage_manifest_schema_version,storage_path,disk,size_bytes,mime,generated_at,created_at,updated_at)
            VALUES (?,?,?,decode(?,'hex'),decode(?,'hex'),?,?,?::jsonb,1,?,?,?,?,clock_timestamp(),clock_timestamp(),clock_timestamp()) RETURNING id",
            [$head->versionId, $kind->value, $blob->id->value, $recipe->hex, $blob->hash->hex, $rangeKey, $toolVersion, $coverage->json(), $blob->objectKey, $blob->disk, $blob->sizeBytes, $mime]) ?? throw new \LogicException('Representation insert failed.');

        return $row->int('id');
    }

    /**
     * Caller holds capacity, the document and supplied version. Historical versions remain eligible.
     * Stored format metadata does not prove original-source coverage or Process head authority.
     *
     * @param  array{page_count: int|null, token_estimate: mixed, mime: mixed, meta: array<array-key,mixed>}  $metadata
     */
    public function storeImportedLocked(int $versionId, BlobReference $blob, ContentHash $recipe, RepresentationKind $kind,
        string $toolVersion, int $pages, array $metadata): int
    {
        CallerTransaction::require('Imported representation publication');
        $this->artifacts->referenceLocked($blob);
        $existing = LedgerRow::one("SELECT id,blob_id FROM prc_document_representations WHERE document_version_id=? AND kind=? AND preparation_recipe_hash=decode(?,'hex') AND range_key='complete'", [$versionId, $kind->value, $recipe->hex]);
        if ($existing !== null) {
            if ($existing->string('blob_id') !== $blob->id->value) {
                throw new \LogicException('Immutable representation recipe returned different bytes.');
            }

            return $existing->int('id');
        }
        $units = max(1, $pages);
        $coverage = (new RepresentationCoverage(CoverageUnit::Page, $units, [], [new DocumentRange(0, $units)]))->json();
        $row = LedgerRow::one("INSERT INTO prc_document_representations(document_version_id,kind,blob_id,preparation_recipe_hash,content_hash,range_key,tool_version,coverage_manifest,coverage_manifest_schema_version,storage_path,disk,size_bytes,page_count,token_estimate,mime,meta,generated_at,created_at,updated_at)
            VALUES (?,?,?,decode(?,'hex'),decode(?,'hex'),'complete',?,?::jsonb,1,?,?,?,?,?,?,?::jsonb,clock_timestamp(),clock_timestamp(),clock_timestamp()) RETURNING id", [
            $versionId, $kind->value, $blob->id->value, $recipe->hex, $blob->hash->hex, $toolVersion, $coverage,
            $blob->objectKey, $blob->disk, $blob->sizeBytes, $metadata['page_count'], $metadata['token_estimate'],
            $metadata['mime'], json_encode($metadata['meta'], JSON_THROW_ON_ERROR)]);

        return $row?->int('id') ?? throw new \LogicException('Representation insert failed.');
    }
}
