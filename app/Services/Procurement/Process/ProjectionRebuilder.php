<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\MetricTotalRow;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RebuildResult;
use App\Enums\Procurement\Process\ProjectionMode;
use App\Enums\Procurement\Process\RebuildStatus;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;
use App\Exceptions\Procurement\Process\RebuildAborted;
use App\Repositories\Procurement\Process\ProjectionRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Closure;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bounded maintenance rebuild counters одного projector.
 * A) короткая tx: state FOR UPDATE → mode=maintenance + token (ждёт ранее начатые live apply, новые
 * не стартуют). B–E) одна REPEATABLE READ tx: state FOR UPDATE, ровно один MVCC snapshot, никаких
 * locks work/lane/control/provider и сети; новая generation и receipts пишутся в ней же, flip —
 * CAS по token + прежней generation. Любой отказ (размер, deadline, неизвестный факт, нарушенная
 * целостность) откатывает всю tx: прежняя generation и receipts не меняются. G) finally отдельной
 * CAS снимает gate своим token; если процесс погиб, gate виден и снимается `resume` с token.
 * Rebuild не создаёт work, outbox wakeups и внешних эффектов. Cutoff по max(id)/timestamp/sequence
 * не используется: события, committed после snapshot, получают receipt от обычного consumer.
 */
final readonly class ProjectionRebuilder
{
    public function __construct(
        private ProjectionRegistry $registry,
        private ProjectionRepository $projections,
        private ProjectionPolicy $policy,
        private ProcessTransaction $transaction,
        private ProcessClock $clock,
    ) {}

    public function rebuild(string $projectorKey): RebuildResult
    {
        $reducer = $this->registry->get($projectorKey);
        $catalogHash = $this->registry->catalogHash($reducer);
        $this->projections->seed($projectorKey, $catalogHash);
        $token = new ProcessUuid(Str::lower((string) Str::uuid()));

        $refused = $this->transaction->run(function () use ($projectorKey, $catalogHash, $token): ?RebuildStatus {
            $state = $this->projections->stateForUpdate($projectorKey) ?? throw new \LogicException('Projection state vanished after seeding: '.$projectorKey);
            if ($state->mode !== ProjectionMode::Active) {
                return RebuildStatus::Busy;
            }
            if (! $state->historyComplete) {
                return RebuildStatus::HistoryUnavailable;
            }
            if ($state->catalogHash->hex !== $catalogHash->hex) {
                return RebuildStatus::CatalogMismatch;
            }

            return $this->projections->enterMaintenance($projectorKey, $token) ? null : RebuildStatus::Busy;
        });
        if ($refused !== null) {
            return RebuildResult::refused($refused);
        }

        try {
            return $this->build($projectorKey, $token);
        } catch (RebuildAborted $abort) {
            return RebuildResult::refused($abort->status);
        } finally {
            $this->release($projectorKey, $token);
        }
    }

    /** Идемпотентное снятие зависшего gate владельцем token; totals и receipts не меняются, работа не стартует. */
    public function resume(string $projectorKey, ProcessUuid $expectedToken): bool
    {
        $this->registry->get($projectorKey);

        return $this->transaction->run(function () use ($projectorKey, $expectedToken): bool {
            $state = $this->projections->stateForUpdate($projectorKey);
            if ($state === null || $state->mode !== ProjectionMode::Maintenance || $state->maintenanceToken?->value !== $expectedToken->value) {
                return false;
            }

            return $this->projections->releaseMaintenance($projectorKey, $expectedToken);
        });
    }

    private function build(string $projectorKey, ProcessUuid $token): RebuildResult
    {
        $reducer = $this->registry->get($projectorKey);
        $catalogHash = $this->registry->catalogHash($reducer);
        $deadline = $this->clock->monotonicNs() + $this->policy->rebuildDeadlineSeconds * 1_000_000_000;
        $outermost = DB::connection()->transactionLevel() === 0;
        /** @var int|null $baseGeneration generation, прочитанная под state lock; null — snapshot до неё не дошёл */
        $baseGeneration = null;

        try {
            $result = $this->snapshot(function () use ($projectorKey, $token, $reducer, $catalogHash, $deadline, &$baseGeneration): RebuildResult {
                $state = $this->projections->stateForUpdate($projectorKey);
                if ($state === null || $state->mode !== ProjectionMode::Maintenance || $state->maintenanceToken?->value !== $token->value) {
                    throw new RebuildAborted(RebuildStatus::Busy);
                }
                $baseGeneration = $state->activeGeneration;
                if (! $state->historyComplete) {
                    throw new RebuildAborted(RebuildStatus::HistoryUnavailable);
                }
                if ($state->catalogHash->hex !== $catalogHash->hex) {
                    throw new RebuildAborted(RebuildStatus::CatalogMismatch);
                }
                $kinds = $reducer->entityKinds();
                if ($this->projections->countEvents($kinds, $this->policy->rebuildMaxEvents + 1) > $this->policy->rebuildMaxEvents) {
                    throw new RebuildAborted(RebuildStatus::TooLarge);
                }

                $generation = $state->activeGeneration + 1;
                $oldTotals = $this->projections->generationTotals($projectorKey, $state->activeGeneration);
                /** @var array<string, int> $sums ключ `metric\0dimension` */
                $sums = [];
                $processed = 0;
                $unapplied = 0;
                $digest = hash_init('sha256');
                $after = null;
                do {
                    $this->boundByDeadline($deadline);
                    try {
                        $page = $this->projections->eventPage($kinds, $after, $this->policy->rebuildPage);
                    } catch (\InvalidArgumentException) {
                        // Факт ledger не выражается типизированным событием (например, пустое состояние).
                        throw new RebuildAborted(RebuildStatus::UnsupportedFact);
                    }
                    $ids = [];
                    foreach ($page as $event) {
                        if ($event->schemaVersion !== 1) {
                            throw new RebuildAborted(RebuildStatus::UnsupportedFact);
                        }
                        try {
                            $deltas = $reducer->reduce($event);
                        } catch (ProjectionSchemaError) {
                            throw new RebuildAborted(RebuildStatus::UnsupportedFact);
                        }
                        foreach ($deltas as $delta) {
                            $slot = $delta->metricKey."\0".$delta->dimensionKey;
                            $sums[$slot] = ($sums[$slot] ?? 0) + $delta->amount;
                        }
                        hash_update($digest, $event->eventId->value);
                        $ids[] = $event->eventId;
                    }
                    $this->boundByDeadline($deadline);
                    $unapplied += $this->projections->insertReceipts($projectorKey, $ids);
                    $processed += count($page);
                    $after = $ids === [] ? $after : $ids[array_key_last($ids)];
                    $this->boundByDeadline($deadline);
                } while (count($page) === $this->policy->rebuildPage);

                $this->boundByDeadline($deadline);
                if ($this->projections->receiptCount($projectorKey, $kinds) !== $processed) {
                    throw new RebuildAborted(RebuildStatus::Inconsistent);
                }
                $newTotals = [];
                ksort($sums, SORT_STRING);
                foreach ($sums as $slot => $value) {
                    [$metric, $dimension] = explode("\0", (string) $slot, 2);
                    $total = new MetricTotalRow($metric, $dimension, $value);
                    // Общий deadline команды действует и на записи totals: каждый statement ограничен остатком.
                    $this->boundByDeadline($deadline);
                    $this->projections->insertTotal($projectorKey, $generation, $total);
                    $newTotals[] = $total;
                }
                $this->boundByDeadline($deadline);
                if (! $this->projections->publishGeneration($projectorKey, $token, $state->activeGeneration, $generation)) {
                    throw new RebuildAborted(RebuildStatus::Busy);
                }
                // Процесс мог остановиться (GC, descheduling) между последней проверкой и публикацией: statement_timeout
                // меряет каждый statement отдельно и абсолютный deadline не знает. Проверка ПОСЛЕ publish откатывает
                // просроченный flip вместе с новой generation и receipts, пока tx ещё не committed. Окно после неё
                // (возврат closure → COMMIT) закрывает серверный transaction_timeout, см. snapshot().
                $this->assertWithinDeadline($deadline);

                return new RebuildResult(RebuildStatus::Rebuilt, $generation, $processed, $unapplied, hash_final($digest), $oldTotals, $newTotals);
            }, $deadline);
        } catch (\PDOException $failure) {
            // statement_timeout (57014) — тоже bounded отказ по времени, а не частичный flip.
            if (($failure->errorInfo[0] ?? null) === '57014' || $this->endedByDeadline($failure, $deadline, $projectorKey, $token, $baseGeneration, $outermost)) {
                throw new RebuildAborted(RebuildStatus::Timeout);
            }
            throw $failure;
        }

        // Старые totals чистятся отдельной bounded tx под state lock; сбой очистки не отменяет flip.
        $this->transaction->run(function () use ($projectorKey): void {
            $this->projections->stateForUpdate($projectorKey);
            $this->projections->deleteInactiveTotals($projectorKey);
        });

        return $result;
    }

    /**
     * Общий deadline команды: просроченный rebuild откатывается Timeout, а каждый
     * следующий statement ограничен остатком времени, чтобы много быстрых statements не растянули команду.
     */
    private function boundByDeadline(int $deadlineNs): void
    {
        $remainingMs = $this->remainingMs($deadlineNs);
        if ($remainingMs < 1) {
            throw new RebuildAborted(RebuildStatus::Timeout);
        }
        DB::statement('SET LOCAL statement_timeout = '.$remainingMs);
    }

    private function remainingMs(int $deadlineNs): int
    {
        return intdiv($deadlineNs - $this->clock->monotonicNs(), 1_000_000);
    }

    /**
     * Чистая проверка без новых statements: последний клиентский шаг в tx после publish. Окно после неё
     * (возврат closure → COMMIT, в том числе пауза процесса) закрывает только серверный transaction_timeout.
     */
    private function assertWithinDeadline(int $deadlineNs): void
    {
        if ($this->remainingMs($deadlineNs) < 1) {
            throw new RebuildAborted(RebuildStatus::Timeout);
        }
    }

    /**
     * Один MVCC snapshot: REPEATABLE READ до первого запроса, bounded statement/lock timeout. Внутри уже
     * открытой внешней tx (тест с RefreshDatabase) уровень изоляции менять нельзя — snapshot обеспечивает
     * вызывающий; production daemon/command всегда открывает outermost tx.
     *
     * В outermost tx сервер дополнительно получает transaction_timeout (PostgreSQL 17, принятая версия) на остаток
     * общего deadline. Это единственное, что ограничивает паузу процесса ПОСЛЕ последней клиентской проверки: когда
     * таймер истекает, backend завершает сессию (FATAL 25P04, в том числе пока клиент простаивает в tx), tx
     * откатывается, а COMMIT уже не выполняется. Сначала значение сбрасывается в 0 и лишь затем задаётся остаток:
     * таймер, уже взведённый session-level настройкой, SET LOCAL на меньшее значение не перезапускает. Во вложенной
     * tx (тесты с внешней обёрткой) таймер не ставится: SET LOCAL пережил бы release savepoint и убил бы
     * соединение вызывающего по завершении rebuild.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function snapshot(Closure $operation, int $deadlineNs): mixed
    {
        $outermost = DB::connection()->transactionLevel() === 0;

        return DB::transaction(function () use ($operation, $outermost, $deadlineNs): mixed {
            if ($outermost) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $remainingMs = $this->remainingMs($deadlineNs);
                if ($remainingMs < 1) {
                    throw new RebuildAborted(RebuildStatus::Timeout);
                }
                DB::statement('SET LOCAL transaction_timeout = 0');
                DB::statement('SET LOCAL transaction_timeout = '.$remainingMs);
            }
            DB::statement("SET LOCAL lock_timeout = '2s'");
            DB::statement('SET LOCAL statement_timeout = '.($this->policy->rebuildDeadlineSeconds * 1000));

            return $operation();
        }, attempts: 1);
    }

    /**
     * Сессию закрыл сервер по transaction_timeout (SQLSTATE 25P04 или текст ошибки), либо клиент потерял соединение
     * ПОСЛЕ истечения deadline и locked state доказывает, что flip не committed (generation прежняя, gate наш).
     * FOR UPDATE дожидается исхода прежней tx, даже если COMMIT потерял ответ. Любой другой обрыв — неизвестный
     * исход, он пробрасывается без утверждения «Timeout». Только свою outermost tx можно отсоединить: Laravel
     * может сохранить ненулевой transactionLevel после ошибки rollback на уже закрытой сервером сессии.
     */
    private function endedByDeadline(\PDOException $failure, int $deadlineNs, string $projectorKey, ProcessUuid $token, ?int $baseGeneration, bool $outermost): bool
    {
        if (! $outermost) {
            return false;
        }
        $message = $failure->getMessage();
        $marked = ($failure->errorInfo[0] ?? null) === '25P04' || str_contains($message, 'transaction timeout');
        $sqlState = $failure->errorInfo[0] ?? null;
        $lost = (new LostConnectionDetector)->causedByLostConnection($failure)
            || (is_string($sqlState) && str_starts_with($sqlState, '08'))
            || str_contains($message, 'terminating connection')
            || str_contains($message, 'SQLSTATE[08');
        if (! $marked && ! ($lost && $this->remainingMs($deadlineNs) < 1)) {
            return false;
        }

        // Не переиспользуем мёртвый PDO или его transactionLevel; бизнес-rebuild не повторяем.
        DB::purge();
        if ($marked) {
            return true;
        }

        return $baseGeneration !== null && $this->transaction->run(function () use ($projectorKey, $token, $baseGeneration): bool {
            $state = $this->projections->stateForUpdate($projectorKey);

            return $state !== null && $state->activeGeneration === $baseGeneration
                && $state->mode === ProjectionMode::Maintenance && $state->maintenanceToken?->value === $token->value;
        });
    }

    /** Снимает gate только своим token отдельной короткой tx; ошибка снятия оставляет видимый gate для `resume`. */
    private function release(string $projectorKey, ProcessUuid $token): void
    {
        try {
            $this->transaction->run(function () use ($projectorKey, $token): void {
                $this->projections->stateForUpdate($projectorKey);
                $this->projections->releaseMaintenance($projectorKey, $token);
            });
        } catch (\Throwable $failure) {
            ProcessLog::daemonError('project', 'rebuild_gate_release_failed', $failure, ['projector' => $projectorKey]);
        }
    }
}
