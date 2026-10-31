<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Acquisition\IdentityResolver;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\CrawlerRunManager;
use App\Support\Database\PostgresAdvisoryLock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Procurement\SnapshotFactory;

final class IdentityConcurrency
{
    /** @return array<string, int|bool> */
    public static function worker(int $registryId, int $port, string $barrier, bool $first): array
    {
        $registry = Registry::query()->findOrFail($registryId);
        // The fixture server counts actual requests. Fetch precedes the DB publication transaction.
        $body = file_get_contents('http://127.0.0.1:'.$port.'/identity');
        if ($body !== '00042') {
            throw new \RuntimeException('Identity fixture fetch failed.');
        }
        $snapshot = SnapshotFactory::make($registry, ['registryNumber' => $body]);
        $crawler = Crawler::factory()->create(['registry_id' => $registryId]);
        $manager = app(CrawlerRunManager::class);
        $run = $manager->startOrResume($crawler, RunPurpose::Profile, null);
        if (! $manager->acquireExecutionLease($run)) {
            throw new \RuntimeException('Fixture run lease unavailable.');
        }
        $pid = (int) DB::scalar('SELECT pg_backend_pid()');
        if (! $first) {
            self::until(fn (): bool => is_file($barrier.'/held'));
            file_put_contents($barrier.'/waiter', (string) $pid);
        }
        $procurement = $manager->withinExecutionLease($run, function () use ($first, $barrier, $snapshot, $manager, $run) {
            if ($first) {
                app(PostgresAdvisoryLock::class)->acquireProcurement(app(IdentityResolver::class)->resolve($snapshot->registryId, $snapshot->identity));
                file_put_contents($barrier.'/held', '1');
                self::until(fn (): bool => is_file($barrier.'/waiter'));
                $waiter = (int) file_get_contents($barrier.'/waiter');
                self::until(fn (): bool => (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE pid=? AND locktype='advisory' AND NOT granted)", [$waiter]));
            }
            $procurement = app(SnapshotFinalizer::class)->ingest($snapshot, $run->id);
            if (! $manager->updateCursor($run, new ListingCursor(blockFrom: 501))) {
                throw new \RuntimeException('Cursor did not commit.');
            }

            return $procurement;
        });

        return ['procurement_id' => $procurement->id, 'run_id' => $run->id, 'pid' => $pid, 'committed' => true];
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
