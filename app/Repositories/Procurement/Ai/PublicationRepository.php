<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\PublicationCompleted;
use App\Dto\Procurement\Ai\PublicationProgress;
use App\Dto\Procurement\Ai\RowRange;
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
            'rows_expected' => $estimate->plan->required instanceof RowRange
                ? $estimate->plan->required->end - $estimate->plan->required->start : null,
            'rows_extracted' => count(array_filter($estimate->rows, static fn ($row): bool => $row->row->row_type === RowType::Item)),
            'validation_status' => 'valid', ...$this->provenance($estimate, array_values($chunks), null)]);
        $sectionPosition = $subsectionPosition = null;
        $sectionRows = $subsectionRows = $itemSources = $workTypeRows = [];
        foreach ($estimate->rows as $anchored) {
            $row = $anchored->row;
            $provenance = $this->provenance($estimate, array_map(static fn ($id): string => $id->value, $estimate->rowChunks[$anchored->source_anchor]), $anchored->source_anchor);
            if ($row->row_type === RowType::SectionHeader) {
                $sectionRows[] = ['estimate_id' => $estimateId, 'parent_id' => null, 'position' => $row->row_index,
                    'title' => $row->name, ...$provenance];
                $sectionPosition = $row->row_index;
                $subsectionPosition = null;

                continue;
            }
            if ($row->row_type === RowType::SubsectionHeader) {
                $subsectionRows[] = ['estimate_id' => $estimateId, 'parent_position' => $sectionPosition,
                    'position' => $row->row_index, 'title' => $row->name, ...$provenance];
                $subsectionPosition = $row->row_index;

                continue;
            }
            if ($row->row_type !== RowType::Item) {
                continue;
            }

            $itemSources[] = ['anchored' => $anchored, 'section_position' => $subsectionPosition ?? $sectionPosition, 'provenance' => $provenance];
            if ($row->work_type_name !== null && $row->work_type_name !== '' && ! isset($workTypeRows[$row->work_type_name])) {
                $workTypeRows[$row->work_type_name] = ['estimate_id' => $estimateId, 'position' => $row->row_index, 'title' => $row->work_type_name];
            }
        }

        $sectionColumns = ['estimate_id' => 'int8', 'parent_id' => 'int8', 'position' => 'int4', 'title' => 'text',
            'analysis_provenance' => 'jsonb', 'analysis_provenance_schema_version' => 'int2'];
        $sections = [];
        foreach ($this->bulkInsert('prc_estimate_sections', $sectionColumns, $sectionRows, 'position, id') as $inserted) {
            $sections[$this->returnedInt($inserted, 'position')] = $this->returnedInt($inserted, 'id');
        }
        foreach ($subsectionRows as &$subsectionRow) {
            $subsectionRow['parent_id'] = $subsectionRow['parent_position'] === null ? null : $sections[$subsectionRow['parent_position']];
            unset($subsectionRow['parent_position']);
        }
        unset($subsectionRow);
        foreach ($this->bulkInsert('prc_estimate_sections', $sectionColumns, $subsectionRows, 'position, id') as $inserted) {
            $sections[$this->returnedInt($inserted, 'position')] = $this->returnedInt($inserted, 'id');
        }

        $workTypes = [];
        foreach ($this->bulkInsert('prc_estimate_work_types', ['estimate_id' => 'int8', 'position' => 'int4', 'title' => 'text'],
            array_values($workTypeRows), 'title, id') as $inserted) {
            $workTypes[$this->returnedString($inserted, 'title')] = $this->returnedInt($inserted, 'id');
        }

        $itemColumns = ['estimate_id' => 'int8', 'section_id' => 'int8', 'work_type_id' => 'int8', 'row_index' => 'int4',
            'code' => 'text', 'name' => 'text', 'unit' => 'text', 'unit_base' => 'text', 'unit_multiplier' => 'numeric',
            'quantity' => 'numeric', 'unit_price_base' => 'numeric', 'unit_price_curr' => 'numeric',
            'total_price_base' => 'numeric', 'total_price_curr' => 'numeric', 'index_code' => 'text',
            'index_value' => 'numeric', 'source_position_number' => 'text', 'attributes' => 'jsonb', 'quality' => 'jsonb',
            'analysis_provenance' => 'jsonb', 'analysis_provenance_schema_version' => 'int2'];
        $itemRows = $resourceSources = [];
        foreach ($itemSources as $source) {
            $anchored = $source['anchored'];
            $row = $anchored->row;
            $attributes = EstimateAttributes::itemAttributes($row);
            foreach (['attributes', 'quality'] as $key) {
                if ($attributes[$key] !== null) {
                    $attributes[$key] = CanonicalJson::encode($attributes[$key]);
                }
            }
            $itemRows[] = ['estimate_id' => $estimateId,
                'section_id' => $source['section_position'] === null ? null : $sections[$source['section_position']],
                'work_type_id' => $row->work_type_name === null || $row->work_type_name === '' ? null : $workTypes[$row->work_type_name],
                'row_index' => $row->row_index, ...$attributes, ...$source['provenance']];
            foreach ($row->resources as $resource) {
                $resourceSources[] = ['row_index' => $row->row_index, 'resource' => $resource, 'provenance' => $source['provenance']];
            }
        }
        $items = [];
        foreach (array_chunk($itemRows, 5000) as $chunk) {
            foreach ($this->bulkInsert('prc_estimate_items', $itemColumns, $chunk, 'row_index, id') as $inserted) {
                $items[$this->returnedInt($inserted, 'row_index')] = $this->returnedInt($inserted, 'id');
            }
        }

        $resourceColumns = ['item_id' => 'int8', 'resource_type' => 'text', 'code' => 'text', 'name' => 'text',
            'unit' => 'text', 'unit_base' => 'text', 'unit_multiplier' => 'numeric', 'quantity' => 'numeric',
            'price_base' => 'numeric', 'price_curr' => 'numeric', 'index_code' => 'text', 'index_value' => 'numeric',
            'analysis_provenance' => 'jsonb', 'analysis_provenance_schema_version' => 'int2'];
        $resourceRows = [];
        foreach ($resourceSources as $source) {
            $resource = $source['resource'];
            $resourceRows[] = ['item_id' => $items[$source['row_index']], 'resource_type' => $resource->resource_type->value,
                'code' => $resource->code, 'name' => $resource->name, 'unit' => $resource->unit,
                ...UnitNormalizer::normalize($resource->unit), 'quantity' => $resource->quantity,
                'price_base' => $resource->price_base, 'price_curr' => $resource->price_curr,
                'index_code' => $resource->index_code, 'index_value' => $resource->index_value, ...$source['provenance']];
        }
        foreach (array_chunk($resourceRows, 5000) as $chunk) {
            $this->bulkInsert('prc_estimate_resources', $resourceColumns, $chunk);
        }

        $totalRows = [];
        foreach ($estimate->totals as $position => $node) {
            $totalRows[] = ['estimate_id' => $estimateId,
                'position' => $position, 'label' => $node->label, 'amount' => $node->value, 'source_node_id' => $node->node_id, 'source_parent_node_id' => $node->parent_id,
                ...$this->provenance($estimate, array_map(static fn ($id): string => $id->value, $estimate->totalChunks[$node->node_id]), $node->source_anchor)];
        }
        $nodes = [];
        foreach ($this->bulkInsert('prc_estimate_totals', ['estimate_id' => 'int8', 'position' => 'int4', 'label' => 'text',
            'amount' => 'numeric', 'source_node_id' => 'text', 'source_parent_node_id' => 'text',
            'analysis_provenance' => 'jsonb', 'analysis_provenance_schema_version' => 'int2'], $totalRows, 'source_node_id, id') as $inserted) {
            $nodes[$this->returnedString($inserted, 'source_node_id')] = $this->returnedInt($inserted, 'id');
        }
        $childIds = $parentIds = [];
        foreach ($estimate->totals as $node) {
            if ($node->parent_id !== null) {
                $childIds[] = $nodes[$node->node_id];
                $parentIds[] = $nodes[$node->parent_id];
            }
        }
        if ($childIds !== []) {
            DB::update('UPDATE prc_estimate_totals AS t SET parent_id = v.parent_id FROM UNNEST(?::int8[], ?::int8[]) AS v(id, parent_id) WHERE t.id = v.id',
                [$this->pgIntArray($childIds), $this->pgIntArray($parentIds)]);
        }
    }

    /**
     * @param  array<string, 'int2'|'int4'|'int8'|'text'|'numeric'|'jsonb'>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return list<object>
     */
    private function bulkInsert(string $table, array $columns, array $rows, ?string $returning = null): array
    {
        if ($rows === []) {
            return [];
        }

        $names = array_keys($columns);
        $bindings = $arrays = [];
        foreach ($columns as $name => $type) {
            $values = array_column($rows, $name);
            $bindings[] = match ($type) {
                'int2', 'int4', 'int8' => $this->pgIntArray($values),
                'text' => $this->pgTextArray($values),
                'numeric' => $this->pgNumericArray($values),
                'jsonb' => $this->pgJsonbArray($values),
            };
            $arrays[] = "?::{$type}[]";
        }
        $list = implode(', ', $names);
        $select = implode(', ', array_map(static fn (string $name): string => "v.{$name}", $names));
        $sql = "INSERT INTO {$table} ({$list}, created_at, updated_at) SELECT {$select}, clock_timestamp(), clock_timestamp()"
            .' FROM UNNEST('.implode(', ', $arrays).") AS v({$list})";
        if ($returning !== null) {
            $result = [];
            foreach (DB::select($sql." RETURNING {$returning}", $bindings) as $row) {
                if (! is_object($row)) {
                    throw new \UnexpectedValueException('Expected object from INSERT RETURNING.');
                }
                $result[] = $row;
            }

            return $result;
        }

        DB::statement($sql, $bindings);

        return [];
    }

    /** @param list<mixed> $values */
    private function pgIntArray(array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if ($value === null) {
                $encoded[] = 'NULL';

                continue;
            }
            if (! is_int($value)) {
                throw new \InvalidArgumentException('Expected integer array value.');
            }
            $encoded[] = (string) $value;
        }

        return '{'.implode(',', $encoded).'}';
    }

    /** @param list<mixed> $values */
    private function pgTextArray(array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if ($value === null) {
                $encoded[] = 'NULL';

                continue;
            }
            if (! is_string($value)) {
                throw new \InvalidArgumentException('Expected text array value.');
            }
            $clean = str_replace("\0", '', $value);
            $encoded[] = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $clean).'"';
        }

        return '{'.implode(',', $encoded).'}';
    }

    /** @param list<mixed> $values */
    private function pgNumericArray(array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if ($value === null) {
                $encoded[] = 'NULL';

                continue;
            }
            if (! is_string($value) || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) {
                throw new \InvalidArgumentException('Expected decimal string array value.');
            }
            $encoded[] = $value;
        }

        return '{'.implode(',', $encoded).'}';
    }

    /** @param list<mixed> $values */
    private function pgJsonbArray(array $values): string
    {
        foreach ($values as &$value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || ! json_validate($value)) {
                throw new \InvalidArgumentException('Expected JSON array value.');
            }
            if (str_contains($value, '\\u0000')) {
                $value = CanonicalJson::encode($this->removeNullBytes(json_decode($value, flags: JSON_THROW_ON_ERROR)));
            }
        }
        unset($value);

        return $this->pgTextArray($values);
    }

    private function removeNullBytes(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }
        if (is_array($value)) {
            return array_map($this->removeNullBytes(...), $value);
        }
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->removeNullBytes($item);
            }
        }

        return $value;
    }

    private function returnedInt(object $row, string $column): int
    {
        $value = $row->{$column} ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1 && (string) (int) $value === $value) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("Expected integer RETURNING column {$column}.");
    }

    private function returnedString(object $row, string $column): string
    {
        $value = $row->{$column} ?? null;
        if (! is_string($value)) {
            throw new \UnexpectedValueException("Expected text RETURNING column {$column}.");
        }

        return $value;
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
