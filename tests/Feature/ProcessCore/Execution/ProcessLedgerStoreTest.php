<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkState;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Process\WorkItem;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\CrawlStoreRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\SchemaSupportedStages;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('ledger-authority');

const PROCESS_LEDGER_TABLES = [
    'prc_control_scopes', 'prc_process_lanes', 'prc_process_runs', 'prc_process_incidents', 'prc_process_handler_holds',
    'prc_crawl_runs', 'prc_crawl_blocks', 'prc_operation_intents', 'prc_process_work', 'prc_process_attempts',
    'prc_admission_groups', 'prc_process_reservations', 'prc_process_run_work', 'prc_process_transitions',
    'prc_process_outbox', 'prc_process_daemon_heartbeats', 'prc_request_attempts', 'prc_observations', 'prc_observation_applications',
];

/** @return array{input: WorkInput, owner: int|string} Real subject row of the stage owner table plus its typed input. */
function processLedgerSubject(StageCode $stage): array
{
    $intent = static function (SubjectKey $subject) use ($stage): ProcessUuid {
        $id = LedgerFixtures::uuid();
        app(WorkStoreRepository::class)->storeIntent(
            id: $id,
            intentKey: LedgerFixtures::hash('intent:'.$id->value),
            stage: $stage,
            subject: $subject,
            semanticPolicyHash: LedgerFixtures::hash('semantic'),
            accessPolicyHash: LedgerFixtures::hash('access'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        );

        return $id;
    };

    return match ($stage) {
        StageCode::Listing => (static function (): array {
            $subject = LedgerFixtures::crawlBlock();

            return ['input' => LedgerFixtures::listingInput($subject['block'], $subject['filter_hash'], $subject['access_policy_hash']), 'owner' => $subject['block']->value];
        })(),
        StageCode::Detail => (static function () use ($intent): array {
            $procurement = Procurement::factory()->create();

            return ['input' => LedgerFixtures::detailInput($procurement->id, $intent(new SubjectKey(SubjectKind::Procurement, $procurement->id))), 'owner' => $procurement->id];
        })(),
        StageCode::StatusRefresh => (static function (): array {
            $procurement = Procurement::factory()->create();

            return ['input' => LedgerFixtures::statusInput($procurement->id), 'owner' => $procurement->id];
        })(),
        StageCode::Download => (static function () use ($intent): array {
            $document = Document::factory()->create();

            return ['input' => LedgerFixtures::downloadInput($document->id, $intent(new SubjectKey(SubjectKind::Document, $document->id))), 'owner' => $document->id];
        })(),
        default => throw new InvalidArgumentException('Unsupported acquisition/download ledger stage.'),
    };
}

test('чистый baseline не содержит fake subject, authority или ledger rows', function (): void {
    foreach (PROCESS_LEDGER_TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
});

test('каждый schema-supported stage сохраняет ready work на реальном subject ровно одной owner column', function (StageCode $stage): void {
    $subject = processLedgerSubject($stage);
    $run = LedgerFixtures::crawlBlock()['run'];
    $workId = LedgerFixtures::storeWork($subject['input'], originRunId: $run);
    app(WorkStoreRepository::class)->linkRun($run, $workId, RunWorkRelation::Created, AccountingRole::Required);

    $work = WorkItem::query()->findOrFail($workId->value->value);
    $hashes = DB::selectOne("SELECT encode(work_key, 'hex') AS work_key, encode(input_hash, 'hex') AS input_hash, input_payload::text AS input_payload FROM prc_process_work WHERE id = ?", [$workId->value->value]);
    $owners = array_filter(
        array_combine(SchemaSupportedStages::OWNER_COLUMNS, array_map(fn (string $column): mixed => $work->getAttribute($column), SchemaSupportedStages::OWNER_COLUMNS)),
        fn (mixed $value): bool => $value !== null,
    );
    $payload = (new ProcessPayloadCodec)->encodeInput($subject['input']);

    expect($work->stage)->toBe($stage)
        ->and($work->state)->toBe(WorkState::Ready)
        ->and($owners)->toBe([SchemaSupportedStages::ownerColumn($stage) => $subject['owner']])
        ->and($work->due_at)->toBeNull()->and($work->provider_recovery_due_at)->toBeNull()
        ->and([$work->generation, $work->dispatch_generation, $work->checkpoint_seq, $work->execution_attempts, $work->transport_attempts, $work->semantic_reasks, $work->item_failures, $work->infrastructure_interruptions])
        ->toBe([0, 0, 0, 0, 0, 0, 0, 0])
        ->and($work->transition_seq)->toBe(1)
        ->and($work->checkpoint)->toBeNull()->and($work->owner_token)->toBeNull()->and($work->lease_until)->toBeNull()
        ->and($hashes->work_key)->toBe((new WorkIdentity($subject['input']))->key()->hex)
        ->and($hashes->input_hash)->toBe(WorkStoreRepository::inputHash((string) $payload->json)->hex)
        ->and((new ProcessPayloadCodec)->decodeInput(new VersionedPayload($hashes->input_payload, $work->input_schema_version)))->toEqual($subject['input']);

    $transition = DB::table('prc_process_transitions')->where('work_id', $workId->value->value)->sole();
    expect($transition->entity_kind)->toBe('work')
        ->and($transition->entity_seq)->toBe(1)
        ->and($transition->previous_state)->toBeNull()
        ->and($transition->new_state)->toBe('ready')
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$transition->event_id)->where('kind', 'projection')->count())->toBe(1)
        ->and(DB::table('prc_process_run_work')->where(['run_id' => $run->value, 'work_id' => $workId->value->value])->value('accounting_role'))->toBe('required');
})->with([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh, StageCode::Download]);

test('тот же immutable input не создаёт вторую work: work_key уникален', function (): void {
    $subject = processLedgerSubject(StageCode::Listing);
    LedgerFixtures::storeWork($subject['input']);

    expect(LedgerFixtures::sqlState(fn () => LedgerFixtures::storeWork($subject['input'])))->toBe('23505')
        ->and(DB::table('prc_process_work')->count())->toBe(1)
        ->and(DB::table('prc_process_transitions')->whereNotNull('work_id')->count())->toBe(1);
});

test('неподдерживаемая стадия и чужой subject отвергаются до INSERT без NULL owner rows', function (): void {
    expect(fn () => LedgerFixtures::storeWork(new ExtractionWindowInput(LedgerFixtures::uuid(), 'window:0', LedgerFixtures::hash('recipe'))))
        ->toThrow(InvalidArgumentException::class, 'Work input has no resolved owner')
        ->and(fn () => app(WorkStoreRepository::class)->storeIntent(
            id: LedgerFixtures::uuid(),
            intentKey: LedgerFixtures::hash('foreign-subject'),
            stage: StageCode::Detail,
            subject: new SubjectKey(SubjectKind::Document, 42),
            semanticPolicyHash: LedgerFixtures::hash('semantic'),
            accessPolicyHash: LedgerFixtures::hash('access'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        ))->toThrow(InvalidArgumentException::class, 'stage owner table')
        ->and(DB::table('prc_process_work')->count())->toBe(0)
        ->and(DB::table('prc_operation_intents')->count())->toBe(0);
});

test('process run начинается collecting вместе с transition и projection intent', function (): void {
    $run = LedgerFixtures::crawlBlock('manual:'.LedgerFixtures::uuid()->value)['run'];

    $row = DB::table('prc_process_runs')->where('id', $run->value)->sole();
    $transition = DB::table('prc_process_transitions')->where('run_id', $run->value)->sole();

    expect($row->discovery_outcome)->toBe('collecting')
        ->and($row->discovery_closed_at)->toBeNull()
        ->and($row->discovery_reason_code)->toBeNull()
        ->and($row->transition_seq)->toBe(1)
        ->and($transition->new_state)->toBe('collecting')
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$transition->event_id)->count())->toBe(1)
        // trigger_key дедуплицирует повтор той же invocation.
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::crawlBlock($row->trigger_key)))->toBe('23505');
});

test('crawl block наследует filter hash своего run и не создаётся без реального run', function (): void {
    $subject = LedgerFixtures::crawlBlock();

    expect(DB::selectOne("SELECT encode(filter_hash, 'hex') AS hash FROM prc_crawl_blocks WHERE id = ?", [$subject['block']->value])->hash)
        ->toBe($subject['filter_hash']->hex)
        ->and(fn () => app(CrawlStoreRepository::class)->storeCrawlBlock(LedgerFixtures::uuid(), LedgerFixtures::uuid(), null, 'orphan', new VersionedPayload('{}', 1)))
        ->toThrow(InvalidArgumentException::class, 'existing crawl run')
        ->and(DB::table('prc_crawl_blocks')->count())->toBe(1);
});

test('procurement и document получают initial head/cycle authority в исходном baseline', function (): void {
    $procurement = Procurement::factory()->create()->refresh();
    $document = Document::factory()->create()->refresh();

    expect([$procurement->head_generation, $procurement->detail_cycle_seq, $procurement->status_cycle_seq, $procurement->next_status_due_at])
        ->toBe([1, 0, 0, null])
        ->and([$document->head_generation, $document->acquisition_cycle_seq, $document->next_revalidation_due_at])->toBe([1, 0, null])
        ->and(LedgerFixtures::sqlState(fn () => DB::table('prc_procurements')->where('id', $procurement->id)->update(['head_generation' => 0])))->toBe('23514')
        ->and(LedgerFixtures::sqlState(fn () => DB::table('prc_documents')->where('id', $document->id)->update(['acquisition_cycle_seq' => -1])))->toBe('23514');
});

test('новые head поля не mass-assignable: subject authority пишет только Process ledger', function (): void {
    $procurement = new Procurement;
    $procurement->fill(['head_generation' => 9, 'status_cycle_seq' => 9]);
    $document = new Document;
    $document->fill(['head_generation' => 9, 'acquisition_cycle_seq' => 9]);

    expect(array_intersect_key($procurement->getAttributes(), array_flip(['head_generation', 'detail_cycle_seq', 'status_cycle_seq'])))->toBe([])
        ->and(array_intersect_key($document->getAttributes(), array_flip(['head_generation', 'acquisition_cycle_seq'])))->toBe([]);
});
