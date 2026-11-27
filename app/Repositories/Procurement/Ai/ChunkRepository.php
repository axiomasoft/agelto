<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Procurement\Ai\Extraction\ValidatedChunk;
use App\Dto\Procurement\Ai\Output\ExtractionOutput;
use App\Dto\Procurement\Ai\Output\PageExtractionOutput;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\Planning\ChunkReference;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Dto\Procurement\Document\Storage\BlobReference;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\Pipeline\AnalysisRangeCodec;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;
use Illuminate\Support\Facades\DB;

/** Only the future guarded checkpoint/finalizer owner may call this in its transaction. */
final class ChunkRepository
{
    public function __construct(private readonly ArtifactRepository $artifacts) {}

    /**
     * Restore the exact accepted reference inventory; partition policy checks its count after the range walk.
     *
     * @param  list<ChunkReference>  $references
     * @return array{ranges: list<RowRange|PageRange>, storedChunkCount: int|null}
     */
    public function acceptedCoverage(AnalysisWindowSnapshot $window, array $references): array
    {
        $ranges = [];
        $seen = [];
        foreach ($references as $reference) {
            if (isset($seen[$reference->id->value])) {
                throw new \InvalidArgumentException('Duplicate accepted chunk reference.');
            }
            $seen[$reference->id->value] = true;
            $ranges[] = $this->acceptedRange(window: $window, reference: $reference);
        }
        $stored = LedgerRow::one('SELECT count(*) AS count FROM prc_analysis_chunks WHERE window_id=?', [$window->id->value]);

        return ['ranges' => $ranges, 'storedChunkCount' => $stored?->int('count')];
    }

    /**
     * Restore and fully revalidate immutable canonical rows, without object-store I/O.
     *
     * @return list<ValidatedChunk>
     */
    public function forWindow(AnalysisWindowSnapshot $window): array
    {
        $rows = LedgerRow::all("SELECT *,encode(output_hash,'hex') AS output_hex,encode(subrange_key,'hex') AS subrange_hex FROM prc_analysis_chunks
            WHERE window_id=? AND run_id=? AND recipe_hash=decode(?,'hex') ORDER BY subrange_key,output_ordinal LIMIT 4097",
            [$window->id->value, $window->run->id->value, $window->run->recipe->hash->hex]);
        if (count($rows) > 4096) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        $chunks = [];
        foreach ($rows as $row) {
            if ($row->int('canonical_rows_schema_version') !== 1 || $row->int('provenance_schema_version') !== 1 || $row->int('output_ref_schema_version') !== 1) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }
            $provenance = PayloadReader::json($row->string('provenance'));
            $range = $provenance->object('range');
            $ref = PayloadReader::json($row->string('output_ref'));
            try {
                $chunk = new ValidatedChunk(new ProcessUuid($row->string('id')), $window, AnalysisRangeCodec::restore($range),
                    $row->int('output_ordinal'), new ArtifactRef($ref->string('disk'), $ref->string('key'), new ContentHash($ref->string('sha256'))),
                    new StructuredResult(StructuredDisposition::Accepted, ($window->range instanceof PageRange ? PageExtractionOutput::class : ExtractionOutput::class)::from(json_decode($row->string('canonical_rows'), true, flags: JSON_THROW_ON_ERROR))));
            } catch (\InvalidArgumentException) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }
            if ($chunk->outputHash->hex !== $row->string('output_hex') || $chunk->subrangeKey->hex !== $row->string('subrange_hex')
                || $provenance->string('representation_hash') !== $window->run->representationHash->hex || $provenance->string('input_hash') !== $window->inputHash->hex) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /** Duplicate delivery returns the original durable reference, even with a different proposed ID. */
    public function storeLocked(ValidatedChunk $chunk): ChunkReference
    {
        CallerTransaction::require('Validated analysis chunk');
        $window = $chunk->window;
        $stored = LedgerRow::one("SELECT encode(input_hash,'hex') AS hash FROM prc_analysis_windows WHERE id=? AND run_id=? AND window_key=?", [
            $window->id->value, $window->run->id->value, $window->windowKey,
        ]);
        if ($stored?->string('hash') !== $window->inputHash->hex) {
            throw new \InvalidArgumentException('Analysis window input drift.');
        }
        $blob = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE disk=? AND object_key=? AND content_hash=decode(?,'hex')",
            [$chunk->outputRef->disk, $chunk->outputRef->key, $chunk->outputHash->hex]);
        if ($blob === null) {
            throw new ArtifactUnavailable('Validated chunk requires a verified accounted object.');
        }
        $this->artifacts->referenceLocked(new BlobReference(new ProcessUuid($blob->string('id')), new ProcessUuid($blob->string('storage_domain_id')),
            new ContentHash($blob->string('hash')), $blob->string('disk'), $blob->string('object_key'), $blob->int('size_bytes')));
        DB::affectingStatement("INSERT INTO prc_analysis_chunks(id,run_id,window_id,window_key,recipe_hash,subrange_key,output_ordinal,
            output_ref,output_ref_schema_version,output_hash,canonical_rows,canonical_rows_schema_version,provenance,provenance_schema_version,completed_at)
            VALUES (?,?,?,?,decode(?,'hex'),decode(?,'hex'),?,?::jsonb,1,decode(?,'hex'),?::jsonb,1,?::jsonb,1,clock_timestamp())
            ON CONFLICT(run_id,window_key,recipe_hash,subrange_key,output_ordinal) DO NOTHING", [
            $chunk->id->value, $window->run->id->value, $window->id->value, $window->windowKey, $window->run->recipe->hash->hex,
            $chunk->subrangeKey->hex, $chunk->outputOrdinal, CanonicalJson::encode(['disk' => $chunk->outputRef->disk, 'key' => $chunk->outputRef->key, 'sha256' => $chunk->outputRef->sha256->hex]),
            $chunk->outputHash->hex, $chunk->canonicalRows, CanonicalJson::encode(['input_hash' => $window->inputHash->hex,
                'representation_hash' => $window->run->representationHash->hex, 'range' => $chunk->range->fields()]),
        ]);
        $existing = LedgerRow::one("SELECT id,encode(output_hash,'hex') AS hash FROM prc_analysis_chunks WHERE run_id=? AND window_key=? AND recipe_hash=decode(?,'hex') AND subrange_key=decode(?,'hex') AND output_ordinal=?", [
            $window->run->id->value, $window->windowKey, $window->run->recipe->hash->hex, $chunk->subrangeKey->hex, $chunk->outputOrdinal,
        ]);
        if ($existing?->string('hash') !== $chunk->outputHash->hex) {
            throw new PublicationBlocked(BlockedReason::ChunkConflict);
        }

        return new ChunkReference(new ProcessUuid($existing->string('id')), new ContentHash($existing->string('hash')));
    }

    private function acceptedRange(AnalysisWindowSnapshot $window, ChunkReference $reference): RowRange|PageRange
    {
        $row = LedgerRow::one(
            "SELECT provenance::text AS provenance,encode(output_hash,'hex') AS hash FROM prc_analysis_chunks
             WHERE id=? AND window_id=? AND recipe_hash=decode(?,'hex')",
            [$reference->id->value, $window->id->value, $window->run->recipe->hash->hex],
        );
        if ($row === null || $row->string('hash') !== $reference->outputHash->hex) {
            throw new \InvalidArgumentException('Accepted chunk identity/hash mismatch.');
        }

        return AnalysisRangeCodec::restore(PayloadReader::json($row->string('provenance'))->object('range'));
    }
}
