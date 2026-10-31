<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\DocumentReference;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use Illuminate\Support\Facades\DB;

final readonly class DocumentReferenceLocks implements PublicationLocks
{
    /** @param list<DocumentReference> $references */
    public function __construct(private int $platformId, private int $procurementId, private array $references) {}

    public function lockIdentities(): void
    {
        CallerTransaction::require('Document identity publication locks');
        $keys = array_map(fn (DocumentReference $r): string => json_encode(
            ['document', $this->platformId, $r->sourceKey->value], JSON_THROW_ON_ERROR), $this->references);
        // Numeric signed64 order, including hash collisions, agrees for every publication batch.
        $rows = DB::select('SELECT DISTINCT hashtextextended(value,0) AS key FROM jsonb_array_elements_text(?::jsonb) ORDER BY key', [json_encode($keys, JSON_THROW_ON_ERROR)]);
        foreach ($rows as $row) {
            if (! $row instanceof \stdClass || (! is_int($row->key) && ! is_string($row->key))) {
                throw new \LogicException('Invalid document advisory key.');
            }
            DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [$row->key]);
        }
    }

    public function lockSubjects(): void
    {
        // Include removed references, whose relationship may be reconciled by CompleteEmpty.
        DB::select('SELECT d.id FROM prc_documents d WHERE (d.platform_id=? AND d.source_document_key IN
            (SELECT value FROM jsonb_array_elements_text(?::jsonb))) OR d.id IN
            (SELECT document_id FROM prc_procurement_documents WHERE procurement_id=?) ORDER BY d.id FOR UPDATE OF d',
            [$this->platformId, json_encode(array_map(fn (DocumentReference $r): string => $r->sourceKey->value, $this->references), JSON_THROW_ON_ERROR), $this->procurementId]);
    }
}
