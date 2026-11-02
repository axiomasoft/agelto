<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\ChunkReference;
use App\Dto\Procurement\Ai\ValidatedChunk;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** Only the future guarded checkpoint/finalizer owner may call this in its transaction. */
final class ChunkRepository
{
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
            throw new \InvalidArgumentException('chunk_conflict');
        }

        return new ChunkReference(new ProcessUuid($existing->string('id')), new ContentHash($existing->string('hash')));
    }
}
