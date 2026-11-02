<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\PayloadReader;
use Illuminate\Support\Facades\DB;

/** Typed canonical rows, deliberately independent of the legacy AnalysisRun model binding. */
final class AnalysisRepository
{
    /** Caller holds document + version subject locks, after the full applicable early prefix. */
    public function storeRunLocked(AnalysisRunSnapshot $run): void
    {
        CallerTransaction::require('Analysis run creation');
        $inserted = DB::affectingStatement("INSERT INTO prc_analysis_runs(id,document_version_id,representation_id,representation_hash,recipe_json,recipe_schema_version,recipe_hash,
            generation,expected_document_head_generation,expected_analysis_generation,requested_reason,supersedes_run_id,publication_status)
            SELECT ?,v.id,r.id,decode(?,'hex'),?::jsonb,1,decode(?,'hex'),?,?,?,?,?,'unpublished'
            FROM prc_document_versions v JOIN prc_documents d ON d.id=v.document_id
            JOIN prc_document_representations r ON r.document_version_id=v.id
            WHERE v.id=? AND r.id=? AND r.content_hash=decode(?,'hex') AND r.preparation_recipe_hash=decode(?,'hex')
              AND d.current_version_id=v.id AND d.head_generation=? AND v.analysis_generation=?", [
            $run->id->value, $run->representationHash->hex, $run->recipe->json, $run->recipe->hash->hex,
            $run->generation, $run->expectedDocumentHeadGeneration, $run->expectedAnalysisGeneration, $run->requestedReason, $run->supersedesRunId?->value,
            $run->documentVersionId, $run->representationId, $run->representationHash->hex, $run->recipe->preparationHash->hex,
            $run->expectedDocumentHeadGeneration, $run->expectedAnalysisGeneration - 1,
        ]);
        if ($inserted !== 1) {
            throw new \InvalidArgumentException('Analysis representation/input/head drift.');
        }
        DB::update('UPDATE prc_document_versions SET analysis_generation=?,current_analysis_run_id=? WHERE id=?', [
            $run->expectedAnalysisGeneration, $run->id->value, $run->documentVersionId,
        ]);
    }

    public function getRun(ProcessUuid $id): AnalysisRunSnapshot
    {
        $row = LedgerRow::one("SELECT *,encode(representation_hash,'hex') AS representation_hex,encode(recipe_hash,'hex') AS recipe_hex FROM prc_analysis_runs WHERE id=?", [$id->value])
            ?? throw new \InvalidArgumentException('Unknown canonical analysis run.');
        $recipe = AnalysisRecipe::restore($row->string('recipe_json'), $row->int('recipe_schema_version'));
        if ($recipe->hash->hex !== $row->string('recipe_hex')) {
            throw new \InvalidArgumentException('Stored analysis recipe hash mismatch.');
        }
        $supersedes = $row->nullableString('supersedes_run_id');

        return new AnalysisRunSnapshot($id, $row->int('document_version_id'), $row->int('representation_id'), new ContentHash($row->string('representation_hex')),
            $recipe, $row->int('generation'), $row->int('expected_document_head_generation'), $row->int('expected_analysis_generation'),
            $row->string('requested_reason'), $supersedes === null ? null : new ProcessUuid($supersedes));
    }

    public function storeWindowLocked(AnalysisWindowSnapshot $window): void
    {
        CallerTransaction::require('Analysis window creation');
        if ($this->getRun($window->run->id) != $window->run) {
            throw new \InvalidArgumentException('Analysis run/representation drift.');
        }
        DB::insert("INSERT INTO prc_analysis_windows(id,run_id,window_key,input_hash,input_range,input_range_schema_version,expected_coverage,expected_coverage_schema_version,state,checkpoint_version)
            VALUES (?,?,?,decode(?,'hex'),?::jsonb,1,?::jsonb,1,'pending',0)", [
            $window->id->value, $window->run->id->value, $window->windowKey, $window->inputHash->hex,
            CanonicalJson::encode($window->range->fields()), CanonicalJson::encode(['estimate_ordinal' => $window->estimateOrdinal, 'anchors' => $window->sourceAnchors]),
        ]);
    }

    public function getWindow(ProcessUuid $id): AnalysisWindowSnapshot
    {
        $row = LedgerRow::one("SELECT *,encode(input_hash,'hex') AS input_hex FROM prc_analysis_windows WHERE id=?", [$id->value])
            ?? throw new \InvalidArgumentException('Unknown analysis window.');
        if ($row->int('input_range_schema_version') !== 1 || $row->int('expected_coverage_schema_version') !== 1) {
            throw new \InvalidArgumentException('Unknown window codec; create a new analysis.');
        }
        $range = PayloadReader::json($row->string('input_range'));
        $range->keys(['start', 'end']);
        $coverage = PayloadReader::json($row->string('expected_coverage'));
        $coverage->keys(['estimate_ordinal', 'anchors']);
        $anchors = [];
        foreach (get_object_vars($coverage->object('anchors')->object) as $anchor => $page) {
            if (! is_string($anchor) || ! is_int($page)) {
                throw new \InvalidArgumentException('Invalid stored anchor.');
            }
            $anchors[$anchor] = $page;
        }
        $window = new AnalysisWindowSnapshot($id, $this->getRun(new ProcessUuid($row->string('run_id'))), $row->string('window_key'),
            new RowRange($range->integer('start'), $range->integer('end')), $coverage->integer('estimate_ordinal'), $anchors);
        if ($window->inputHash->hex !== $row->string('input_hex')) {
            throw new \InvalidArgumentException('Stored window input hash drift.');
        }

        return $window;
    }
}
