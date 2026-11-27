<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Publication;

use App\Dto\Procurement\Ai\Execution\PublicationCompleted;
use App\Dto\Procurement\Ai\Extraction\StitchedEstimate;
use App\Dto\Procurement\Process\Inputs\PublicationInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Procurement\Ai\AnalysisPlanRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Ai\ChunkRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\Pipeline\ChunkStitcher;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;

/** DB-only validation: safe both before finalization and under its existing subject locks. */
final readonly class EstimatePublisher
{
    public function __construct(private AnalysisRepository $analyses, private AnalysisPlanRepository $plans,
        private ChunkRepository $chunks, private ChunkStitcher $stitcher) {}

    /** @return non-empty-list<StitchedEstimate> */
    public function estimates(PublicationInput $input): array
    {
        $run = $this->analyses->getRun($input->analysisRunId);
        if ($run->recipe->hash != $input->recipeHash || $run->generation !== $input->resultGeneration) {
            throw new PublicationBlocked(BlockedReason::InvalidRecipeConfiguration);
        }
        $rep = LedgerRow::one('SELECT coverage_manifest,coverage_manifest_schema_version FROM prc_document_representations WHERE id=?', [$run->representationId]);
        if ($rep === null || $rep->int('coverage_manifest_schema_version') !== 1) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        $coverage = PayloadReader::json($rep->string('coverage_manifest'));
        // Completeness is proved by the original preparation manifest, independently of model rows.
        $cursor = 0;
        foreach ($coverage->list('covered') as $part) {
            if (! is_array($part) || count($part) !== 2 || ! is_int($part[0]) || ! is_int($part[1]) || $part[0] !== $cursor || $part[1] <= $cursor) {
                throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
            }
            $cursor = $part[1];
        }
        if ($coverage->list('missing') !== [] || $cursor !== $coverage->integer('source_units')) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        $result = [];
        foreach ($this->plans->plans($run) as $plan) {
            $chunks = [];
            foreach ($plan->windows as $window) {
                $chunks = [...$chunks, ...$this->chunks->forWindow($window)];
            }
            $result[] = $this->stitcher->stitch($plan, $chunks);
        }

        return $result;
    }

    public function result(PublicationInput $input): PublicationCompleted
    {
        $estimates = $this->estimates($input);
        $fields = [];
        foreach ($estimates as $estimate) {
            $fields[] = ['ordinal' => $estimate->plan->estimateOrdinal, 'required' => $estimate->plan->required->fields(),
                'rows' => array_map(static fn ($row): array => $row->toArray(), $estimate->rows),
                'totals' => array_map(static fn ($node): array => $node->toArray(), $estimate->totals),
                'row_chunks' => array_map(static fn ($ids): array => array_map(static fn ($id): string => $id->value, $ids), $estimate->rowChunks),
                'total_chunks' => array_map(static fn ($ids): array => array_map(static fn ($id): string => $id->value, $ids), $estimate->totalChunks)];
        }

        return new PublicationCompleted($input->analysisRunId, $input->recipeHash, $input->resultGeneration, new ContentHash(CanonicalJson::hash($fields)));
    }
}
