<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\ListingCursor;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Жизненный цикл прогона: инвариант «один `running` на профиль» владеет
 * частичный unique-индекс БД (`crw_runs_crawler_id_running_unique`) — менеджер
 * ловит нарушение и резолвит гонку, вторую PHP-проверку не заводит.
 */
final readonly class CrawlerRunManager
{
    public function startOrResume(Crawler $crawler): Run
    {
        return DB::transaction(function () use ($crawler): Run {
            $running = Run::query()
                ->where('crawler_id', $crawler->id)
                ->where('status', RunStatus::Running)
                ->first();

            if ($running !== null) {
                return $running;
            }

            $paused = Run::query()
                ->where('crawler_id', $crawler->id)
                ->where('status', RunStatus::Paused)
                ->latest('id')
                ->first();

            if ($paused !== null) {
                $paused->update(['status' => RunStatus::Running, 'last_heartbeat_at' => now()]);

                return $paused;
            }

            try {
                return Run::query()->create([
                    'crawler_id' => $crawler->id,
                    'status' => RunStatus::Running,
                    'cursor' => (new ListingCursor)->toArray(),
                    'started_at' => now(),
                    'last_heartbeat_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return Run::query()
                    ->where('crawler_id', $crawler->id)
                    ->where('status', RunStatus::Running)
                    ->firstOrFail();
            }
        });
    }

    public function heartbeat(Run $run): void
    {
        $run->update(['last_heartbeat_at' => now()]);
    }

    public function updateCursor(Run $run, ListingCursor $cursor): void
    {
        $run->update(['cursor' => $cursor->toArray()]);
    }

    /**
     * Cap-детект fresh-режима (`02-crawling.md` §3.3/§4.1): помечает прогон флагом
     * возможного пропуска, не меняя статус — вызывающий код решает, что делать дальше.
     *
     * @param  array{from: ?string, to: string}  $possibleSkipWindow
     */
    public function flagCapHit(Run $run, array $possibleSkipWindow): void
    {
        $run->update([
            'stats' => array_merge($run->stats?->toArray() ?? [], [
                'cap_hit' => true,
                'possible_skip_window' => $possibleSkipWindow,
            ]),
        ]);
    }

    /**
     * Backlog-рескан (`02-crawling.md` §4.1, D22): единственный state-переход,
     * пометивший flagged fresh-прогон — повторный sweep его больше не отбирает.
     */
    public function markBacklogRescanned(Run $run): void
    {
        $run->update([
            'stats' => array_merge($run->stats?->toArray() ?? [], [
                'backlog_rescanned_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    public function complete(Run $run): void
    {
        DB::transaction(function () use ($run): void {
            $run->update(['status' => RunStatus::Completed, 'finished_at' => now()]);
        });
    }

    public function fail(Run $run, string $error): void
    {
        DB::transaction(function () use ($run, $error): void {
            $run->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'last_error' => $error,
                'last_error_at' => now(),
            ]);
        });
    }

    /**
     * Оборванные `running`-прогоны (протухший heartbeat) → `paused` (resumable),
     * не `failed` — курсор сохраняется для возобновления.
     */
    public function recoverStale(): int
    {
        $timeout = (int) config('crawler.heartbeat_timeout_seconds', 300);

        return Run::query()
            ->where('status', RunStatus::Running)
            ->where('last_heartbeat_at', '<', now()->subSeconds($timeout))
            ->update(['status' => RunStatus::Paused]);
    }
}
