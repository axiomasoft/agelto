<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Exceptions\Procurement\Document\DocumentHeadChanged;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
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
        $row = LedgerRow::one("INSERT INTO prc_document_representations(document_version_id,kind,blob_id,preparation_recipe_hash,content_hash,range_key,tool_version,coverage_manifest,
            coverage_manifest_schema_version,storage_path,disk,size_bytes,mime,generated_at,created_at,updated_at)
            VALUES (?,?,?,decode(?,'hex'),decode(?,'hex'),?,?,?::jsonb,1,?,?,?,?,clock_timestamp(),clock_timestamp(),clock_timestamp()) RETURNING id",
            [$head->versionId, $kind->value, $blob->id->value, $recipe->hex, $blob->hash->hex, $rangeKey, $toolVersion, $coverage->json(), $blob->objectKey, $blob->disk, $blob->sizeBytes, $mime]) ?? throw new \LogicException('Representation insert failed.');

        return $row->int('id');
    }
}
