<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\Crawler\CrawlerRunBusyException;
use App\Exceptions\Procurement\Crawler\ExecutionLeaseLost;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelData\Data;
use WeakMap;

/**
 * Жизненный цикл прогона: частичный unique-индекс БД сохраняет один `running`
 * на crawler, а purpose/subject_key определяют владельца его курсора.
 */
final class CrawlerRunManager
{
    /**
     * A processor-only capability. It is deliberately not persisted on the model,
     * so loading the same run in another PHP process does not grant its lease.
     *
     * @var WeakMap<Run, string>|null
     */
    private static ?WeakMap $executionOwnerTokens = null;

    // 600 seconds exceeds the 240-second checkpoint slice and renews after every archive block.
    private const ExecutionLeaseSeconds = 600;

    public function startOrResume(Crawler $crawler, RunPurpose $purpose, ?string $subjectKey): Run
    {
        return DB::transaction(function () use ($crawler, $purpose, $subjectKey): Run {
            $lockedCrawler = Crawler::query()->lockForUpdate()->findOrFail($crawler->id);
            $running = Run::query()
                ->where('crawler_id', $lockedCrawler->id)
                ->where('status', RunStatus::Running)
                ->first();

            if ($running !== null) {
                return $this->ownerMatches(run: $running, purpose: $purpose, subjectKey: $subjectKey)
                    ? $running
                    : throw CrawlerRunBusyException::forRun(activeRun: $running);
            }

            $paused = Run::query()
                ->where('crawler_id', $lockedCrawler->id)
                ->where('status', RunStatus::Paused)
                ->where('purpose', $purpose)
                ->when($subjectKey === null, fn ($query) => $query->whereNull('subject_key'))
                ->when($subjectKey !== null, fn ($query) => $query->where('subject_key', $subjectKey))
                ->latest('id')
                ->first();

            if ($paused !== null) {
                Run::query()->whereKey($paused->getKey())->update([
                    'status' => RunStatus::Running,
                    'last_heartbeat_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);

                return $paused->refresh();
            }

            try {
                return DB::transaction(function () use ($lockedCrawler, $purpose, $subjectKey): Run {
                    $run = Run::query()->create([
                        'crawler_id' => $lockedCrawler->id,
                        'status' => RunStatus::Running,
                        'purpose' => $purpose,
                        'subject_key' => $subjectKey,
                        'cursor' => (new ListingCursor)->toArray(),
                        'started_at' => now(),
                        'last_heartbeat_at' => now(),
                    ]);

                    Run::query()->whereKey($run->getKey())->update([
                        'started_at' => DB::raw('CURRENT_TIMESTAMP'),
                        'last_heartbeat_at' => DB::raw('CURRENT_TIMESTAMP'),
                    ]);

                    return $run->refresh();
                });
            } catch (UniqueConstraintViolationException) {
                $winner = Run::query()
                    ->where('crawler_id', $lockedCrawler->id)
                    ->where('status', RunStatus::Running)
                    ->firstOrFail();

                return $this->ownerMatches(run: $winner, purpose: $purpose, subjectKey: $subjectKey)
                    ? $winner
                    : throw CrawlerRunBusyException::forRun(activeRun: $winner);
            }
        });
    }

    private function ownerMatches(Run $run, RunPurpose $purpose, ?string $subjectKey): bool
    {
        return $run->purpose === $purpose && $run->subject_key === $subjectKey;
    }

    public function acquireExecutionLease(Run $run): bool
    {
        $token = (string) Str::uuid();

        $acquired = Run::query()
            ->whereKey($run->getKey())
            ->where('status', RunStatus::Running)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('execution_owner_token')
                    ->orWhereNull('execution_lease_expires_at')
                    ->orWhereRaw('execution_lease_expires_at <= CURRENT_TIMESTAMP');
            })
            ->update([
                'execution_owner_token' => $token,
                'execution_lease_expires_at' => $this->executionLeaseExpiry(),
                'last_heartbeat_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);

        $run->refresh();

        if ($acquired !== 1) {
            return false;
        }

        $this->executionOwnerTokens()[$run] = $token;

        return true;
    }

    public function heartbeat(Run $run): void
    {
        if ($this->executionOwnerToken(run: $run) === null) {
            Run::query()
                ->whereKey($run->getKey())
                ->where('status', RunStatus::Running)
                ->whereNull('execution_owner_token')
                ->update(['last_heartbeat_at' => DB::raw('CURRENT_TIMESTAMP')]);

            $run->refresh();

            return;
        }

        if (! $this->renewExecutionLease(run: $run)) {
            throw ExecutionLeaseLost::noLongerOwned();
        }
    }

    /**
     * Fences one response-to-checkpoint write section. The lease is renewed only after the
     * row lock is held; a contender can neither take over nor observe a partial block until
     * ingestion, stats, and cursor are committed together.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withinExecutionLease(Run $run, Closure $callback): mixed
    {
        $token = $this->executionOwnerToken(run: $run);

        return DB::transaction(function () use ($callback, $run, $token): mixed {
            $lockedRun = Run::query()->lockForUpdate()->findOrFail($run->getKey());

            if (! $lockedRun instanceof Run) {
                throw new RuntimeException('Locked crawler run is invalid.');
            }

            if ($token === null && $lockedRun->execution_owner_token !== null) {
                throw ExecutionLeaseLost::notOwned();
            }

            if ($token !== null && ! $this->renewExecutionLease(run: $run)) {
                throw ExecutionLeaseLost::noLongerOwned();
            }

            return $callback();
        });
    }

    public function updateCursor(Run $run, ListingCursor $cursor): bool
    {
        $values = ['cursor' => $cursor->toArray()];

        if ($run->isDirty('stats')) {
            $values['stats'] = $run->stats?->toArray();
        }

        $updated = $this->ownedRunningQuery(run: $run)->update($values);

        $run->refresh();

        if ($updated !== 1 && $this->executionOwnerToken(run: $run) !== null) {
            throw ExecutionLeaseLost::noLongerOwned();
        }

        return $updated === 1;
    }

    /**
     * Cap-детект fresh-режима (`02-crawling.md` §3.3/§4.1): помечает прогон флагом
     * возможного пропуска, не меняя статус — вызывающий код решает, что делать дальше.
     *
     * @param  array{from: ?string, to: string}  $possibleSkipWindow
     */
    public function flagCapHit(Run $run, array $possibleSkipWindow): void
    {
        $this->updateStats(run: $run, stats: array_merge($run->stats?->toArray() ?? [], [
            'cap_hit' => true,
            'possible_skip_window' => $possibleSkipWindow,
        ]));
    }

    /**
     * Backlog-рескан (`02-crawling.md` §4.1, D22): единственный state-переход,
     * пометивший flagged fresh-прогон — повторный sweep его больше не отбирает.
     */
    public function markBacklogRescanned(Run $run): void
    {
        $this->updateStats(run: $run, stats: array_merge($run->stats?->toArray() ?? [], [
            'backlog_rescanned_at' => now()->toIso8601String(),
        ]));
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
        $gapSeconds = $run->started_at !== null && $previousCompletedRun?->finished_at !== null
            ? $run->started_at->diffInSeconds($previousCompletedRun->finished_at)
            : null;

        $this->updateStats(run: $run, stats: array_merge($run->stats?->toArray() ?? [], [
            'scan_gap_seconds' => $gapSeconds,
        ]));
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
        $currentAt = is_string($current) ? Carbon::parse($current) : null;

        if ($currentAt !== null && $candidate->gte($currentAt)) {
            return;
        }

        $this->updateStats(run: $run, stats: array_merge($run->stats?->toArray() ?? [], [
            'oldest_update_seen' => $candidate->toIso8601String(),
        ]));
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
    public function complete(Run $run): bool
    {
        $updated = $this->ownedRunningQuery(run: $run)->update([
            'status' => RunStatus::Completed,
            'finished_at' => DB::raw('CURRENT_TIMESTAMP'),
            'execution_owner_token' => null,
            'execution_lease_expires_at' => null,
        ]);

        $run->refresh();
        $this->forgetExecutionOwnerToken(run: $run);

        return $updated === 1;
    }

    /**
     * Сигнал `Pause` стоп-гейта (P3.2, D28): курсор к этому моменту уже сохранён вызывающим
     * циклом, статус — `paused` (resumable), запрос остановки гасится, чтобы `startOrResume()`
     * следующего запуска не увидел его снова.
     */
    /**
     * @param  array<string, mixed>  $extraStats  метки причины паузы (например, `source_suspended`)
     */
    public function pause(Run $run, string $reason, array $extraStats = []): void
    {
        $this->ownedRunningQuery(run: $run)->update([
            'status' => RunStatus::Paused,
            'stats' => array_merge($run->stats?->toArray() ?? [], [
                'stop_reason' => $reason,
                'automatic_recovery_pending' => false,
            ], $extraStats),
            'stop_requested_at' => null,
            'stop_kind' => null,
            'stop_requested_by' => null,
            'execution_owner_token' => null,
            'execution_lease_expires_at' => null,
        ]);

        $run->refresh();
        $this->forgetExecutionOwnerToken(run: $run);
    }

    /**
     * Сигнал `Cancel` стоп-гейта (P3.2, D28): терминальный статус, `finished_at` проставляется
     * тем же приёмом, что `complete()`/`fail()`; запрос остановки гасится.
     */
    public function cancel(Run $run, string $reason): void
    {
        $this->ownedRunningQuery(run: $run)->update([
            'status' => RunStatus::Cancelled,
            'finished_at' => DB::raw('CURRENT_TIMESTAMP'),
            'stats' => array_merge($run->stats?->toArray() ?? [], ['stop_reason' => $reason]),
            'stop_requested_at' => null,
            'stop_kind' => null,
            'stop_requested_by' => null,
            'execution_owner_token' => null,
            'execution_lease_expires_at' => null,
        ]);

        $run->refresh();
        $this->forgetExecutionOwnerToken(run: $run);
    }

    /**
     * Бюджет одного запуска исчерпан (P3.2, `crawler.run.checkpoint_seconds`): курсор уже
     * сохранён вызывающим циклом, статус ОСТАЁТСЯ `running` — это и есть сигнал для
     * `CrawlRunJob` самодиспатчить продолжение. Счётчик — только наблюдаемость.
     */
    public function recordCheckpoint(Run $run): void
    {
        $checkpoints = $run->stats?->toArray()['checkpoints'] ?? 0;

        $this->ownedRunningQuery(run: $run)->update([
            'stats' => array_merge($run->stats?->toArray() ?? [], [
                'checkpoints' => (is_int($checkpoints) ? $checkpoints : 0) + 1,
            ]),
        ]);

        $run->refresh();
    }

    /** @return array{mode: string, execution_lane: string, from: string|null, to: string|null, filter_snapshot: array<string, mixed>|null} */
    public function recordExecutionContext(
        Run $run,
        Crawler $crawler,
        CrawlerMode $mode,
        CrawlerExecutionLane $executionLane,
        ?string $from,
        ?string $to,
    ): array {
        DB::transaction(function () use ($crawler, $executionLane, $from, $mode, $run, $to): void {
            $lockedRun = Run::query()->lockForUpdate()->findOrFail($run->getKey());

            if (! $lockedRun instanceof Run) {
                throw new RuntimeException('Locked crawler run is invalid.');
            }

            $stats = $lockedRun->stats?->toArray() ?? [];
            $existing = $stats['execution_context'] ?? null;
            if (is_array($existing) && ($existing['mode'] ?? null) === CrawlerMode::Archive->value) {
                $executionContext = $existing;

                if (! array_key_exists('filter_snapshot', $executionContext)) {
                    $executionContext['filter_snapshot'] = $this->filterSnapshot(crawler: $crawler);
                }
            } else {
                $executionContext = [
                    'mode' => $mode->value,
                    'execution_lane' => $executionLane->value,
                    'from' => $from,
                    'to' => $to,
                    'filter_snapshot' => $mode === CrawlerMode::Archive
                        ? $this->filterSnapshot(crawler: $crawler)
                        : null,
                ];
            }

            $updated = Run::query()
                ->whereKey($lockedRun->getKey())
                ->whereNull('execution_owner_token')
                ->update(['stats' => array_merge($stats, [
                    'execution_context' => $executionContext,
                    'automatic_recovery_pending' => false,
                ])]);

            if ($updated !== 1) {
                throw new RuntimeException('Crawler run execution context cannot be changed while another owner is active.');
            }
        });

        $run->refresh();

        $context = $run->stats?->toArray()['execution_context'] ?? null;

        if (! is_array($context)
            || ! is_string($context['mode'] ?? null)
            || ! is_string($context['execution_lane'] ?? null)
            || (! is_string($context['from'] ?? null) && ($context['from'] ?? null) !== null)
            || (! is_string($context['to'] ?? null) && ($context['to'] ?? null) !== null)
            || ! array_key_exists('filter_snapshot', $context)
            || (! is_array($context['filter_snapshot']) && $context['filter_snapshot'] !== null)) {
            throw new RuntimeException('Crawler run execution context was not persisted.');
        }

        return [
            'mode' => $context['mode'],
            'execution_lane' => $context['execution_lane'],
            'from' => $context['from'] ?? null,
            'to' => $context['to'] ?? null,
            'filter_snapshot' => is_array($context['filter_snapshot'])
                ? $this->stringKeyedArray($context['filter_snapshot'])
                : null,
        ];
    }

    public function crawlerFromExecutionContext(Run $run, Crawler $crawler): Crawler
    {
        $context = $run->stats?->toArray()['execution_context'] ?? null;

        if (! is_array($context) || ($context['mode'] ?? null) !== CrawlerMode::Archive->value
            || ! array_key_exists('filter_snapshot', $context)) {
            return $crawler;
        }

        $snapshot = $context['filter_snapshot'];

        if ($snapshot !== null && ! is_array($snapshot)) {
            throw new RuntimeException('Archive crawler filter snapshot must be an object or null.');
        }

        $executionCrawler = clone $crawler;
        $executionCrawler->filter = is_array($snapshot) ? $this->stringKeyedArray($snapshot) : null;

        if ($snapshot !== null && ! $executionCrawler->filter instanceof Data) {
            throw new RuntimeException('Archive crawler filter snapshot could not be restored as a typed filter.');
        }

        return $executionCrawler;
    }

    /** @return array<string, mixed>|null */
    private function filterSnapshot(Crawler $crawler): ?array
    {
        $filter = $crawler->filter;

        return $filter instanceof Data ? $this->stringKeyedArray($filter->toArray()) : null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Crawler filter snapshot must use string keys.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /** @param array<mixed> $stats */
    public function updateStats(Run $run, array $stats): bool
    {
        $updated = $this->ownedQuery(run: $run)->update(['stats' => $stats]);

        $run->refresh();

        return $updated === 1;
    }

    public function fail(Run $run, string $error): void
    {
        $this->ownedRunningQuery(run: $run)->update([
            'status' => RunStatus::Failed,
            'finished_at' => now(),
            'last_error' => $error,
            'last_error_at' => now(),
            'execution_owner_token' => null,
            'execution_lease_expires_at' => null,
        ]);

        $run->refresh();
        $this->forgetExecutionOwnerToken(run: $run);
    }

    public function releaseExecutionLease(Run $run): bool
    {
        $token = $this->executionOwnerToken(run: $run);

        if ($token === null) {
            return false;
        }

        $released = Run::query()
            ->whereKey($run->getKey())
            ->where('execution_owner_token', $token)
            ->update([
                'execution_owner_token' => null,
                'execution_lease_expires_at' => null,
            ]);

        $run->refresh();
        $this->forgetExecutionOwnerToken(run: $run);

        return $released === 1;
    }

    private function renewExecutionLease(Run $run): bool
    {
        $token = $this->executionOwnerToken(run: $run);

        if ($token === null) {
            return false;
        }

        $renewed = Run::query()
            ->whereKey($run->getKey())
            ->where('status', RunStatus::Running)
            ->where('execution_owner_token', $token)
            ->whereRaw('execution_lease_expires_at > CURRENT_TIMESTAMP')
            ->update([
                'execution_lease_expires_at' => $this->executionLeaseExpiry(),
                'last_heartbeat_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);

        $run->refresh();

        if ($renewed !== 1) {
            $this->forgetExecutionOwnerToken(run: $run);
        }

        return $renewed === 1;
    }

    /** @return Builder<Run> */
    private function ownedRunningQuery(Run $run): Builder
    {
        return $this->ownedQuery(run: $run)->where('status', RunStatus::Running);
    }

    /** @return Builder<Run> */
    private function ownedQuery(Run $run): Builder
    {
        $query = Run::query()->whereKey($run->getKey());
        $token = $this->executionOwnerToken(run: $run);

        if ($token === null) {
            return $query->whereNull('execution_owner_token');
        }

        return $query
            ->where('execution_owner_token', $token)
            ->whereRaw('execution_lease_expires_at > CURRENT_TIMESTAMP');
    }

    private function executionLeaseExpiry(): Expression
    {
        return DB::raw("CURRENT_TIMESTAMP + INTERVAL '".self::ExecutionLeaseSeconds." seconds'");
    }

    /** @return WeakMap<Run, string> */
    private function executionOwnerTokens(): WeakMap
    {
        return self::$executionOwnerTokens ??= new WeakMap;
    }

    private function executionOwnerToken(Run $run): ?string
    {
        return $this->executionOwnerTokens()[$run] ?? null;
    }

    private function forgetExecutionOwnerToken(Run $run): void
    {
        unset($this->executionOwnerTokens()[$run]);
    }

    /**
     * Оборванные `running`-прогоны (протухший heartbeat) → `paused` (resumable),
     * не `failed` — курсор сохраняется для возобновления.
     */
    public function recoverStale(): int
    {
        $timeout = config('crawler.heartbeat_timeout_seconds', 300);

        if (! is_int($timeout)) {
            throw new \UnexpectedValueException('crawler.heartbeat_timeout_seconds must be an integer.');
        }

        $staleRuns = Run::query()
            ->where('status', RunStatus::Running)
            ->whereRaw(
                sql: "last_heartbeat_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 second')",
                bindings: [$timeout],
            )
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('execution_owner_token')
                    ->orWhereNull('execution_lease_expires_at')
                    ->orWhereRaw('execution_lease_expires_at <= CURRENT_TIMESTAMP');
            })
            ->get();

        $recovered = 0;

        foreach ($staleRuns as $run) {
            $stats = $run->stats?->toArray() ?? [];
            $executionContext = $stats['execution_context'] ?? null;
            $isArchive = is_array($executionContext)
                && ($executionContext['mode'] ?? null) === CrawlerMode::Archive->value;

            $recovered += Run::query()
                ->whereKey($run->getKey())
                ->where('status', RunStatus::Running)
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('execution_owner_token')
                        ->orWhereNull('execution_lease_expires_at')
                        ->orWhereRaw('execution_lease_expires_at <= CURRENT_TIMESTAMP');
                })
                ->update([
                    'status' => RunStatus::Paused,
                    'stats' => array_merge($stats, [
                        'stop_reason' => 'stale_execution_recovery',
                        'automatic_recovery_pending' => $isArchive,
                    ]),
                    'execution_owner_token' => null,
                    'execution_lease_expires_at' => null,
                ]);
        }

        return $recovered;
    }

    /** @return Collection<int, Run> */
    public function recoverableArchiveRuns(): Collection
    {
        return Run::query()
            ->where('status', RunStatus::Paused)
            ->where('stats->automatic_recovery_pending', true)
            ->where('stats->execution_context->mode', CrawlerMode::Archive->value)
            ->orderBy('id')
            ->get();
    }
}
