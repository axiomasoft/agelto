<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkWake;
use App\Enums\Procurement\Process\OutboxKind;
use App\Enums\Procurement\Process\StageCode;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Repositories\Procurement\Process\OutboxRepository;
use App\Services\Procurement\Process\Contracts\WorkWakePublisher;
use App\Services\Procurement\Process\DaemonPolicy;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\QueueWorkWakePublisher;
use App\Services\Procurement\Process\RelayDaemon;
use App\Services\Procurement\Process\StageQueueMap;
use App\Services\Procurement\Process\WorkFeeder;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t08', 't31', 'p110');

/** Таблица bp-jobs: connection, queue, job timeout, Horizon timeout, retry_after, lease. */
const ENVELOPE_ROUTES = [
    'listing' => ['process_long', 'process-listing', 260, 280, 330, 360],
    'detail' => ['process_short', 'process-acquisition', 110, 120, 150, 180],
    'status_refresh' => ['process_short', 'process-acquisition', 110, 120, 150, 180],
    'download' => ['process_download', 'process-download', 320, 340, 390, 420],
    'prepare' => ['process_long', 'process-prepare', 260, 280, 330, 360],
    'screening' => ['process_ai', 'process-ai', 290, 310, 360, 390],
    'extraction' => ['process_ai', 'process-ai', 290, 310, 360, 390],
    'publication' => ['process_short', 'process-publication', 110, 120, 150, 180],
];

function envelopeMapFailure(): ?InvalidArgumentException
{
    try {
        new StageQueueMap(app(ProcessPolicy::class), app('config'));
    } catch (InvalidArgumentException $failure) {
        return $failure;
    }

    return null;
}

/** Publisher boundary: запоминает wake; опционально падает до publish (Redis недоступен). */
function envelopePublisher(?Throwable $failure = null, ?Closure $afterPublish = null): object
{
    $publisher = new class($failure, $afterPublish) implements WorkWakePublisher
    {
        /** @var list<WorkWake> */
        public array $published = [];

        public function __construct(public ?Throwable $failure, private readonly ?Closure $afterPublish) {}

        public function publish(WorkWake $wake): void
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->published[] = $wake;
            if ($this->afterPublish !== null) {
                ($this->afterPublish)($wake);
            }
        }
    };
    app()->instance(WorkWakePublisher::class, $publisher);

    return $publisher;
}

/** Реальный feeder tick: listing work admitted с work_wake outbox (dispatch generation 1). */
function envelopeAdmitted(): WorkId
{
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    expect(ExecutionFixtures::work($work)->state)->toBe('admitted');

    return $work;
}

function envelopeWakeRow(WorkId $work): object
{
    return DB::table('prc_process_outbox')->where('dedup_key', 'wake:'.$work->value->value.':1')->sole();
}

/** Raw work_wake: моделирует сообщение иной версии кода (relay не умеет его исполнить). */
function envelopeRawWake(string $payload, int $schemaVersion = 1): string
{
    $id = LedgerFixtures::uuid()->value;
    DB::table('prc_process_outbox')->insert([
        'message_id' => $id, 'dedup_key' => 'wake:raw-'.$id, 'kind' => 'work_wake', 'payload' => $payload,
        'payload_schema_version' => $schemaVersion, 'available_at' => DB::raw('clock_timestamp()'),
    ]);

    return $id;
}

test('StageQueueMap повторяет таблицу bp-jobs и цепочку slice < job < Horizon < retry_after < lease каждой stage', function (): void {
    $map = app(StageQueueMap::class);
    $values = array_map(static fn (StageCode $code): string => $code->value, StageCode::cases());
    sort($values);
    $tabled = array_keys(ENVELOPE_ROUTES);
    sort($tabled);

    expect($tabled)->toBe($values)->and($map->routes())->toHaveCount(count($values));
    foreach (StageCode::cases() as $code) {
        $route = $map->forStage($code);
        expect([$route->connection, $route->queue, $route->jobTimeout, $route->horizonTimeout, $route->retryAfter, $route->leaseSeconds])
            ->toBe(ENVELOPE_ROUTES[$code->value], $code->value)
            ->and($route->sliceSeconds)->toBeLessThan($route->jobTimeout);
    }
    // Default redis connection и supervisor-1 остаются для notifications/mail, Process их не занимает.
    foreach ($map->routes() as $route) {
        expect($route->connection)->not->toBe('redis')->and($route->queue)->not->toBe('default');
    }
});

test('StageQueueMap отвергает расхождение конфигурации с ключом config, а не подставляет default', function (): void {
    expect(envelopeMapFailure())->toBeNull();

    // retry_after не выше Horizon timeout: Redis вернул бы ещё работающий job второму воркеру.
    config()->set('queue.connections.process_short.retry_after', 120);
    expect(envelopeMapFailure()?->getMessage())->toContain('Invalid process queue map: process.queues.');
    config()->set('queue.connections.process_short.retry_after', 150);

    // lease короче retry_after.
    config()->set('process.stages.detail.lease', 140);
    expect(envelopeMapFailure()?->getMessage())->toBe('Invalid process queue map: process.queues.detail');
    config()->set('process.stages.detail.lease', 180);

    // stage без маршрута.
    $queues = config('process.queues');
    unset($queues['download']);
    config()->set('process.queues', $queues);
    expect(envelopeMapFailure()?->getMessage())->toBe('Invalid process queue map: process.queues.download');
});

test('StageQueueMap отвергает отсутствующий или неоднозначный Horizon supervisor connection+queue и нестрогий tries', function (): void {
    config()->set('horizon.defaults.supervisor-process-ai.queue', ['other-queue']);
    $missing = envelopeMapFailure();
    expect($missing?->getMessage())->toBe('Invalid process queue map: process.queues.screening')
        ->and($missing?->getPrevious()?->getMessage())->toContain('horizon.defaults supervisor of process_ai:process-ai');
    config()->set('horizon.defaults.supervisor-process-ai.queue', ['process-ai']);

    config()->set('horizon.defaults.supervisor-process-ai.tries', 3);
    expect(envelopeMapFailure()?->getPrevious()?->getMessage())->toContain('timeout/tries');
});

test('QueueWorkWakePublisher кладёт job на connection+queue карты и назначает public timeout до push', function (): void {
    foreach (StageCode::cases() as $code) {
        $route = app(StageQueueMap::class)->forStage($code);
        $captured = null;
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('pushOn')->once()->withArgs(function (string $name, ExecuteWorkJob $job) use ($route, &$captured): bool {
            $captured = $job;

            return $name === $route->queue;
        });
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with($route->connection)->andReturn($queue);

        (new QueueWorkWakePublisher(app(StageQueueMap::class), $factory))->publish(
            new WorkWake(new WorkId(LedgerFixtures::uuid()), new DeliveryGeneration(4), LedgerFixtures::uuid(), 1, $code),
        );

        expect($captured)->toBeInstanceOf(ExecuteWorkJob::class)
            ->and($captured->timeout)->toBe($route->jobTimeout)
            ->and([$captured->dispatchGeneration, $captured->queueSchemaVersion])->toBe([4, 1]);
    }
});

test('actual serialized Laravel payload: public timeout, одна попытка, ни моделей ни credentials и ни retry middleware', function (): void {
    foreach (StageCode::cases() as $code) {
        $route = app(StageQueueMap::class)->forStage($code);
        $job = new ExecuteWorkJob(LedgerFixtures::uuid()->value, 7, LedgerFixtures::uuid()->value, 1);
        $job->timeout = $route->jobTimeout;
        $queue = app('queue')->connection($route->connection);
        $json = (new ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, $route->queue);
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $command = unserialize($payload['data']['command'], ['allowed_classes' => [ExecuteWorkJob::class]]);

        // Laravel читает public $timeout и кладёт в payload; метод timeout() не участвует.
        expect($payload['timeout'])->toBe($route->jobTimeout, $code->value)
            ->and([$payload['maxTries'], $payload['maxExceptions'], $payload['failOnTimeout']])->toBe([1, 1, true])
            ->and([$payload['backoff'], $payload['retryUntil'], $payload['delay'], $payload['deleteWhenMissingModels']])->toBe([null, null, null, false])
            ->and($payload['data']['commandName'])->toBe(ExecuteWorkJob::class)
            ->and($command)->toBeInstanceOf(ExecuteWorkJob::class)
            ->and([$command->workId, $command->dispatchGeneration, $command->timeout])->toBe([$job->workId, 7, $route->jobTimeout])
            ->and($payload['data']['command'])->not->toContain('App\\Models')->not->toContain('password')
            ->and(strlen($json))->toBeLessThan(2048)
            // Connection retry_after этой же карты покрывает Horizon timeout и job timeout.
            ->and((int) config('queue.connections.'.$route->connection.'.retry_after'))->toBe($route->retryAfter)
            ->and($route->retryAfter)->toBeGreaterThan($route->jobTimeout);
    }
});

test('ExecuteWorkJob — только delivery: нет методов retry/timeout, middleware и unique-семантики', function (): void {
    $forbidden = ['timeout', 'tries', 'backoff', 'retryUntil', 'middleware', 'uniqueId', 'uniqueFor'];

    expect(array_filter($forbidden, static fn (string $method): bool => method_exists(ExecuteWorkJob::class, $method)))->toBe([])
        ->and((new ReflectionProperty(ExecuteWorkJob::class, 'timeout'))->isPublic())->toBeTrue()
        ->and(array_intersect(array_values(class_implements(ExecuteWorkJob::class)), [
            ShouldBeUnique::class, ShouldBeUniqueUntilProcessing::class,
        ]))->toBe([])
        ->and((string) file_get_contents(app_path('Jobs/Procurement/Process/ExecuteWorkJob.php')))->not->toMatch('/->(release|delay|backoff|retryUntil|chain)\(/')
        ->and((new ExecuteWorkJob(LedgerFixtures::uuid()->value, 1, LedgerFixtures::uuid()->value))->middleware)->toBe([]);
});

test('relay claim-ит только work_wake, публикует wake и помечает его published тем же token', function (): void {
    $work = envelopeAdmitted();
    $feederWake = LedgerFixtures::uuid();
    app(OutboxRepository::class)->enqueueFeederWake($feederWake, 'acquisition');
    $publisher = envelopePublisher();

    $tick = app(RelayDaemon::class)->tick();
    $wake = envelopeWakeRow($work);

    expect($tick->processed)->toBe(1)->and($tick->errorCode)->toBeNull()
        ->and($publisher->published)->toHaveCount(1)
        ->and([$publisher->published[0]->workId->value->value, $publisher->published[0]->dispatchGeneration->value, $publisher->published[0]->stage])
        ->toBe([$work->value->value, 1, StageCode::Listing])
        ->and($publisher->published[0]->messageId->value)->toBe($wake->message_id)
        ->and([$wake->published_at !== null, $wake->claim_token, $wake->claim_until, $wake->attempts])->toBe([true, null, null, 1])
        // Чужие kind не затронуты: ни claim, ни attempts.
        ->and(DB::table('prc_process_outbox')->whereIn('kind', ['feeder_wake', 'projection'])->whereNotNull('published_at')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->whereIn('kind', ['feeder_wake', 'projection'])->where(fn ($query) => $query->whereNotNull('claim_token')->orWhere('attempts', '>', 0))->count())->toBe(0);

    // Повторный tick ничего не публикует: published_at — единственный признак завершённой доставки.
    expect(app(RelayDaemon::class)->tick()->processed)->toBe(0)->and($publisher->published)->toHaveCount(1);
});

test('publish-before-mark: перехваченный claim даёт duplicate delivery, чужой mark невозможен, outbox остаётся одной строкой', function (): void {
    $work = envelopeAdmitted();
    $takeover = LedgerFixtures::uuid()->value;
    // Relay A опубликовал, но до mark его claim перехватил relay B (новый token, claim ещё жив).
    $first = envelopePublisher(afterPublish: function () use ($takeover): void {
        DB::update(
            "UPDATE prc_process_outbox SET claim_token = ?, claim_until = clock_timestamp() + interval '30 seconds' WHERE kind = 'work_wake'",
            [$takeover],
        );
    });
    $tickA = app(RelayDaemon::class)->tick();
    $afterA = envelopeWakeRow($work);

    expect($first->published)->toHaveCount(1)
        // Mark A отвергнут: token не совпал, сообщение осталось unpublished под claim B.
        ->and($tickA->processed)->toBe(0)
        ->and([$afterA->published_at, $afterA->claim_token])->toBe([null, $takeover]);

    // Claim B истёк до его publish: следующий consumer забирает сообщение заново и публикует duplicate.
    DB::update("UPDATE prc_process_outbox SET claim_until = clock_timestamp() - interval '1 second' WHERE kind = 'work_wake'");
    $second = envelopePublisher();
    $tickB = app(RelayDaemon::class)->tick();
    $afterB = envelopeWakeRow($work);

    expect($tickB->processed)->toBe(1)
        ->and($second->published)->toHaveCount(1)
        ->and([$second->published[0]->messageId->value, $second->published[0]->dispatchGeneration->value])
        ->toBe([$first->published[0]->messageId->value, $first->published[0]->dispatchGeneration->value])
        ->and([$afterB->published_at !== null, $afterB->claim_token, $afterB->attempts])->toBe([true, null, 2])
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'wake:'.$work->value->value.':1')->count())->toBe(1);
});

test('Redis publish недоступен: claim снят, код ошибки виден, сообщение не потеряно и публикуется после восстановления', function (): void {
    $work = envelopeAdmitted();
    $failing = envelopePublisher(new RuntimeException('redis password=secret refused'));

    $tick = app(RelayDaemon::class)->tick();
    $row = envelopeWakeRow($work);

    expect([$tick->processed, $tick->errorCode])->toBe([0, 'redis_publish_failed'])
        ->and($tick->degraded())->toBeTrue()
        ->and([$row->published_at, $row->claim_token, $row->claim_until, $row->last_error_code])->toBe([null, null, null, 'redis_publish_failed']);

    $recovered = envelopePublisher();
    $next = app(RelayDaemon::class)->tick();
    $row = envelopeWakeRow($work);

    expect([$next->processed, $next->errorCode])->toBe([1, null])
        ->and($recovered->published)->toHaveCount(1)
        ->and([$row->published_at !== null, $row->last_error_code])->toBe([true, null]);
});

test('relay при недоступном Redis: delayMs даёт backoff по подряд идущим отказам, а не idle-пулинг; успешный tick снимает backoff', function (): void {
    envelopeAdmitted();
    envelopePublisher(new RuntimeException('redis refused'));
    $relay = app(RelayDaemon::class);
    $policy = app(DaemonPolicy::class);

    // Loop читает паузу сразу после своего tick: счётчик отказов относится к последнему tick.
    $first = $relay->tick();
    $firstDelay = $relay->delayMs($first);
    $second = $relay->tick();
    $secondDelay = $relay->delayMs($second);

    expect([$first->errorCode, $second->errorCode])->toBe(['redis_publish_failed', 'redis_publish_failed'])
        ->and($firstDelay)->toBe($policy->backoffMs(1))
        ->and($secondDelay)->toBe($policy->backoffMs(2));

    envelopePublisher();
    $recovered = app(RelayDaemon::class)->tick();

    expect([$recovered->processed, $recovered->errorCode])->toBe([1, null])
        ->and(app(RelayDaemon::class)->delayMs($recovered))->toBe(0);
});

test('relay не публикует wake неподдерживаемой schema: quarantine с incident, persistent degraded и без потери сообщения', function (): void {
    $message = LedgerFixtures::uuid()->value;
    $unsupported = envelopeRawWake(json_encode([
        'work_id' => LedgerFixtures::uuid()->value, 'dispatch_generation' => 1, 'message_id' => $message, 'queue_schema_version' => 2, 'stage' => 'listing',
    ], JSON_THROW_ON_ERROR));
    $unreadable = envelopeRawWake('{"unexpected":true}');
    $publisher = envelopePublisher();

    $tick = app(RelayDaemon::class)->tick();
    $rows = DB::table('prc_process_outbox')->whereIn('message_id', [$unsupported, $unreadable])->get()->keyBy('message_id');
    $incident = DB::table('prc_process_incidents')->where('kind', 'outbox_quarantine')->get();

    expect($publisher->published)->toBe([])
        ->and([$tick->processed, $tick->errorCode])->toBe([0, 'outbox_quarantined'])
        ->and([$rows[$unsupported]->published_at, $rows[$unsupported]->last_error_code])->toBe([null, 'quarantined_schema_unsupported'])
        ->and([$rows[$unreadable]->published_at, $rows[$unreadable]->last_error_code])->toBe([null, 'quarantined_payload_invalid'])
        ->and([$rows[$unsupported]->claim_token, $rows[$unreadable]->claim_token])->toBe([null, null])
        ->and(DB::selectOne('SELECT available_at > clock_timestamp() + interval \'3000 seconds\' AS delayed FROM prc_process_outbox WHERE message_id = ?', [$unsupported])->delayed)->toBeTrue()
        ->and($incident)->toHaveCount(2)
        ->and($incident->every(fn (object $row): bool => $row->state === 'open'))->toBeTrue();

    // Quarantine не исчезает со следующим tick: degraded остаётся, пока сообщение не обработано кодом.
    $again = app(RelayDaemon::class)->tick();
    expect([$again->processed, $again->errorCode])->toBe([0, 'outbox_quarantined'])->and($publisher->published)->toBe([]);
});

test('feeder_wake coalescing: pending и уже published wake одного 5-секундного bucket остаются одной строкой без ошибки', function (): void {
    $outbox = app(OutboxRepository::class);
    $bucket = static fn (): int => (int) DB::selectOne('SELECT floor(extract(epoch FROM clock_timestamp()) / 5)::bigint AS bucket')->bucket;

    // Граница bucket может пройти между вызовами: тогда повторяем с чистой таблицей, а не ослабляем assertion.
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        DB::table('prc_process_outbox')->where('kind', 'feeder_wake')->delete();
        $before = $bucket();
        $first = LedgerFixtures::uuid();
        $outbox->enqueueFeederWake($first, 'acquisition');
        $outbox->enqueueFeederWake(LedgerFixtures::uuid(), 'acquisition');
        $pending = DB::table('prc_process_outbox')->where('kind', 'feeder_wake')->get();

        $claimed = $outbox->claim(OutboxKind::FeederWake, LedgerFixtures::uuid(), 10, 30);
        $marked = $outbox->markPublished($claimed[0]);
        $outbox->enqueueFeederWake(LedgerFixtures::uuid(), 'acquisition');
        $published = DB::table('prc_process_outbox')->where('kind', 'feeder_wake')->get();
        if ($bucket() !== $before) {
            continue;
        }

        expect($pending)->toHaveCount(1)
            ->and($pending[0]->message_id)->toBe($first->value)
            ->and($pending[0]->dedup_key)->toStartWith('feed:acquisition:')
            ->and($claimed)->toHaveCount(1)
            ->and($marked)->toBeTrue()
            // 0 inserted rows после published — успешное coalescing: ни дубля, ни ошибки, published_at сохранён.
            ->and($published)->toHaveCount(1)
            ->and([$published[0]->message_id, $published[0]->published_at !== null])->toBe([$first->value, true]);

        return;
    }

    throw new RuntimeException('Feeder wake bucket kept changing during the coalescing check.');
});

test('feeder daemon claim-ит только feeder_wake, запускает реальный admission и не трогает work_wake', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    app(OutboxRepository::class)->enqueueFeederWake(LedgerFixtures::uuid(), 'acquisition');
    $daemon = app(FeederDaemon::class);

    $tick = $daemon->tick();
    $wake = DB::table('prc_process_outbox')->where('kind', 'feeder_wake')->sole();
    $workWake = DB::table('prc_process_outbox')->where('kind', 'work_wake')->get();

    expect(ExecutionFixtures::work($work)->state)->toBe('admitted')
        ->and($tick->processed)->toBeGreaterThanOrEqual(2)
        ->and($tick->errorCode)->toBeNull()
        ->and($wake->published_at)->not->toBeNull()
        ->and($workWake)->toHaveCount(1)
        ->and([$workWake[0]->claim_token, $workWake[0]->published_at, $workWake[0]->attempts])->toBe([null, null, 0]);

    // Без wake следующий tick до наступления periodic интервала ничего не делает (нет busy loop).
    expect($daemon->tick()->processed)->toBe(0)->and($daemon->delayMs($daemon->tick()))->toBe(500);
});

test('wake payload разбирается строго: чужие ключи, stage, generation и schema отвергаются codec', function (): void {
    $codec = new ProcessPayloadCodec;
    $valid = [
        'work_id' => LedgerFixtures::uuid()->value, 'dispatch_generation' => 3, 'message_id' => LedgerFixtures::uuid()->value,
        'queue_schema_version' => 1, 'stage' => 'detail',
    ];
    $decode = static fn (array $fields, int $schema = 1): WorkWake => $codec->decodeWorkWake(
        new VersionedPayload(json_encode($fields, JSON_THROW_ON_ERROR), $schema),
    );

    expect($decode($valid)->dispatchGeneration->value)->toBe(3)
        ->and($decode($valid)->stage)->toBe(StageCode::Detail)
        ->and($decode($valid)->envelope()->supported())->toBeTrue()
        ->and($decode([...$valid, 'queue_schema_version' => 2])->envelope()->supported())->toBeFalse()
        ->and(fn () => $decode([...$valid, 'extra' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $decode([...$valid, 'stage' => 'unknown']))->toThrow(InvalidArgumentException::class, 'Unknown stage code')
        ->and(fn () => $decode([...$valid, 'dispatch_generation' => 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $decode($valid, 2))->toThrow(InvalidArgumentException::class, 'Unsupported or absent process schema version');
});
