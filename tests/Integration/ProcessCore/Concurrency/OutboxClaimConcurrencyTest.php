<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Execution\ClaimedOutboxMessage;
use App\Enums\Procurement\Process\Core\OutboxKind;
use App\Repositories\Procurement\Process\Work\OutboxRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('delivery-and-daemon-lifecycle', 'daemon-reconciliation');

/**
 * Committed outbox rows (Integration не использует RefreshDatabase): workers видят их на своих
 * соединениях. Raw INSERT моделирует сообщения producers; claim — реальный OutboxRepository.
 *
 * @return list<string> message ids work_wake
 */
function outboxConcurrencyRows(string $prefix, int $count, string $kind = 'work_wake'): array
{
    $ids = [];
    for ($index = 0; $index < $count; $index++) {
        $id = LedgerFixtures::uuid()->value;
        DB::table('prc_process_outbox')->insert([
            'message_id' => $id, 'dedup_key' => $prefix.':'.$kind.':'.$index, 'kind' => $kind, 'payload' => '{}',
            'payload_schema_version' => 1, 'available_at' => DB::raw('clock_timestamp()'),
        ]);
        $ids[] = $id;
    }

    return $ids;
}

/** Worker держит claim-транзакцию открытой, чтобы конкурент реально встретил заблокированные строки. */
function outboxConcurrencyWorker(string $kind, int $batch): string
{
    return str_replace(['__KIND__', '__BATCH__'], [$kind, (string) $batch], <<<'PHP'
        $claimed = \Illuminate\Support\Facades\DB::transaction(function (): array {
            $messages = app(\App\Repositories\Procurement\Process\Work\OutboxRepository::class)->claim(
                \App\Enums\Procurement\Process\Core\OutboxKind::from('__KIND__'),
                new \App\Dto\Procurement\Process\Primitives\ProcessUuid(strtolower((string) \Illuminate\Support\Str::uuid())),
                __BATCH__,
                30,
            );
            \Illuminate\Support\Facades\DB::select('SELECT pg_sleep(0.3)');

            return ['ids' => array_map(static fn ($message): string => $message->messageId->value, $messages), 'returned' => count($messages)];
        });

        return ['ids' => $claimed['ids'], 'returned' => $claimed['returned'], 'backend_pid' => \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
        PHP);
}

test('два relay одновременно claim-ят непересекающиеся work_wake, каждое сообщение ровно одним token, чужой kind не затронут', function (): void {
    $prefix = 't08-claim-'.LedgerFixtures::uuid()->value;
    $mine = outboxConcurrencyRows($prefix, 6);
    $foreign = outboxConcurrencyRows($prefix, 2, 'feeder_wake');

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => outboxConcurrencyWorker(OutboxKind::WorkWake->value, 3),
            'second' => outboxConcurrencyWorker(OutboxKind::WorkWake->value, 3),
        ]);
        $first = array_values(array_intersect($workers['first']['ids'], $mine));
        $second = array_values(array_intersect($workers['second']['ids'], $mine));
        $claimed = DB::table('prc_process_outbox')->whereIn('message_id', $mine)->whereNotNull('claim_token')->get();
        $tokens = $claimed->pluck('claim_token')->unique()->count();

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            // Один и тот же message не достаётся двум relay (SKIP LOCKED + claim_until).
            ->and(array_intersect($workers['first']['ids'], $workers['second']['ids']))->toBe([])
            // Диагностика флейка: при нарушении лимита падение показывает ids и число строк, которое вернул claim().
            ->and(count($workers['first']['ids']) <= 3 ? [] : [$workers['first']['returned'], $workers['first']['ids']])->toBe([])
            ->and(count($workers['second']['ids']) <= 3 ? [] : $workers['second']['ids'])->toBe([])
            ->and(array_intersect($first, $second))->toBe([])
            ->and($claimed)->toHaveCount(count($first) + count($second))
            ->and($claimed->every(fn (object $row): bool => $row->attempts === 1 && $row->published_at === null && $row->claim_until !== null))->toBeTrue()
            ->and($tokens)->toBe(($first === [] ? 0 : 1) + ($second === [] ? 0 : 1))
            // Feeder wake другого kind relay не берёт никогда.
            ->and(DB::table('prc_process_outbox')->whereIn('message_id', $foreign)->where(fn ($query) => $query->whereNotNull('claim_token')->orWhere('attempts', '>', 0))->count())->toBe(0);
    } finally {
        DB::delete('DELETE FROM prc_process_outbox WHERE dedup_key LIKE ?', [$prefix.':%']);
    }
});

test('claim забирает истёкший claim заново, а живой claim другого relay не отнимает', function (): void {
    $prefix = 't08-takeover-'.LedgerFixtures::uuid()->value;
    $mine = outboxConcurrencyRows($prefix, 1);

    $outbox = app(OutboxRepository::class);
    $ownIds = static fn (array $messages): array => array_values(array_filter(
        array_map(static fn (ClaimedOutboxMessage $message): string => $message->messageId->value, $messages),
        static fn (string $id): bool => $id === $mine[0],
    ));

    try {
        // Живой claim другого relay: claim_until в будущем — сообщение не свободно.
        DB::update("UPDATE prc_process_outbox SET claim_token = ?, claim_until = clock_timestamp() + interval '30 seconds' WHERE message_id = ?", [LedgerFixtures::uuid()->value, $mine[0]]);
        $live = $ownIds($outbox->claim(OutboxKind::WorkWake, LedgerFixtures::uuid(), 100, 30));
        DB::update("UPDATE prc_process_outbox SET claim_until = clock_timestamp() - interval '1 second' WHERE message_id = ?", [$mine[0]]);
        $expired = $ownIds($outbox->claim(OutboxKind::WorkWake, LedgerFixtures::uuid(), 100, 30));
        $row = DB::table('prc_process_outbox')->where('message_id', $mine[0])->sole();

        expect($live)->toBe([])
            ->and($expired)->toBe([$mine[0]])
            ->and($row->attempts)->toBe(1)
            ->and($row->claim_token)->not->toBeNull();
    } finally {
        DB::delete('DELETE FROM prc_process_outbox WHERE dedup_key LIKE ?', [$prefix.':%']);
    }
});
