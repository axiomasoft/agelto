<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DaemonTick;
use App\Enums\Procurement\Process\DaemonKind;
use App\Repositories\Procurement\Process\OutboxRepository;
use App\Services\Procurement\Process\DaemonPolicy;
use App\Services\Procurement\Process\ProjectorDaemon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures as Fixture;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t46', 't08', 'p115');

function rawProjectionMessage(string $payload, int $schemaVersion = 1): string
{
    $id = LedgerFixtures::uuid()->value;
    DB::table('prc_process_outbox')->insert([
        'message_id' => $id, 'dedup_key' => 'project:raw:'.$id, 'kind' => 'projection', 'payload' => $payload,
        'payload_schema_version' => $schemaVersion, 'available_at' => DB::raw('clock_timestamp()'),
    ]);

    return $id;
}

test('tick claim-ит ТОЛЬКО kind projection, подтверждает после receipts и сеет state всех consumers', function (): void {
    ['work' => $work] = Fixture::workInRun();
    Fixture::transition($work, 'succeeded', null, 'running');
    $feeder = LedgerFixtures::uuid();
    app(OutboxRepository::class)->enqueueFeederWake($feeder, 'acquisition');

    $tick = app(ProjectorDaemon::class)->tick();

    // Три события: создание run, создание work, succeeded.
    expect($tick->processed)->toBe(3)
        ->and($tick->errorCode)->toBeNull()
        ->and(DB::table('prc_process_outbox')->where('kind', 'projection')->whereNull('published_at')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('message_id', $feeder->value)->whereNull('published_at')->whereNull('claim_token')->exists())->toBeTrue()
        ->and(DB::table('prc_process_projection_receipts')->count())->toBe(3)
        ->and(DB::table('prc_process_projection_states')->where('active_generation', 1)->where('mode', 'active')->count())->toBe(3)
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('повторная доставка того же сообщения: Duplicate, сообщение подтверждено, счётчики не удвоены', function (): void {
    ['work' => $work] = Fixture::workInRun();
    Fixture::transition($work, 'succeeded', null, 'running');
    app(ProjectorDaemon::class)->tick();
    $before = DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all();

    DB::table('prc_process_outbox')->where('kind', 'projection')->update(['published_at' => null]);
    $again = app(ProjectorDaemon::class)->tick();

    expect($again->processed)->toBe(3)
        ->and(DB::table('prc_process_metric_totals')->orderBy('metric_key')->orderBy('dimension_key')->get()->all())->toEqual($before)
        ->and(DB::table('prc_process_projection_receipts')->count())->toBe(3);
});

test('нечитаемое и неподдерживаемое сообщение — quarantine с incident и degraded, остальные event IDs проецируются', function (): void {
    Fixture::workInRun();
    $broken = rawProjectionMessage('{"bogus":1}');
    $future = rawProjectionMessage('{"event_id":"'.LedgerFixtures::uuid()->value.'"}', schemaVersion: 2);

    $tick = app(ProjectorDaemon::class)->tick();

    $rows = DB::table('prc_process_outbox')->whereIn('message_id', [$broken, $future])->get()->keyBy('message_id');
    expect($tick->processed)->toBe(2)
        ->and($tick->errorCode)->toBe('outbox_quarantined')
        ->and($rows[$broken]->last_error_code)->toBe('quarantined_projection_payload_invalid')
        ->and($rows[$future]->last_error_code)->toBe('quarantined_projection_schema_unsupported')
        ->and($rows[$broken]->published_at)->toBeNull()
        ->and(DB::table('prc_process_outbox')->whereIn('message_id', [$broken, $future])->whereRaw('available_at > clock_timestamp()')->count())->toBe(2)
        ->and(DB::table('prc_process_incidents')->where('kind', 'projection_schema_error')->where('scope_key', 'outbox:projection')->where('state', 'open')->count())->toBe(2)
        ->and(Fixture::receipts('work_transitions'))->toBe(1);
});

test('событие вне каталога уходит в quarantine, соседние события применяются; тот же сбой не плодит incidents', function (): void {
    ['work' => $work] = Fixture::workInRun();
    Fixture::rawWorkEvent($work, 'bogus_state');
    Fixture::rawWorkEvent($work, 'also_bogus');

    // Raw INSERT не создаёт projection outbox: первый tick проецирует только штатные события run и work.
    expect(app(ProjectorDaemon::class)->tick()->processed)->toBe(2);
    foreach (DB::table('prc_process_transitions')->whereIn('new_state', ['bogus_state', 'also_bogus'])->pluck('event_id') as $eventId) {
        DB::table('prc_process_outbox')->insert([
            'message_id' => LedgerFixtures::uuid()->value, 'dedup_key' => 'project:'.$eventId, 'kind' => 'projection',
            'payload' => '{"event_id":"'.$eventId.'"}', 'payload_schema_version' => 1, 'available_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    $second = app(ProjectorDaemon::class)->tick();

    expect($second->errorCode)->toBe('outbox_quarantined')
        ->and(DB::table('prc_process_outbox')->where('last_error_code', 'quarantined_projection_schema_invalid')->count())->toBe(2)
        // Один fingerprint на код: массовый сбой — один incident, а не по одному на событие.
        ->and(DB::table('prc_process_incidents')->where('kind', 'projection_schema_error')->count())->toBe(1);
});

test('самое раннее malformed событие (пустое new_state) не роняет tick и не блокирует следующие: quarantine + применение соседа', function (): void {
    ['work' => $work] = Fixture::workInRun();
    $malformed = Fixture::rawWorkEvent($work, '');
    // Raw INSERT не создаёт projection outbox: сообщение malformed события вставляем раньше штатного соседа.
    DB::table('prc_process_outbox')->insert([
        'message_id' => LedgerFixtures::uuid()->value, 'dedup_key' => 'project:'.$malformed->value, 'kind' => 'projection',
        'payload' => '{"event_id":"'.$malformed->value.'"}', 'payload_schema_version' => 1, 'available_at' => DB::raw('clock_timestamp() - interval \'1 minute\''),
    ]);
    $good = Fixture::transition($work, 'succeeded', null, 'running');

    $first = app(ProjectorDaemon::class)->tick();
    $second = app(ProjectorDaemon::class)->tick();

    expect($first->errorCode)->toBe('outbox_quarantined')
        ->and($second->errorCode)->toBe('outbox_quarantined')
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$malformed->value)->value('last_error_code'))->toBe('quarantined_projection_schema_invalid')
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$good->value)->whereNotNull('published_at')->exists())->toBeTrue()
        ->and(Fixture::total('work_transitions', 'work_transition_total', Fixture::workDimension('listing', 'succeeded')))->toBe(1);
});

test('maintenance откладывает сообщение без quarantine и degraded; после снятия gate оно применяется', function (): void {
    ['work' => $work] = Fixture::workInRun();
    Fixture::transition($work, 'succeeded', null, 'running');
    app(ProjectorDaemon::class)->tick();
    $late = Fixture::transition($work, 'superseded', 'input_invalidated', 'succeeded');
    DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->update([
        'mode' => 'maintenance', 'maintenance_token' => LedgerFixtures::uuid()->value, 'maintenance_started_at' => DB::raw('clock_timestamp()'),
    ]);

    $tick = app(ProjectorDaemon::class)->tick();
    $row = DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$late->value)->first();

    expect($tick->processed)->toBe(0)
        ->and($tick->errorCode)->toBeNull()
        ->and($row->published_at)->toBeNull()
        ->and($row->last_error_code)->toBe('projection_maintenance')
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$late->value)->whereRaw('available_at > clock_timestamp()')->exists())->toBeTrue()
        ->and(Fixture::receipts('work_transitions'))->toBe(2);

    DB::table('prc_process_projection_states')->where('projector_key', 'work_transitions')->update(['mode' => 'active', 'maintenance_token' => null, 'maintenance_started_at' => null]);
    DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$late->value)->update(['available_at' => DB::raw('clock_timestamp()')]);

    expect(app(ProjectorDaemon::class)->tick()->processed)->toBe(1)
        ->and(Fixture::receipts('work_transitions'))->toBe(3);
});

test('batch ограничен process.daemons.project.batch; delay: работа есть — без паузы, пусто — tick 1 s', function (): void {
    ['work' => $work] = Fixture::workInRun();
    Fixture::transition($work, 'succeeded', null, 'running');
    config()->set('process.daemons.project.batch', 1);
    $daemon = app(ProjectorDaemon::class);

    $first = $daemon->tick();

    expect($first->processed)->toBe(1)
        ->and($daemon->delayMs($first))->toBe(0)
        ->and($daemon->kind())->toBe(DaemonKind::Project)
        ->and($daemon->delayMs(DaemonTick::idle()))->toBe(1000)
        ->and(DB::table('prc_process_outbox')->where('kind', 'projection')->whereNull('published_at')->count())->toBe(2);
});

test('policy daemon project закрыта: ключи обязательны, неизвестный ключ отвергается', function (): void {
    $policy = new DaemonPolicy(app('config'));

    expect([$policy->projectTickSeconds, $policy->projectBatch, $policy->projectClaimSeconds])->toBe([1, 100, 30]);

    config()->set('process.daemons.project.unknown', 1);
    expect(fn () => new DaemonPolicy(app('config')))->toThrow(InvalidArgumentException::class, 'Invalid daemon policy: process.daemons.project');
});
