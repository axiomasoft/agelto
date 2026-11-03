<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\MetricMeasurement;
use App\Dto\Procurement\Process\PlatformMetricsSnapshot;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Dto\Procurement\Process\WorkStateCount;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\MeasurementState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProjectionRegistry;

/** Read-only app integration: existing coverage receipts and replayable projection, no new writer. */
final readonly class PlatformMetricsReadRepository
{
    public function __construct(
        private PlatformRegistry $platforms,
        private ProjectionRegistry $registry,
        private ProjectionRepository $projections,
        private ProcessPolicy $policy,
    ) {}

    public function read(PlatformCode $platform): PlatformMetricsSnapshot
    {
        $this->platforms->get($platform);
        $now = new DbInstant(new \DateTimeImmutable((LedgerRow::one('SELECT clock_timestamp()::text AS measured_at')
            ?? throw new \LogicException('Database clock unavailable.'))->string('measured_at')));
        $coverage = $this->coverage($platform);
        $streams = [];
        foreach ($coverage as $row) {
            $streams[$row->string('stream')][] = $row;
        }
        $partitions = $this->partitions($platform);
        $closed = $partitions->int('closed');
        // Every fresh stream must have coverage; a fast stream cannot mask another stream's gap.
        $through = null;
        $gap = false;
        foreach ($streams as $intervals) {
            $cursor = $intervals[0]->string('start');
            $verified = null;
            foreach ($intervals as $interval) {
                if (! $interval->bool('complete') || $interval->string('start') > $cursor) {
                    continue;
                }
                if ($interval->string('finish') >= $cursor) {
                    $cursor = (new \DateTimeImmutable($interval->string('finish')))->modify('+1 day')->format('Y-m-d');
                    $verified = $interval->string('finish');
                }
            }
            if ($verified === null) {
                $gap = true;
            } elseif ($through === null || $verified < $through) {
                $through = $verified;
            }
        }
        $through = $gap ? null : $through;
        $projection = $this->projections->activeSnapshot('work_transitions');
        $state = $projection->state;
        $lag = $this->projectionLag($platform);
        $matching = [];
        foreach ($projection->totals as $total) {
            // Exact tuple component, not prefix matching (e.g. a vs ab).
            if (str_starts_with($total->dimensionKey, 'v1;platform='.$platform->value.';')) {
                $matching[] = $total;
            }
        }
        $modes = $this->controlModes($platform);
        $paused = array_keys(array_filter($modes, static fn (ControlMode $mode): bool => $mode === ControlMode::Paused));
        $control = $paused !== [] ? ControlMode::Paused : (count($modes) === 6 ? ControlMode::Enabled : null);

        return new PlatformMetricsSnapshot(
            $platform, $now, $through,
            $through === null
                ? new MetricMeasurement(MeasurementState::Unknown, null, $streams === [] ? 'no_data' : 'coverage_gap')
                // Existing coverage records source calendar DAYS, not their timezone/UTC boundaries.
                // closesAt is a later stabilization deadline; using it would understate lag.
                : new MetricMeasurement(MeasurementState::Unknown, null, 'coverage_day_precision'),
            $closed === 0 ? new MetricMeasurement(MeasurementState::Unknown, null, 'no_closed_partitions')
                : new MetricMeasurement(MeasurementState::Known, $partitions->int('complete') / $closed),
            $closed, $partitions->int('collecting'),
            $state === null ? new MetricMeasurement(MeasurementState::Unknown, null, 'projection_unavailable')
                : ($state->catalogHash->hex !== $this->registry->catalogHash($this->registry->get('work_transitions'))->hex
                    ? new MetricMeasurement(MeasurementState::Unknown, null, 'catalog_mismatch')
                    : new MetricMeasurement(MeasurementState::Known, $lag)),
            $state, $matching, $paused, $this->workCounts($platform),
            $this->dueLag($platform, StageCode::Detail), $this->dueLag($platform, StageCode::StatusRefresh),
            $control,
        );
    }

    /**
     * Same per-run/window completeness predicate as ListingCoverageRepository, batched across streams.
     * Page/pass/split receipts alone cannot close a window. Archive never advances fresh coverage.
     *
     * @return list<LedgerRow>
     */
    private function coverage(PlatformCode $platform): array
    {
        return LedgerRow::all("SELECT b.partition_payload->>'streamKey' AS stream,
                b.partition_payload->>'windowStart' AS start, b.partition_payload->>'windowEnd' AS finish,
                max(b.partition_payload->>'closesAt') AS closes_at,
                bool_and(b.terminal_proof IS NOT NULL) AND bool_or(b.terminal_proof->>'outcome'='complete') AND
                  NOT bool_or(COALESCE(b.terminal_proof->>'outcome' IN ('observed','incomplete'),true)) AS complete
            FROM prc_crawl_blocks b JOIN prc_crawl_runs c ON c.id=b.crawl_run_id
            JOIN prc_registries r ON r.id=(b.partition_payload->>'registryId')::bigint
            JOIN prc_platforms p ON p.id=r.platform_id
            WHERE p.code=? AND c.listing_kind='fresh' AND jsonb_exists(b.partition_payload, 'streamKey')
            GROUP BY c.id, b.partition_payload->>'streamKey', b.partition_payload->>'windowStart', b.partition_payload->>'windowEnd'
            ORDER BY stream, start, finish", [$platform->value]);
    }

    private function partitions(PlatformCode $platform): LedgerRow
    {
        // Only the terminal leaf proof counts: page/pass/split are traversal, not closed partitions.
        return LedgerRow::one("SELECT count(*) FILTER (WHERE b.terminal_proof IS NULL) AS collecting,
                count(*) FILTER (WHERE b.terminal_proof->>'outcome' IN ('complete','incomplete')
                  AND t.occurred_at>=clock_timestamp()-interval '24 hours'
                  AND (b.partition_payload->>'closesAt')::timestamptz<=clock_timestamp()) AS closed,
                count(*) FILTER (WHERE b.terminal_proof->>'outcome'='complete'
                  AND t.occurred_at>=clock_timestamp()-interval '24 hours'
                  AND (b.partition_payload->>'closesAt')::timestamptz<=clock_timestamp()) AS complete
            FROM prc_crawl_blocks b
            JOIN prc_registries r ON r.id=(b.partition_payload->>'registryId')::bigint
            JOIN prc_platforms p ON p.id=r.platform_id
            LEFT JOIN prc_process_work w ON w.crawl_block_id=b.id
            LEFT JOIN prc_process_transitions t ON t.work_id=w.id AND t.new_state='succeeded'
            WHERE p.code=?", [$platform->value]) ?? throw new \LogicException('Partition aggregate unavailable.');
    }

    private function projectionLag(PlatformCode $platform): int
    {
        $row = LedgerRow::one("SELECT COALESCE(floor(extract(epoch FROM clock_timestamp()-min(t.occurred_at))),0)::bigint AS lag
            FROM prc_process_transitions t JOIN prc_process_work w ON w.id=t.work_id
            WHERE (w.cohort_key=? OR w.platform_id=(SELECT id FROM prc_platforms WHERE code=?))
              AND NOT EXISTS (SELECT 1 FROM prc_process_projection_receipts r
              WHERE r.projector_key='work_transitions' AND r.event_id=t.event_id)", ['platform:'.$platform->value, $platform->value]);

        return max(0, $row?->int('lag') ?? 0);
    }

    /** @return list<WorkStateCount> */
    private function workCounts(PlatformCode $platform): array
    {
        return array_map(static fn (LedgerRow $row): WorkStateCount => new WorkStateCount(
            StageCode::from($row->string('stage')), WorkState::from($row->string('state')),
            DeferralReason::tryFrom($row->nullableString('reason_code') ?? '')
                ?? BlockedReason::tryFrom($row->nullableString('reason_code') ?? ''), $row->int('n'),
        ), LedgerRow::all("SELECT stage, state, reason_code, count(*) AS n FROM prc_process_work
            WHERE (cohort_key=? OR platform_id=(SELECT id FROM prc_platforms WHERE code=?))
              AND state IN ('ready','admitted','running','deferred','blocked')
            GROUP BY stage, state, reason_code ORDER BY stage, state, reason_code", ['platform:'.$platform->value, $platform->value]));
    }

    private function dueLag(PlatformCode $platform, StageCode $stage): MetricMeasurement
    {
        $keys = array_map(static fn (ScopeDefinition $scope): string => $scope->key,
            $this->policy->workScopes($stage, 'platform:'.$platform->value));
        $paused = LedgerRow::one("SELECT EXISTS(SELECT 1 FROM prc_control_scopes WHERE desired_mode='paused'
            AND scope_key IN (".LedgerRow::placeholders(count($keys)).')) AS paused', $keys);
        if ($paused?->bool('paused')) {
            return new MetricMeasurement(MeasurementState::NotApplicable, null, 'paused');
        }
        // Status cadence belongs to the subject. Detail combines pending intent cadence and ready work age.
        $due = $stage === StageCode::StatusRefresh
            ? "SELECT p.next_status_due_at AS due FROM prc_procurements p JOIN prc_platforms platform ON platform.id=p.platform_id
                WHERE platform.code=? AND p.next_status_due_at IS NOT NULL AND NOT EXISTS
                (SELECT 1 FROM prc_process_work w WHERE w.procurement_id=p.id AND w.stage='status_refresh' AND w.state='blocked')"
            : "SELECT i.next_due_at AS due FROM prc_operation_intents i
                JOIN prc_procurements p ON i.subject_id=p.id::text AND i.subject_kind='prc_procurements'
                JOIN prc_platforms platform ON platform.id=p.platform_id
                WHERE platform.code=? AND i.stage='detail' AND i.state='terminal' AND i.next_due_at IS NOT NULL
                UNION ALL SELECT w.created_at AS due FROM prc_process_work w
                WHERE (w.cohort_key=? OR w.platform_id=(SELECT id FROM prc_platforms WHERE code=?))
                  AND w.stage='detail' AND w.state IN ('ready','admitted','running')";
        $bindings = $stage === StageCode::StatusRefresh
            ? [$platform->value]
            : [$platform->value, 'platform:'.$platform->value, $platform->value];
        $row = LedgerRow::one('SELECT floor(extract(epoch FROM clock_timestamp()-min(due)))::bigint AS lag FROM ('.$due.') due', $bindings);
        $lag = $row?->nullableInt('lag');

        return $lag === null ? new MetricMeasurement(MeasurementState::Unknown, null, 'no_due_data')
            : new MetricMeasurement(MeasurementState::Known, max(0, $lag));
    }

    /** @return array<string, ControlMode> */
    private function controlModes(PlatformCode $platform): array
    {
        $modes = [];
        foreach (LedgerRow::all("SELECT scope_key, desired_mode FROM prc_control_scopes
            WHERE scope_key IN ('system','acquisition','acquisition:stage:listing','acquisition:stage:detail',
                'acquisition:stage:status_refresh',?) ORDER BY scope_key", ['acquisition:platform:'.$platform->value]) as $row) {
            $modes[$row->string('scope_key')] = ControlMode::from($row->string('desired_mode'));
        }

        return $modes;
    }
}
