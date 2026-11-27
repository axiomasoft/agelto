<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Extraction\DiscoveryCheckpoint;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowPlan;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\Pipeline\AnalysisRangeCodec;
use App\Services\Procurement\Extraction\Pipeline\WindowPlanner;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;
use Illuminate\Support\Facades\DB;

/** Required source ranges are declared before generation, never inferred from successful chunks. */
final readonly class AnalysisPlanRepository
{
    public function __construct(private AnalysisRepository $analyses) {}

    /** Called only after accepted discovery progress and its receipt consumption committed. */
    public function sealDiscoveryLocked(AnalysisRunSnapshot $run, DiscoveryCheckpoint $discovery): void
    {
        CallerTransaction::require('Accepted source discovery');
        $source = $run->source ?? throw new \InvalidArgumentException('Independent source required.');
        $discovery->assertPartition($source);
        if ($discovery->pending !== [] || $source->representationHash != $run->representationHash) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        $assignments = $discovery->assignments;
        ksort($assignments, SORT_NUMERIC);
        $groups = [];
        $previous = 1;
        foreach ($assignments as $page => $ordinal) {
            if (($page === 0 && $ordinal !== 1) || $ordinal < $previous || $ordinal > $previous + 1) {
                throw new PublicationBlocked(BlockedReason::SemanticReviewRequired);
            }
            $groups[$ordinal][] = $page;
            $previous = $ordinal;
        }
        $declarations = [];
        $windows = [];
        foreach ($groups as $ordinal => $pages) {
            $required = new PageRange($pages[0], $pages[count($pages) - 1] + 1);
            $plan = (new WindowPlanner)->plan($run, $ordinal, $required, $source->anchorsFor($required));
            $windows = [...$windows, ...$plan->windows];
            $declarations[] = ['estimate_ordinal' => $ordinal, 'required' => $required->fields(),
                'windows' => array_map(static fn ($w): string => $w->id->value, $plan->windows)];
        }
        $json = CanonicalJson::encode(['source' => $source->fields(), 'estimates' => $declarations]);
        $row = LedgerRow::one('SELECT declared_coverage::text AS coverage,declared_coverage_schema_version AS version FROM prc_analysis_runs WHERE id=?', [$run->id->value]);
        if ($row === null || $row->int('version') !== 2) {
            throw new \InvalidArgumentException('Independent page source was not sealed before the first call.');
        }
        $old = PayloadReader::json($row->string('coverage'));
        if (! $old->isNull('estimates')) {
            if (CanonicalJson::encode($old->object) !== $json) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }

            return;
        }
        if ($declarations === [] || count($declarations) > 128 || count($windows) > 4096) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        foreach ($windows as $window) {
            $this->analyses->storeWindowLocked($window);
        }
        if (DB::update("UPDATE prc_analysis_runs SET declared_coverage=?::jsonb WHERE id=? AND declared_coverage_schema_version=2 AND declared_coverage->'estimates'='null'::jsonb", [$json, $run->id->value]) !== 1) {
            throw new \LogicException('Source discovery sealing CAS failed.');
        }
    }

    /**
     * Caller owns the document/version/run subjects. All windows must already be persisted.
     *
     * @param  list<AnalysisWindowPlan>  $plans
     */
    public function sealLocked(AnalysisRunSnapshot $run, array $plans): void
    {
        CallerTransaction::require('Analysis source coverage declaration');
        if ($plans === [] || count($plans) > 128) {
            throw new \InvalidArgumentException('A bounded independent source plan is required.');
        }
        $declarations = [];
        foreach ($plans as $plan) {
            if ($plan->run != $run || isset($declarations[$plan->estimateOrdinal])) {
                throw new \InvalidArgumentException('Conflicting source plan identity.');
            }
            foreach ($plan->windows as $window) {
                if ($this->analyses->getWindow($window->id) != $window) {
                    throw new \InvalidArgumentException('Source plan window drift.');
                }
            }
            $declarations[$plan->estimateOrdinal] = ['estimate_ordinal' => $plan->estimateOrdinal,
                'required' => $plan->required->fields(), 'windows' => array_map(static fn ($w): string => $w->id->value, $plan->windows)];
        }
        ksort($declarations);
        $json = CanonicalJson::encode(['estimates' => array_values($declarations)]);
        $row = LedgerRow::one('SELECT declared_coverage,declared_coverage_schema_version FROM prc_analysis_runs WHERE id=?', [$run->id->value]);
        if ($row !== null && $row->nullableString('declared_coverage') !== null) {
            if ($row->int('declared_coverage_schema_version') !== 1 || CanonicalJson::encode(json_decode($row->string('declared_coverage'), flags: JSON_THROW_ON_ERROR)) !== $json) {
                throw new \InvalidArgumentException('Declared source coverage is immutable.');
            }

            return;
        }
        if (LedgerRow::one('SELECT 1 AS found FROM prc_analysis_llm_requests WHERE analysis_run_id=? LIMIT 1', [$run->id->value]) !== null
            || DB::update('UPDATE prc_analysis_runs SET declared_coverage=?::jsonb,declared_coverage_schema_version=1 WHERE id=? AND declared_coverage IS NULL', [$json, $run->id->value]) !== 1) {
            throw new \InvalidArgumentException('Source coverage must be sealed before the first provider operation.');
        }
    }

    /** @return non-empty-list<AnalysisWindowPlan> */
    public function plans(AnalysisRunSnapshot $run): array
    {
        $row = LedgerRow::one('SELECT declared_coverage,declared_coverage_schema_version FROM prc_analysis_runs WHERE id=?', [$run->id->value]);
        if ($row === null || $row->nullableString('declared_coverage') === null || ! in_array($row->int('declared_coverage_schema_version'), [1, 2], true)) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        $data = PayloadReader::json($row->string('declared_coverage'));
        $pageSource = $row->int('declared_coverage_schema_version') === 2;
        $data->keys($pageSource ? ['source', 'estimates'] : ['estimates']);
        if ($pageSource && ($run->source === null || CanonicalJson::encode($run->source->fields()) !== CanonicalJson::encode($data->object('source')->object))) {
            throw new PublicationBlocked(BlockedReason::InvalidRecipeConfiguration);
        }
        $plans = [];
        $seen = [];
        foreach ($data->list('estimates') as $entry) {
            if (! $entry instanceof \stdClass) {
                throw new \InvalidArgumentException('Invalid source estimate declaration.');
            }
            $estimate = new PayloadReader($entry);
            $estimate->keys(['estimate_ordinal', 'required', 'windows']);
            $range = $estimate->object('required');
            $required = AnalysisRangeCodec::restore($range);
            if ($pageSource !== ($required instanceof PageRange)) {
                throw new PublicationBlocked(BlockedReason::InvalidRecipeConfiguration);
            }
            $windows = [];
            foreach ($estimate->list('windows') as $id) {
                if (! is_string($id) || isset($seen[$id]) || count($seen) >= 4096) {
                    throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
                }
                $seen[$id] = true;
                $windows[] = $this->analyses->getWindow(new ProcessUuid($id));
            }
            $plans[] = new AnalysisWindowPlan($run, $estimate->integer('estimate_ordinal'), $required, $windows);
        }
        $count = LedgerRow::one('SELECT count(*) AS count FROM prc_analysis_windows WHERE run_id=?', [$run->id->value])?->int('count');
        if ($plans === [] || count($plans) > 128 || count($seen) !== $count) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        if ($pageSource) {
            $cursor = 0;
            foreach ($plans as $i => $plan) {
                if ($plan->estimateOrdinal !== $i + 1 || $plan->required->start !== $cursor) {
                    throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
                }
                $cursor = $plan->required->end;
            }
            if ($cursor !== $run->source->pageCount) {
                throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
            }
        }

        return $plans;
    }
}
