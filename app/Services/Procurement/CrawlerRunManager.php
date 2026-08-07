<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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

    /**
     * Последний завершённый прогон профиля (`02-crawling.md` §4.1) — единственный носитель
     * этого запроса (П-4, D33: снятие второго носителя одного факта): раньше жил только внутри
     * `FreshRunProcessor::handleCapHit()`, теперь его же использует `recordScanGap()`.
     */
    public function lastCompletedRun(Crawler $crawler, ?int $excludeRunId = null): ?Run
    {
        return Run::query()
            ->where('crawler_id', $crawler->id)
            ->where('status', RunStatus::Completed)
            ->when($excludeRunId !== null, fn ($query) => $query->where('id', '!=', $excludeRunId))
            ->latest('finished_at')
            ->first();
    }

    /**
     * Метрика отставания fresh-скана (П-4, `research/05-change-tracking.md` §3.1): пауза от
     * `finished_at` предыдущего completed-прогона профиля до старта текущего. Нет предыдущего
     * прогона (первый скан профиля) — `null`, не `0`: отставание не измерено, а не нулевое.
     */
    public function recordScanGap(Run $run, ?Run $previousCompletedRun): void
    {
        $gapSeconds = $previousCompletedRun?->finished_at !== null
            ? $run->started_at->diffInSeconds($previousCompletedRun->finished_at)
            : null;

        $run->update([
            'stats' => array_merge($run->stats?->toArray() ?? [], [
                'scan_gap_seconds' => $gapSeconds,
            ]),
        ]);
    }

    /**
     * Минимальная «Дата обновления» среди СНЯТЫХ блоков прогона (П-4) — источник каждого
     * кандидата: `FetchResult::$oldestUpdateSeen`, заполненное инжестором из уже разобранных
     * снапшотов. Пишется только fresh-прогоном (Implementation Rules П-4); архив и delta-scan
     * эту метрику не наполняют — вызывающий код их не зовёт.
     */
    public function recordOldestUpdateSeen(Run $run, Carbon $candidate): void
    {
        $current = $run->stats?->toArray()['oldest_update_seen'] ?? null;
        $currentAt = $current !== null ? Carbon::parse($current) : null;

        if ($currentAt !== null && $candidate->gte($currentAt)) {
            return;
        }

        $run->update([
            'stats' => array_merge($run->stats?->toArray() ?? [], [
                'oldest_update_seen' => $candidate->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Переводит прогон в `Completed` ТОЛЬКО из `Running` (P3.7, D111): владелец состояния
     * прогона один, вторую проверку статуса перед вызовом обёртки не заводят — вызывающий цикл
     * мог быть остановлен кооперативно между итерациями, и такой прогон уже носит
     * `Paused`/`Cancelled`. На любом другом статусе — тихий идемпотентный no-op (условие
     * проверяется в самом `UPDATE`, а не через ранее прочитанный `$run->status`, иначе гонка
     * между чтением и записью осталась бы открытой). `$run->refresh()` после транзакции
     * синхронизирует переданный инстанс с фактической строкой — вызывающий код (все три
     * обёртки) возвращает этот же `$run` наружу.
     */
    public function complete(Run $run): void
    {
        DB::transaction(function () use ($run): void {
            Run::query()
                ->whereKey($run->getKey())
                ->where('status', RunStatus::Running)
                ->update(['status' => RunStatus::Completed, 'finished_at' => now()]);
        });

        $run->refresh();
    }

    /**
     * Сигнал `Pause` стоп-гейта (P3.2, D28): курсор к этому моменту уже сохранён вызывающим
     * циклом, статус — `paused` (resumable), запрос остановки гасится, чтобы `startOrResume()`
     * следующего запуска не увидел его снова.
     */
    public function pause(Run $run, string $reason): void
    {
        DB::transaction(function () use ($run, $reason): void {
            $run->update([
                'status' => RunStatus::Paused,
                'stats' => array_merge($run->stats?->toArray() ?? [], ['stop_reason' => $reason]),
                'stop_requested_at' => null,
                'stop_kind' => null,
                'stop_requested_by' => null,
            ]);
        });
    }

    /**
     * Сигнал `Cancel` стоп-гейта (P3.2, D28): терминальный статус, `finished_at` проставляется
     * тем же приёмом, что `complete()`/`fail()`; запрос остановки гасится.
     */
    public function cancel(Run $run, string $reason): void
    {
        DB::transaction(function () use ($run, $reason): void {
            $run->update([
                'status' => RunStatus::Cancelled,
                'finished_at' => now(),
                'stats' => array_merge($run->stats?->toArray() ?? [], ['stop_reason' => $reason]),
                'stop_requested_at' => null,
                'stop_kind' => null,
                'stop_requested_by' => null,
            ]);
        });
    }

    /**
     * Бюджет одного запуска исчерпан (P3.2, `crawler.run.checkpoint_seconds`): курсор уже
     * сохранён вызывающим циклом, статус ОСТАЁТСЯ `running` — это и есть сигнал для
     * `CrawlRunJob` самодиспатчить продолжение. Счётчик — только наблюдаемость.
     */
    public function recordCheckpoint(Run $run): void
    {
        $run->update([
            'stats' => array_merge($run->stats?->toArray() ?? [], [
                'checkpoints' => (int) ($run->stats?->toArray()['checkpoints'] ?? 0) + 1,
            ]),
        ]);
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
