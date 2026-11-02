<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\PublicationCompleted;
use App\Dto\Procurement\Ai\PublicationProgress;
use App\Dto\Procurement\Ai\StitchedEstimate;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Estimate\RowType;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Analyzers\Estimate\EstimateAttributes;
use App\Services\Procurement\Analyzers\Estimate\UnitNormalizer;
use App\Services\Procurement\Extraction\EstimatePublisher;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use Illuminate\Support\Facades\DB;

/** No transaction of its own. WorkFinalizer owns result, pointer, journal, outbox and slot release. */
final readonly class PublicationRepository implements StageResultRepository
{
    public function __construct(private EstimatePublisher $publisher, private AnalysisRepository $analyses,
        private AnalysisResultProjectionRepository $projection) {}

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        CallerTransaction::require('Publication progress');
        if (! $input instanceof PublicationInput || ! $progress instanceof PublicationProgress) {
            throw new \InvalidArgumentException('Publication progress binding mismatch.');
        }
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Validated estimate publication');
        if (! $input instanceof PublicationInput || ! $result instanceof PublicationCompleted || $this->publisher->result($input) != $result) {
            throw new PublicationBlocked(BlockedReason::ChunkConflict);
        }
        $run = LedgerRow::one("SELECT r.*,d.procurement_id,d.platform_id,encode(publication_digest,'hex') AS digest
            FROM prc_analysis_runs r JOIN prc_document_versions v ON v.id=r.document_version_id JOIN prc_documents d ON d.id=v.document_id WHERE r.id=?", [$input->analysisRunId->value])
            ?? throw new \LogicException('Publication run missing.');
        if ($run->string('publication_status') === 'published') {
            if ($run->nullableString('digest') !== $result->digest->hex) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }

            return [];
        }
        foreach ($this->publisher->estimates($input) as $estimate) {
            $this->storeEstimate($estimate, $run);
        }
        if (DB::update('UPDATE prc_document_versions SET published_analysis_run_id=? WHERE id=? AND current_analysis_run_id=? AND analysis_generation=?',
            [$input->analysisRunId->value, $run->int('document_version_id'), $input->analysisRunId->value, $run->int('expected_analysis_generation')]) !== 1
            || DB::update("UPDATE prc_analysis_runs SET publication_status='published',publication_digest=decode(?,'hex') WHERE id=? AND publication_status='unpublished' AND generation=?",
                [$result->digest->hex, $input->analysisRunId->value, $input->resultGeneration]) !== 1) {
            throw new PublicationBlocked(BlockedReason::ChunkConflict);
        }

        $this->projection->extraction($this->analyses->getRun($input->analysisRunId), $result->digest);

        return [];
    }

    private function storeEstimate(StitchedEstimate $estimate, LedgerRow $run): void
    {
        $now = DB::raw('clock_timestamp()');
        $base = ['created_at' => $now, 'updated_at' => $now];
        $chunks = [];
        foreach ([...array_values($estimate->rowChunks), ...array_values($estimate->totalChunks)] as $ids) {
            foreach ($ids as $id) {
                $chunks[$id->value] = $id->value;
            }
        }
        $estimateId = DB::table('prc_estimates')->insertGetId([...$base, 'uuid' => ListingStoreRepository::id('published-estimate:'.$run->string('id').':'.$estimate->plan->estimateOrdinal)->value,
            'analysis_id' => null, 'analysis_run_id' => $run->string('id'), 'document_version_id' => $run->int('document_version_id'),
            'procurement_id' => $run->nullableInt('procurement_id'), 'platform_id' => $run->nullableInt('platform_id'),
            'ordinal' => $estimate->plan->estimateOrdinal, 'currency' => $estimate->plan->run->recipe->currency,
            'rows_expected' => $estimate->plan->required->end - $estimate->plan->required->start,
            'rows_extracted' => count(array_filter($estimate->rows, static fn ($row): bool => $row->row->row_type === RowType::Item)),
            'validation_status' => 'valid', ...$this->provenance($estimate, array_values($chunks), null)]);
        $section = $subsection = null;
        foreach ($estimate->rows as $anchored) {
            $row = $anchored->row;
            $provenance = $this->provenance($estimate, array_map(static fn ($id): string => $id->value, $estimate->rowChunks[$anchored->source_anchor]), $anchored->source_anchor);
            if ($row->row_type === RowType::SectionHeader || $row->row_type === RowType::SubsectionHeader) {
                $id = DB::table('prc_estimate_sections')->insertGetId([...$base, ...$provenance, 'estimate_id' => $estimateId,
                    'parent_id' => $row->row_type === RowType::SubsectionHeader ? $section : null, 'position' => $row->row_index, 'title' => $row->name]);
                if ($row->row_type === RowType::SectionHeader) {
                    $section = $id;
                    $subsection = null;
                } else {
                    $subsection = $id;
                }

                continue;
            }
            if ($row->row_type !== RowType::Item) {
                continue;
            }
            $attributes = EstimateAttributes::itemAttributes($row);
            foreach (['attributes', 'quality'] as $key) {
                if ($attributes[$key] !== null) {
                    $attributes[$key] = CanonicalJson::encode($attributes[$key]);
                }
            }
            $workType = null;
            if ($row->work_type_name !== null) {
                $workType = DB::table('prc_estimate_work_types')->where('estimate_id', $estimateId)->where('title', $row->work_type_name)->value('id');
                $workType ??= DB::table('prc_estimate_work_types')->insertGetId([...$base, 'estimate_id' => $estimateId, 'position' => $row->row_index, 'title' => $row->work_type_name]);
            }
            $item = DB::table('prc_estimate_items')->insertGetId([...$base, ...$attributes, ...$provenance, 'estimate_id' => $estimateId,
                'section_id' => $subsection ?? $section, 'work_type_id' => $workType, 'row_index' => $row->row_index]);
            foreach ($row->resources as $ordinal => $resource) {
                $unit = UnitNormalizer::normalize($resource->unit);
                DB::table('prc_estimate_resources')->insert([...$base, ...$provenance, 'item_id' => $item, 'resource_type' => $resource->resource_type->value,
                    'name' => $resource->name, 'code' => $resource->code, 'unit' => $resource->unit, ...$unit, 'quantity' => $resource->quantity,
                    'price_base' => $resource->price_base, 'price_curr' => $resource->price_curr, 'index_code' => $resource->index_code, 'index_value' => $resource->index_value]);
            }
        }
        $nodes = [];
        foreach ($estimate->totals as $position => $node) {
            $nodes[$node->node_id] = DB::table('prc_estimate_totals')->insertGetId([...$base, 'estimate_id' => $estimateId,
                'position' => $position, 'label' => $node->label, 'amount' => $node->value, 'source_node_id' => $node->node_id, 'source_parent_node_id' => $node->parent_id,
                ...$this->provenance($estimate, array_map(static fn ($id): string => $id->value, $estimate->totalChunks[$node->node_id]), $node->source_anchor)]);
        }
        foreach ($estimate->totals as $node) {
            if ($node->parent_id !== null) {
                DB::table('prc_estimate_totals')->where('id', $nodes[$node->node_id])->update(['parent_id' => $nodes[$node->parent_id]]);
            }
        }
    }

    /**
     * @param  list<string>  $chunks
     * @return array{analysis_provenance:string,analysis_provenance_schema_version:int}
     */
    private function provenance(StitchedEstimate $estimate, array $chunks, ?string $anchor): array
    {
        return ['analysis_provenance' => CanonicalJson::encode(['analysis_run_id' => $estimate->plan->run->id->value,
            'document_version_id' => $estimate->plan->run->documentVersionId, 'recipe_hash' => $estimate->plan->run->recipe->hash->hex,
            'representation_hash' => $estimate->plan->run->representationHash->hex, 'chunk_ids' => $chunks, 'source_anchor' => $anchor]), 'analysis_provenance_schema_version' => 1];
    }
}
