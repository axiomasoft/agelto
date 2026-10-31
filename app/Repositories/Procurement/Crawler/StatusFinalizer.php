<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\LockedProcurement;
use App\Dto\Procurement\Acquisition\StatusProgress;
use App\Dto\Procurement\Acquisition\StatusResult;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkInput;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Acquisition\AcquisitionProvenance;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use App\Services\Procurement\Acquisition\HeadSnapshot;
use App\Services\Procurement\Acquisition\IdentityResolver;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\Acquisition\UnmappedStatusReporter;
use App\Services\Procurement\Process\Contracts\StageResultRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class StatusFinalizer implements StageResultRepository
{
    public function __construct(private RefreshRepository $refreshes, private DetailSnapshotRepository $details,
        private SnapshotFinalizer $snapshots, private IdentityResolver $identities, private HeadSnapshot $heads,
        private UnmappedStatusReporter $unknown, private DetailValueCodec $codec) {}

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        if (! $input instanceof StatusRefreshInput || ! $progress instanceof StatusProgress) {
            throw new \InvalidArgumentException('Status progress required.');
        }
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Fenced status publication');
        if (! $input instanceof StatusRefreshInput || ! $result instanceof StatusResult) {
            throw new \InvalidArgumentException('Status result required.');
        }
        $intent = $this->refreshes->intent($input);
        $row = LedgerRow::one('SELECT expected_head_generation FROM prc_operation_intents WHERE id=?', [$intent->value]) ?? throw new \LogicException('Missing status intent.');
        $p = Procurement::query()->findOrFail($input->procurementId);
        $request = $this->details->request($p->id, $intent);
        if ($request->identity->toArray() !== $result->snapshot->identity->toArray()) {
            throw new \InvalidArgumentException('Status result identity mismatch.');
        }
        $this->details->insert($p->id, $row->int('expected_head_generation'), $intent->value.':status', 'complete', $this->codec->encode($result), $result->snapshot->sourceRevision);
        $current = $p->head_generation === $row->int('expected_head_generation') && $p->status_cycle_seq === $input->cycleSeq;
        if ($current) {
            $oldHead = $p->head_generation;
            $identity = $this->identities->resolve($p->registry_id, $request->identity);
            $tx = LedgerRow::one('SELECT txid_current()::text AS id') ?? throw new \LogicException('Missing tx');
            $p = $this->snapshots->persistLocked($this->heads->make($p, $request->identity, $result->snapshot->title, $result->snapshot->sourceStage),
                new LockedProcurement($identity, $p, $tx->string('id')),
                processRunId: AcquisitionProvenance::runFor($input), observedAt: Carbon::instance($result->observedAt));
            $this->unknown->record($p);
            if ($p->head_generation !== $oldHead) {
                DB::update("UPDATE prc_operation_intents SET next_due_at=clock_timestamp() WHERE subject_kind='prc_procurements'
                    AND subject_id=? AND stage='detail' AND state='terminal'", [(string) $p->id]);
            }
        }
        // A conflicting head is history-only and requests a new reconciliation cycle.
        $seconds = $current ? $this->refreshes->statusSeconds($p) : 0;
        DB::update("UPDATE prc_procurements SET next_status_due_at=clock_timestamp()+(? * interval '1 second') WHERE id=?", [$seconds, $p->id]);
        DB::update('UPDATE prc_status_refreshes SET source_version=?,observed_at=?,next_due_at=(SELECT next_status_due_at FROM prc_procurements WHERE id=?) WHERE id=?',
            [$result->snapshot->sourceRevision, $result->observedAt->format('Y-m-d H:i:s.uP'), $p->id, $intent->value]);
        if (DB::update("UPDATE prc_operation_intents SET state='terminal' WHERE id=? AND state='active'", [$intent->value]) !== 1) {
            throw new \LogicException('Status intent fence failed.');
        }

        return [];
    }
}
