<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\PrepareSource;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Process\LedgerRow;

final readonly class PrepareSourceRepository
{
    public function get(int $versionId): PrepareSource
    {
        $r = LedgerRow::one("SELECT d.id AS document_id,d.head_generation,d.current_version_id,v.mime,b.id,b.storage_domain_id,
            encode(b.content_hash,'hex') AS hash,b.disk,b.object_key,b.size_bytes FROM prc_document_versions v
            JOIN prc_documents d ON d.id=v.document_id JOIN prc_document_blobs b ON b.id=v.blob_id
            WHERE v.id=? AND b.deletion_marked_at IS NULL", [$versionId]) ?? throw new \LogicException('Verified preparation source missing.');
        if ($r->nullableInt('current_version_id') !== $versionId) {
            throw new \LogicException('Preparation source superseded.');
        }

        return new PrepareSource(new DocumentHead($r->int('document_id'), $r->int('head_generation'), $versionId),
            new BlobReference(new ProcessUuid($r->string('id')), new ProcessUuid($r->string('storage_domain_id')), new ContentHash($r->string('hash')),
                $r->string('disk'), $r->string('object_key'), $r->int('size_bytes')), $r->string('mime'));
    }
}
