<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ActorKind;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Services\Procurement\Process\MetricProjector;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\RetentionPolicy;
use App\Services\Procurement\Process\RetentionService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures as Fixture;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t20', 'p118');

// RetentionService выбирает кандидатов prune; удаление остаётся за ProjectionRetention (receipts+fanout).

function ageEvent(ProcessUuid $event, int $days): void
{
    DB::table('prc_process_transitions')->where('event_id', $event->value)->update(['occurred_at' => DB::raw("clock_timestamp() - interval '{$days} days'")]);
}

function cancelledWork(): WorkId
{
    ['work' => $work] = Fixture::workInRun();
    DB::table('prc_process_work')->where('id', $work->value->value)->update([
        'state' => 'cancelled', 'reason_code' => 'cancelled', 'owner_token' => null, 'lease_until' => null,
        'dispatch_claim_until' => null, 'due_at' => null, 'provider_recovery_due_at' => null, 'checkpoint' => null, 'checkpoint_schema_version' => null,
    ]);

    return $work;
}

/** @return list<string> */
function candidateIds(): array
{
    return array_map(static fn (ProcessUuid $id): string => $id->value, app(RetentionService::class)->candidates(new DateTimeImmutable));
}

test('T20 retention: старое non-last transition терминальной work — кандидат; свежее, последнее и нетерминальной work — нет', function (): void {
    $terminal = cancelledWork();
    $old = Fixture::transition($terminal, 'ready');
    $last = Fixture::transition($terminal, 'cancelled', 'cancelled', 'ready');
    $fresh = Fixture::transition($terminal, 'cancelled', 'cancelled', 'ready');
    ageEvent($old, 400);
    ageEvent($last, 400);
    ageEvent($fresh, 10);

    ['work' => $live] = Fixture::workInRun();
    $liveOld = Fixture::transition($live, 'ready');
    Fixture::transition($live, 'admitted', null, 'ready');
    ageEvent($liveOld, 900);

    // $last не последний (есть $fresh), поэтому кандидаты — $old и $last; $fresh свежий; live work активна.
    expect(candidateIds())->toEqualCanonicalizing([$old->value, $last->value]);
});

test('T20 retention: последнее transition сущности и operator audit внутри audit_days сохраняются', function (): void {
    config()->set('process.retention', ['transition_days' => 365, 'audit_days' => 800]);
    $work = cancelledWork();
    $system = Fixture::transition($work, 'ready');
    $operator = LedgerFixtures::uuid();
    app(TransitionRepository::class)->recordWork($operator, LedgerFixtures::uuid(), $work->value, (int) DB::table('prc_process_transitions')->where('work_id', $work->value->value)->max('entity_seq') + 1, 'ready', 'ready', 'manual', ActorKind::Operator, 1, 9);
    $tail = Fixture::transition($work, 'cancelled', 'cancelled', 'ready');
    ageEvent($system, 500);
    ageEvent($operator, 500);
    ageEvent($tail, 500);
    app()->forgetInstance(RetentionPolicy::class);
    app()->forgetInstance(RetentionService::class);

    // Operator audit моложе 800 дней и хвост истории остаются; только system transition устарел.
    expect(candidateIds())->toBe([$system->value]);

    ageEvent($operator, 900);
    expect(candidateIds())->toEqualCanonicalizing([$system->value, $operator->value]);
});

test('T20 retention: нерешённый incident хранит историю, resolved — отдаёт non-last события', function (): void {
    $incident = LedgerFixtures::uuid();
    $events = [LedgerFixtures::uuid(), LedgerFixtures::uuid()];
    app(ProcessTransaction::class)->run(function () use ($incident, $events): void {
        app(IncidentRepository::class)->open(LedgerFixtures::hash('retention'), 'acquisition', 'operator_test', ['k' => 1], $incident, $events[0], LedgerFixtures::uuid());
        app(TransitionRepository::class)->recordIncident($events[1], LedgerFixtures::uuid(), $incident, 2, 'open', 'acknowledged', 'manual_acknowledge', ActorKind::System);
    });
    foreach ($events as $event) {
        ageEvent($event, 500);
    }
    expect(candidateIds())->toBe([]);

    DB::update("UPDATE prc_process_incidents SET state = 'resolved', resolved_at = clock_timestamp() WHERE id = ?", [$incident->value]);
    expect(candidateIds())->toBe([$events[0]->value]);
});

test('T20 retention: prune удаляет только подтверждённые projections; повтор идемпотентен; policy отвергает срок меньше 365 дней', function (): void {
    $work = cancelledWork();
    $old = Fixture::transition($work, 'ready');
    Fixture::transition($work, 'cancelled', 'cancelled', 'ready');
    ageEvent($old, 400);
    app(MetricProjector::class)->ensureStates();
    $service = app(RetentionService::class);

    // Receipt отсутствует: кандидат выбран, но ProjectionRetention его не удаляет.
    $first = $service->prune(new DateTimeImmutable);
    expect([$first->pruned, $first->ineligible])->toBe([0, 1])
        ->and(DB::table('prc_process_transitions')->where('event_id', $old->value)->exists())->toBeTrue();

    app(MetricProjector::class)->apply($old);
    DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$old->value)->update(['published_at' => DB::raw('clock_timestamp()')]);
    $second = $service->prune(new DateTimeImmutable);
    expect($second->pruned)->toBe(1)
        ->and(DB::table('prc_process_transitions')->where('event_id', $old->value)->exists())->toBeFalse()
        ->and($service->prune(new DateTimeImmutable)->pruned)->toBe(0);

    foreach ([['transition_days' => 364, 'audit_days' => 365], ['transition_days' => 365, 'audit_days' => 30], ['transition_days' => 365]] as $bad) {
        config()->set('process.retention', $bad);
        expect(fn () => new RetentionPolicy(config()))->toThrow(InvalidArgumentException::class);
    }
});

function setEventTime(ProcessUuid $event, string $utc): void
{
    DB::table('prc_process_transitions')->where('event_id', $event->value)->update(['occurred_at' => $utc.'+00']);
}

/** @return list<string> */
function candidateIdsAt(DateTimeImmutable $now): array
{
    return array_map(static fn (ProcessUuid $id): string => $id->value, app(RetentionService::class)->candidates($now));
}

test('T20 month boundary: срок — ровно 365 суток; граница строгая, високосный год и конец месяца не сдвигают её', function (): void {
    $work = cancelledWork();
    $before = Fixture::transition($work, 'ready');
    $atCutoff = Fixture::transition($work, 'ready');
    // Хвост сущности — максимальный entity_seq, поэтому делаем его последним.
    $last = Fixture::transition($work, 'cancelled', 'cancelled', 'ready');
    setEventTime($last, '2000-01-01 00:00:00');

    // Не високосный интервал: 2027-03-01 − 365 суток = 2026-03-01.
    setEventTime($before, '2026-02-28 23:59:59');
    setEventTime($atCutoff, '2026-03-01 00:00:00');
    expect(candidateIdsAt(new DateTimeImmutable('2027-03-01 00:00:00', new DateTimeZone('UTC'))))
        ->toContain($before->value)->not->toContain($atCutoff->value);

    // Через 29 февраля 2028: 2028-03-01 − 365 суток = 2027-03-02 (календарный «минус год» дал бы 2027-03-01).
    setEventTime($before, '2027-03-01 23:59:59');
    setEventTime($atCutoff, '2027-03-02 00:00:00');
    expect(candidateIdsAt(new DateTimeImmutable('2028-03-01 00:00:00', new DateTimeZone('UTC'))))
        ->toContain($before->value)->not->toContain($atCutoff->value);

    // Конец месяца и граница года: 2027-12-31 − 365 суток = 2026-12-31.
    setEventTime($before, '2026-12-30 23:59:59');
    setEventTime($atCutoff, '2026-12-31 00:00:00');
    expect(candidateIdsAt(new DateTimeImmutable('2027-12-31 00:00:00', new DateTimeZone('UTC'))))
        ->toContain($before->value)->not->toContain($atCutoff->value);
});

test('T20 month boundary: тот же момент в другой зоне, в том числе с DST, даёт тот же cutoff', function (): void {
    $work = cancelledWork();
    $old = Fixture::transition($work, 'ready');
    Fixture::transition($work, 'cancelled', 'cancelled', 'ready');
    // now = 2027-03-14 06:30 UTC — 01:30 EST в Нью-Йорке за 30 минут до перехода на DST; ровно 365 суток
    // назад — 2026-03-14 06:30 UTC. Wall-clock арифметика в зоне с DST сдвинула бы cutoff на час.
    $instant = '2027-03-14 06:30:00';
    setEventTime($old, '2026-03-14 06:29:59');

    foreach (['UTC', 'Europe/Moscow', 'America/New_York', 'Pacific/Auckland'] as $zone) {
        $now = (new DateTimeImmutable($instant, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));
        expect(candidateIdsAt($now))->toBe([$old->value], $zone);
    }

    setEventTime($old, '2026-03-14 06:30:00');
    foreach (['UTC', 'America/New_York', 'Pacific/Auckland'] as $zone) {
        $now = (new DateTimeImmutable($instant, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));
        expect(candidateIdsAt($now))->toBe([], $zone);
    }
});

test('T20 process:prune-history: --dry-run ничего не удаляет; реальный запуск удаляет только подтверждённое и сообщает полноту истории', function (): void {
    $work = cancelledWork();
    $old = Fixture::transition($work, 'ready');
    Fixture::transition($work, 'cancelled', 'cancelled', 'ready');
    ageEvent($old, 400);
    app(MetricProjector::class)->ensureStates();
    app(MetricProjector::class)->apply($old);
    DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$old->value)->update(['published_at' => DB::raw('clock_timestamp()')]);

    $this->artisan('process:prune-history', ['--dry-run' => true])
        ->expectsOutputToContain('Кандидатов в этом batch: 1.')
        ->expectsOutputToContain('История всех projectors полна')
        ->assertSuccessful();
    expect(DB::table('prc_process_transitions')->where('event_id', $old->value)->exists())->toBeTrue();

    // Архив не лжёт о полноте: после prune команда явно говорит, что rebuild больше недоступен.
    $this->artisan('process:prune-history')
        ->expectsOutputToContain('Удалено 1, оставлено до подтверждения projections 0.')
        ->expectsOutputToContain('История неполна, rebuild недоступен (history_unavailable): work_transitions')
        ->assertSuccessful();
    expect(DB::table('prc_process_transitions')->where('event_id', $old->value)->exists())->toBeFalse();

    $this->artisan('process:prune-history')->expectsOutputToContain('Удалено 0, оставлено до подтверждения projections 0.')->assertSuccessful();
});
