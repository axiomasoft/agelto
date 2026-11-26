<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\RefreshPolicy;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\StoreWorkData;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\TransitionData;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\RunKind;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\RunStoreRepository;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Due allocation is serialized by the procurement head, with no network or admission inside it. */
final readonly class RefreshRepository
{
    public function __construct(private ProcessTransaction $transactions, private WorkStoreRepository $works,
        private RunStoreRepository $runs, private TransitionRepository $transitions, private DetailSnapshotRepository $details,
        private ProcessPayloadCodec $inputs, private RefreshPolicy $policy) {}

    public function intent(StatusRefreshInput $input): ProcessUuid
    {
        $row = LedgerRow::one('SELECT intent_id FROM prc_status_refreshes WHERE procurement_id=? AND cycle_seq=?',
            [$input->procurementId, $input->cycleSeq]) ?? throw new \LogicException('Missing status cycle.');

        return new ProcessUuid($row->string('intent_id'));
    }

    public function scheduleStatus(int $procurementId): ?WorkId
    {
        return $this->allocate($procurementId, StageCode::StatusRefresh);
    }

    public function scheduleDetail(int $procurementId): ?WorkId
    {
        return $this->allocate($procurementId, StageCode::Detail);
    }

    /** @return list<WorkId> */
    public function due(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid refresh scan bound.');
        }
        $rows = LedgerRow::all("SELECT p.id FROM prc_procurements p WHERE p.next_status_due_at<=clock_timestamp()
            OR EXISTS(SELECT 1 FROM prc_operation_intents i WHERE i.subject_kind='prc_procurements' AND i.subject_id=p.id::text
                AND i.stage='detail' AND i.state='terminal' AND i.next_due_at<=clock_timestamp()) ORDER BY p.id LIMIT ?", [$limit]);
        $works = [];
        foreach ($rows as $row) {
            foreach ([$this->scheduleDetail($row->int('id')), $this->scheduleStatus($row->int('id'))] as $work) {
                if ($work !== null) {
                    $works[] = $work;
                }
            }
        }

        return $works;
    }

    private function allocate(int $procurementId, StageCode $stage): ?WorkId
    {
        return $this->transactions->run(function () use ($procurementId, $stage): ?WorkId {
            $p = Procurement::query()->lockForUpdate()->findOrFail($procurementId);
            if (LedgerRow::one("SELECT id FROM prc_operation_intents WHERE subject_kind='prc_procurements' AND subject_id=?
                AND stage=? AND state IN ('active','pending') LIMIT 1", [(string) $p->id, $stage->value]) !== null) {
                return null;
            }
            // Latest published complete detail pins locator, adapter and both policy dimensions.
            $template = LedgerRow::one("SELECT w.input_payload::text AS payload, w.cohort_key, i.id,
                    EXISTS (
                        SELECT 1 FROM prc_operation_intents d
                        WHERE d.subject_kind='prc_procurements' AND d.subject_id=i.subject_id
                          AND d.stage='detail' AND d.state='terminal' AND d.next_due_at<=clock_timestamp()
                    ) AS due,
                    r.configuration_snapshot::text AS configuration, r.configuration_schema_version
                FROM prc_operation_intents i JOIN prc_process_work w ON w.intent_id=i.id
                JOIN prc_process_runs r ON r.id=w.origin_run_id
                WHERE i.subject_kind='prc_procurements' AND i.subject_id=? AND i.stage='detail'
                  AND i.state='terminal' AND w.state='succeeded' ORDER BY i.requested_at DESC,i.id DESC LIMIT 1", [(string) $p->id]);
            if ($template === null) {
                return null;
            }
            $statusDue = LedgerRow::one('SELECT COALESCE(next_status_due_at<=clock_timestamp(),false) AS due FROM prc_procurements WHERE id=?', [$p->id]);
            if ($stage === StageCode::Detail ? ! $template->bool('due') : ! ($statusDue?->bool('due') ?? false)) {
                return null;
            }
            $base = $this->inputs->decodeInput(new VersionedPayload($template->string('payload'), 1));
            if (! $base instanceof DetailInput) {
                throw new \LogicException('Invalid refresh template.');
            }
            $request = $this->details->request($p->id, $base->intentId);
            $column = $stage === StageCode::Detail ? 'detail_cycle_seq' : 'status_cycle_seq';
            $cycle = LedgerRow::one('UPDATE prc_procurements SET '.$column.'='.$column.'+1 WHERE id=? RETURNING '.$column.' AS seq', [$p->id])?->int('seq')
                ?? throw new \LogicException('Missing cycle allocation.');
            $key = ContentHash::of($stage->value.':'.$p->id.':'.$cycle);
            $intent = ListingStoreRepository::id('refresh:'.$key->hex);
            $semantic = $stage === StageCode::Detail ? $base->semanticPolicyHash
                : ContentHash::of($this->policy->hash()->hex.':'.$base->semanticPolicyHash->hex.':'.$base->accessPolicyHash->hex.':'.$base->adapterVersion);
            $this->works->storeIntent($intent, $key, $stage, new SubjectKey(SubjectKind::Procurement, $p->id), $semantic,
                $base->accessPolicyHash, $cycle, $p->head_generation, IntentState::Active);
            $this->details->saveRequest($p->id, $p->head_generation, $intent, $request);
            if ($stage === StageCode::Detail) {
                $input = new DetailInput($p->id, $intent, $p->head_generation, $semantic, $base->accessPolicyHash, $base->adapterVersion);
                // Consume this due trigger atomically; the new cycle owns its next due on finish.
                DB::update("UPDATE prc_operation_intents SET next_due_at=NULL WHERE subject_kind='prc_procurements' AND subject_id=? AND stage='detail' AND state='terminal' AND next_due_at IS NOT NULL", [(string) $p->id]);
            } else {
                $input = new StatusRefreshInput($p->id, $cycle, $semantic, $base->adapterVersion);
                $seconds = $this->statusSeconds($p);
                DB::update("UPDATE prc_procurements SET next_status_due_at=clock_timestamp()+(? * interval '1 second') WHERE id=?", [$seconds, $p->id]);
                DB::insert('INSERT INTO prc_status_refreshes(id,procurement_id,intent_id,cycle_seq,next_due_at)
                    SELECT ?,id,?,?,next_status_due_at FROM prc_procurements WHERE id=?', [$intent->value, $intent->value, $cycle, $p->id]);
            }

            return $this->register($input, $intent, $p, $template->string('cohort_key'),
                new VersionedPayload($template->string('configuration'), $template->int('configuration_schema_version')));
        });
    }

    public function statusSeconds(Procurement $p): int
    {
        if (! $p->status->isTerminal()) {
            return $this->policy->activeSeconds;
        }
        $recent = LedgerRow::one("SELECT COALESCE(current_revision_detected_at,source_updated_at,published_at) >=
            clock_timestamp()-(? * interval '1 day') AS recent FROM prc_procurements WHERE id=?", [$this->policy->recentDays, $p->id]);

        return ($recent?->bool('recent') ?? false) ? $this->policy->recentSeconds : $this->policy->terminalSeconds;
    }

    private function register(WorkInput $input, ProcessUuid $intent, Procurement $p, string $cohort, VersionedPayload $configuration): WorkId
    {
        $run = ListingStoreRepository::id('refresh-run:'.$intent->value);
        $work = new WorkId(ListingStoreRepository::id('refresh-work:'.$intent->value));
        $this->runs->storeRun($run, RunKind::Schedule, 'refresh:'.$intent->value, null, $configuration, null,
            ListingStoreRepository::id('run-event:'.$intent->value), ListingStoreRepository::id('run-project:'.$intent->value));
        $this->works->storeWork(new StoreWorkData(
            id: $work,
            input: $input,
            inputRevision: 1,
            intentId: $intent,
            cohortKey: $cohort,
            platformId: $p->platform_id,
            originRunId: $run,
            priority: 100,
            eventId: ListingStoreRepository::id('work-event:'.$intent->value),
            projectionMessageId: ListingStoreRepository::id('work-project:'.$intent->value),
        ));
        $this->works->linkRun($run, $work, RunWorkRelation::Created, AccountingRole::Required);
        DB::update("UPDATE prc_process_runs SET discovery_outcome='complete', discovery_closed_at=clock_timestamp(),transition_seq=2 WHERE id=?", [$run->value]);
        $this->transitions->recordRun(new TransitionData(
            eventId: ListingStoreRepository::id('close-event:'.$intent->value),
            projectionMessageId: ListingStoreRepository::id('close-project:'.$intent->value),
            entityId: $run,
            entitySeq: 2,
            previousState: 'collecting',
            newState: 'complete',
            reasonCode: null,
            actor: ActorKind::System,
        ));

        return $work;
    }
}
