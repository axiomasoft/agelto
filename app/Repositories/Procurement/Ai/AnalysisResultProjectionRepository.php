<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** Accepted result/review projections only. WorkFinalizer owns the surrounding guarded commit. */
final readonly class AnalysisResultProjectionRepository
{
    public function screening(AnalysisRunSnapshot $run, StoredScreeningResult $result, AiDocumentType $documentType): int
    {
        return $this->store($run, AnalysisStep::Screening, CanonicalJson::encode($result->toArray()), $documentType->value);
    }

    public function extraction(AnalysisRunSnapshot $run, ContentHash $digest): int
    {
        return $this->store($run, AnalysisStep::Extraction, CanonicalJson::encode([
            'schema_version' => 3, 'analysis_run_id' => $run->id->value, 'publication_digest' => $digest->hex,
        ]), 'estimate');
    }

    private function store(AnalysisRunSnapshot $run, AnalysisStep $step, string $result, string $documentType): int
    {
        CallerTransaction::require('Accepted analysis result projection');
        $uuid = ListingStoreRepository::id('accepted-analysis:'.$run->id->value.':'.$step->value)->value;
        $old = LedgerRow::one('SELECT id,result::text AS result FROM prc_ai_analyses WHERE uuid=?', [$uuid]);
        if ($old !== null) {
            if (CanonicalJson::encode(json_decode($old->string('result'), flags: JSON_THROW_ON_ERROR)) !== $result) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }

            return $old->int('id');
        }
        // The version subject lock serializes accepted replacements; reviews and old results remain historical.
        $parent = LedgerRow::one('SELECT id FROM prc_ai_analyses WHERE document_version_id=? AND step=?
            AND superseded_at IS NULL AND NOT is_shadow', [$run->documentVersionId, $step->value]);
        DB::update('UPDATE prc_ai_analyses SET superseded_at=clock_timestamp(),updated_at=clock_timestamp()
            WHERE document_version_id=? AND step=? AND superseded_at IS NULL AND NOT is_shadow', [$run->documentVersionId, $step->value]);

        return DB::table('prc_ai_analyses')->insertGetId([
            'uuid' => $uuid, 'analysis_run_id' => $run->id->value, 'document_version_id' => $run->documentVersionId,
            'step' => $step->value, 'status' => 'succeeded', 'document_type' => $documentType, 'result' => $result,
            'provider' => $run->recipe->llm->provider, 'model' => $run->recipe->llm->model,
            'parent_analysis_id' => $parent?->int('id'),
            'review_status' => 'unreviewed', 'is_shadow' => false,
            'finished_at' => DB::raw('clock_timestamp()'), 'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }
}
