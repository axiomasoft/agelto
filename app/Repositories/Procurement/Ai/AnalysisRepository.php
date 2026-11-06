<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\PageSourceManifest;
use App\Dto\Procurement\Ai\PreparedAnalysisCandidate;
use App\Dto\Procurement\Ai\ReanalysisSource;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\WorkId;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\AnalysisRangeCodec;
use App\Services\Procurement\Process\PayloadReader;
use Illuminate\Support\Facades\DB;

/** Typed canonical rows, deliberately independent of the legacy AnalysisRun model binding. */
final class AnalysisRepository
{
    /** @return list<PreparedAnalysisCandidate> Latest complete text for each unplanned current version. */
    public function preparedBacklog(): array
    {
        $rows = LedgerRow::all(
            "SELECT d.id AS document_id,d.head_generation,d.platform_id,d.procurement_id,v.id AS version_id,v.analysis_generation,
                r.id AS representation_id,encode(r.content_hash,'hex') AS hash,encode(r.preparation_recipe_hash,'hex') AS preparation_hash
             FROM prc_documents d JOIN prc_document_versions v ON v.id=d.current_version_id
             JOIN prc_document_representations r ON r.document_version_id=v.id
             WHERE d.removed_at IS NULL AND v.current_analysis_run_id IS NULL AND r.range_key='complete' AND r.kind='text'
               AND r.id=(SELECT max(s.id) FROM prc_document_representations s WHERE s.document_version_id=v.id AND s.range_key='complete' AND s.kind='text')
             ORDER BY v.id LIMIT 50",
        );

        return array_map(static fn (LedgerRow $row): PreparedAnalysisCandidate => new PreparedAnalysisCandidate(
            documentId: $row->int('document_id'),
            documentVersionId: $row->int('version_id'),
            representationId: $row->int('representation_id'),
            representationHash: new ContentHash($row->string('hash')),
            preparationHash: new ContentHash($row->string('preparation_hash')),
            documentHeadGeneration: $row->int('head_generation'),
            analysisGeneration: $row->int('analysis_generation'),
            platformId: $row->nullableInt('platform_id'),
            procurementId: $row->nullableInt('procurement_id'),
        ), $rows);
    }

    /** Serialize competing backlog producers in Document → Version order before creating the run. */
    public function startPreparedRunLocked(int $documentId, AnalysisRunSnapshot $run): bool
    {
        CallerTransaction::require('Prepared analysis planning');
        $document = LedgerRow::one(
            'SELECT current_version_id,head_generation FROM prc_documents WHERE id=? FOR UPDATE',
            [$documentId],
        );
        $version = LedgerRow::one(
            'SELECT document_id,analysis_generation,current_analysis_run_id FROM prc_document_versions WHERE id=? FOR UPDATE',
            [$run->documentVersionId],
        );
        if ($version === null || $document === null
            || $version->int('document_id') !== $documentId
            || $version->nullableString('current_analysis_run_id') !== null
            || $version->int('analysis_generation') + 1 !== $run->expectedAnalysisGeneration
            || $document->nullableInt('current_version_id') !== $run->documentVersionId
            || $document->int('head_generation') !== $run->expectedDocumentHeadGeneration) {
            return false;
        }
        $this->storeRunLocked($run);

        return true;
    }

    /** Null means the exact command already created its replacement; guards apply to new commands. */
    public function rerunSourceLocked(AnalysisRunSnapshot $previousRun, ProcessUuid $commandRunId): ?ReanalysisSource
    {
        CallerTransaction::require('Manual reanalysis source eligibility');
        $ancestry = LedgerRow::one(
            'SELECT document_id FROM prc_document_versions WHERE id=?',
            [$previousRun->documentVersionId],
        ) ?? throw new \InvalidArgumentException('Missing source version.');
        $documentId = $ancestry->int('document_id');
        // Resolve ancestry without locks, then acquire the same Document → Version → Analysis prefix as finalization.
        $document = LedgerRow::one(
            'SELECT current_version_id,head_generation,platform_id,procurement_id FROM prc_documents WHERE id=? FOR UPDATE',
            [$documentId],
        ) ?? throw new \InvalidArgumentException('Missing source document.');
        $version = LedgerRow::one(
            'SELECT document_id,analysis_generation,current_analysis_run_id FROM prc_document_versions WHERE id=? FOR UPDATE',
            [$previousRun->documentVersionId],
        ) ?? throw new \InvalidArgumentException('Missing source version.');
        LedgerRow::one('SELECT id FROM prc_analysis_runs WHERE id=? FOR UPDATE', [$previousRun->id->value]);
        if ($version->int('document_id') !== $documentId) {
            throw new \InvalidArgumentException('Reanalysis source ancestry changed.');
        }
        $replacement = LedgerRow::one(
            'SELECT document_version_id,supersedes_run_id FROM prc_analysis_runs WHERE id=?',
            [$commandRunId->value],
        );
        if ($replacement !== null) {
            if ($replacement->int('document_version_id') !== $previousRun->documentVersionId
                || $replacement->nullableString('supersedes_run_id') !== $previousRun->id->value) {
                throw new \InvalidArgumentException('Reanalysis command identity conflict.');
            }

            return null;
        }
        if ($version->nullableString('current_analysis_run_id') !== $previousRun->id->value
            || $document->nullableInt('current_version_id') !== $previousRun->documentVersionId
            || $document->int('head_generation') !== $previousRun->expectedDocumentHeadGeneration
            || $this->hasUnresolvedProviderHistory($previousRun->id)) {
            throw new \InvalidArgumentException('Reanalysis requires the current source and resolved provider history.');
        }
        $screening = LedgerRow::one(
            "SELECT w.id FROM prc_process_work w WHERE w.stage='screening' AND w.document_version_id=?
                AND w.input_revision=?",
            [$previousRun->documentVersionId, $previousRun->expectedAnalysisGeneration],
        ) ?? throw new \InvalidArgumentException('Missing original screening work.');
        $screeningWorkId = new WorkId(new ProcessUuid($screening->string('id')));
        if ($this->hasUnfinishedAnalysisWork(runId: $previousRun->id, screeningWorkId: $screeningWorkId)) {
            throw new \InvalidArgumentException('Finish or explicitly cancel the current analysis work first.');
        }
        $platformId = $document->int('platform_id');

        return new ReanalysisSource(
            documentHeadGeneration: $document->int('head_generation'),
            nextAnalysisGeneration: $version->int('analysis_generation') + 1,
            previousScreeningWorkId: $screeningWorkId,
            platformId: $platformId,
            cohortKey: $document->nullableInt('procurement_id') === null ? 'manual' : 'platform:'.$platformId,
        );
    }

    private function hasUnresolvedProviderHistory(ProcessUuid $runId): bool
    {
        return LedgerRow::one(
            "SELECT m.id FROM prc_analysis_llm_requests m JOIN llm_provider_calls c ON c.physical_call_id=m.physical_call_id
             JOIN llm_provider_requests q ON q.logical_request_id=c.logical_request_id
             WHERE m.analysis_run_id=? AND (q.state IN ('sending','unknown') OR c.outcome='unknown') LIMIT 1",
            [$runId->value],
        ) !== null;
    }

    private function hasUnfinishedAnalysisWork(ProcessUuid $runId, WorkId $screeningWorkId): bool
    {
        return LedgerRow::one(
            "SELECT w.id FROM prc_process_work w WHERE w.state NOT IN ('succeeded','failed_item','cancelled','superseded')
             AND (w.id=? OR w.analysis_run_id=? OR w.analysis_window_id IN (SELECT id FROM prc_analysis_windows WHERE run_id=?)) LIMIT 1",
            [$screeningWorkId->value->value, $runId->value, $runId->value],
        ) !== null;
    }

    public function sealSourceLocked(AnalysisRunSnapshot $run, PageSourceManifest $source): void
    {
        CallerTransaction::require('Independent original-page source');
        if ($run->source !== null || $source->representationHash != $run->representationHash
            || LedgerRow::one('SELECT id FROM prc_analysis_llm_requests WHERE analysis_run_id=? LIMIT 1', [$run->id->value]) !== null) {
            throw new \LogicException('Source manifest must precede provider authorization.');
        }
        if (DB::update('UPDATE prc_analysis_runs SET declared_coverage=?::jsonb,declared_coverage_schema_version=2 WHERE id=? AND declared_coverage IS NULL',
            [CanonicalJson::encode(['source' => $source->fields(), 'estimates' => null]), $run->id->value]) !== 1) {
            throw new \LogicException('Source manifest sealing CAS failed.');
        }
    }

    public function forScreening(ScreeningInput $input): AnalysisRunSnapshot
    {
        $row = LedgerRow::one("SELECT r.id FROM prc_document_versions v JOIN prc_analysis_runs r ON r.id=v.current_analysis_run_id
            WHERE v.id=? AND r.document_version_id=v.id AND r.representation_hash=decode(?,'hex') AND r.recipe_hash=decode(?,'hex')
              AND r.expected_analysis_generation=v.analysis_generation", [$input->documentVersionId, $input->representationHash->hex, $input->screeningRecipeHash->hex])
            ?? throw new \InvalidArgumentException('Screening run/input/recipe drift.');

        return $this->getRun(new ProcessUuid($row->string('id')));
    }

    /** Caller holds document + version subject locks, after the full applicable early prefix. */
    public function storeRunLocked(AnalysisRunSnapshot $run): void
    {
        CallerTransaction::require('Analysis run creation');
        $inserted = DB::affectingStatement("INSERT INTO prc_analysis_runs(id,document_version_id,representation_id,representation_hash,recipe_json,recipe_schema_version,recipe_hash,
            generation,expected_document_head_generation,expected_analysis_generation,requested_reason,supersedes_run_id,publication_status,declared_coverage,declared_coverage_schema_version)
            SELECT ?,v.id,r.id,decode(?,'hex'),?::jsonb,?::smallint,decode(?,'hex'),?,?,?,?,?,'unpublished',?::jsonb,?::smallint
            FROM prc_document_versions v JOIN prc_documents d ON d.id=v.document_id
            JOIN prc_document_representations r ON r.document_version_id=v.id
            WHERE v.id=? AND r.id=? AND r.content_hash=decode(?,'hex') AND r.preparation_recipe_hash=decode(?,'hex')
              AND d.current_version_id=v.id AND d.head_generation=? AND v.analysis_generation=?", [
            $run->id->value, $run->representationHash->hex, $run->recipe->json, AnalysisRecipe::CODEC_VERSION, $run->recipe->hash->hex,
            $run->generation, $run->expectedDocumentHeadGeneration, $run->expectedAnalysisGeneration, $run->requestedReason, $run->supersedesRunId?->value,
            $run->source === null ? null : CanonicalJson::encode(['source' => $run->source->fields(), 'estimates' => null]), $run->source === null ? null : 2,
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
            $row->string('requested_reason'), $supersedes === null ? null : new ProcessUuid($supersedes),
            $row->nullableInt('declared_coverage_schema_version') !== 2 ? null : PageSourceManifest::restore(
                CanonicalJson::encode(PayloadReader::json($row->string('declared_coverage'))->object('source')->object)));
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

    public function storeWindowProgressLocked(AnalysisWindowSnapshot $window, ExtractionCheckpoint $checkpoint): void
    {
        CallerTransaction::require('Analysis window progress');
        DB::update(
            'UPDATE prc_analysis_windows SET state=?,checkpoint_version=checkpoint_version+1 WHERE id=?',
            [$checkpoint->pending === [] ? 'complete' : 'partial', $window->id->value],
        );
    }

    /** True means every window is complete and the caller may publish the successor in this transaction. */
    public function completeWindowLocked(AnalysisWindowSnapshot $window): bool
    {
        CallerTransaction::require('Analysis window completion');
        DB::update("UPDATE prc_analysis_windows SET state='complete' WHERE id=?", [$window->id->value]);
        $missing = LedgerRow::one(
            "SELECT count(*) AS count FROM prc_analysis_windows WHERE run_id=? AND state<>'complete'",
            [$window->run->id->value],
        );

        return $missing?->int('count') === 0;
    }

    public function getWindow(ProcessUuid $id): AnalysisWindowSnapshot
    {
        $row = LedgerRow::one("SELECT *,encode(input_hash,'hex') AS input_hex FROM prc_analysis_windows WHERE id=?", [$id->value])
            ?? throw new \InvalidArgumentException('Unknown analysis window.');
        if ($row->int('input_range_schema_version') !== 1 || $row->int('expected_coverage_schema_version') !== 1) {
            throw new \InvalidArgumentException('Unknown window codec; create a new analysis.');
        }
        $range = PayloadReader::json($row->string('input_range'));

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
            AnalysisRangeCodec::restore($range), $coverage->integer('estimate_ordinal'), $anchors);
        if ($window->inputHash->hex !== $row->string('input_hex')) {
            throw new \InvalidArgumentException('Stored window input hash drift.');
        }

        return $window;
    }

    public function windowFor(ExtractionWindowInput $input): AnalysisWindowSnapshot
    {
        $id = LedgerRow::one('SELECT id FROM prc_analysis_windows WHERE run_id=? AND window_key=?',
            [$input->analysisRunId->value, $input->windowKey])?->string('id')
            ?? throw new \InvalidArgumentException('Missing analysis window.');
        $window = $this->getWindow(new ProcessUuid($id));
        if ($window->run->recipe->hash != $input->recipeHash) {
            throw new \InvalidArgumentException('Analysis recipe drift.');
        }

        return $window;
    }
}
