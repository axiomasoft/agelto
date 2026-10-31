<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;

final class IdentityConcurrency
{
    /** @return array<string, int|string|bool> */
    public static function worker(int $registryId, int $port, string $barrier, bool $first): array
    {
        $registry = Registry::query()->findOrFail($registryId);
        // Exactly one real HTTP read per worker, before any publication transaction.
        $body = file_get_contents('http://127.0.0.1:'.$port.'/identity');
        if ($body !== '00042') {
            throw new \RuntimeException('Identity fixture fetch failed.');
        }
        ListingFixtures::bindLocal();
        $f = ListingFixtures::run(registry: $registry);
        $lease = FinalizeFixtures::running($f['work']);
        $pid = LedgerRow::one('SELECT pg_backend_pid() AS pid')?->int('pid')
            ?? throw new \LogicException('Missing PostgreSQL backend identity.');
        if ($first) {
            self::until(fn (): bool => is_file($barrier.'/waiter'));
        } else {
            // Both real attempts must be admitted before the holder takes the prefix.
            file_put_contents($barrier.'/waiter', (string) $pid);
            self::until(fn (): bool => is_file($barrier.'/held'));
        }
        $result = new StageSucceeded(new ListingResult(app(ListingStoreRepository::class)->task($f['input']),
            ListingFixtures::page([ListingFixtures::summary($body)])));
        DB::transaction(function () use ($first, $barrier, $lease, $result): void {
            $finished = app(WorkFinalizer::class)->finish($lease, $result);
            if ($finished->status !== CompletionStatus::Applied) {
                throw new \RuntimeException('Listing publication did not apply.');
            }
            if ($first) {
                file_put_contents($barrier.'/held', '1');
                $waiter = (int) file_get_contents($barrier.'/waiter');
                // The full Process prefix may serialize at capacity before identity.
                // Inspect PostgreSQL's actual wait; neither handler nor persistence is mocked.
                self::until(fn (): bool => (bool) DB::scalar('SELECT EXISTS(SELECT 1 FROM pg_locks WHERE pid=? AND NOT granted)', [$waiter]));
            }
        });
        $procurement = Procurement::query()->where('registry_id', $registryId)->where('source_identity_value', $body)->sole();

        return ['procurement_id' => $procurement->id, 'run_id' => $f['run']->value,
            'work_id' => $f['work']->value->value, 'pid' => $pid, 'committed' => true];
    }

    private static function until(\Closure $predicate): void
    {
        $deadline = microtime(true) + 10;
        while (! $predicate()) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Identity barrier timed out.');
            }
            usleep(1000);
        }
    }
}
