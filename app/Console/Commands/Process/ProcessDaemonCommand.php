<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Governance\DaemonHealth;
use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Daemons\DaemonLoop;
use App\Services\Procurement\Process\Daemons\DaemonReadiness;
use App\Services\Procurement\Process\Daemons\FeederDaemon;
use App\Services\Procurement\Process\Daemons\ProjectorDaemon;
use App\Services\Procurement\Process\Daemons\ReconcileDaemon;
use App\Services\Procurement\Process\Daemons\RecoveryReconciler;
use App\Services\Procurement\Process\Daemons\RelayDaemon;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use App\Services\Procurement\Process\Execution\StageQueueMap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Долгоживущий Process daemon: `process:daemon relay|reconcile|feed|project`, свой
 * instance UUID на каждый start; docker перезапускает процесс после SIGTERM/max runtime/memory limit.
 * Project активирован вместе с registry reducers, receipts и totals: он claim-ит только
 * kind projection. `--health` печатает readiness daemon по тому же правилу, что ApplicationReadiness
 * (liveness: свежий heartbeat, readiness: нет текущей ошибки) и завершается кодом 0 только при
 * Healthy — это healthcheck compose-сервиса.
 */
#[Description('Запускает Process daemon (relay, reconcile, feed, project) либо печатает его readiness (--health)')]
#[AsCommand(name: 'process:daemon')]
#[Signature('process:daemon {daemon : relay|reconcile|feed|project} {--health : Показать readiness daemon и выйти}')]
final class ProcessDaemonCommand extends Command
{
    private bool $stopping = false;

    public function handle(DaemonReadiness $readiness, DaemonLoop $loop): int
    {
        $kind = DaemonKind::tryFrom((string) $this->argument('daemon'));
        if ($kind === null) {
            $this->error('Неизвестный daemon. Допустимо: '.implode(', ', array_map(static fn (DaemonKind $case): string => $case->value, DaemonKind::activeKinds())).'.');

            return self::INVALID;
        }
        if ($this->option('health')) {
            $health = $readiness->of($kind);
            $this->line("{$kind->value}: {$health->value}");

            return $health === DaemonHealth::Healthy ? self::SUCCESS : self::FAILURE;
        }

        // Fail fast: несогласованная карта очередей/Horizon не должна молча публиковать wake.
        app(StageQueueMap::class);
        $daemon = $this->daemon($kind);
        if ($kind === DaemonKind::Feed || $kind === DaemonKind::Reconcile) {
            // Control scopes и lanes существуют до первого admission; recovery и
            // fault detector берут тот же prefix и не создают отсутствующие authority rows молча.
            app(ProcessAuthoritySync::class)->ensureBaseline();
        }
        $this->trapSignals();
        $instance = new ProcessUuid(Str::lower((string) Str::uuid()));
        $this->info("Process daemon {$kind->value} запущен: instance {$instance->value}.");
        DB::disableQueryLog();
        $reason = $loop->run($daemon, $instance, fn (): bool => $this->stopping);
        $this->info("Process daemon {$kind->value} остановлен: {$reason}.");

        return self::SUCCESS;
    }

    private function daemon(DaemonKind $kind): ProcessDaemon
    {
        return match ($kind) {
            DaemonKind::Relay => app(RelayDaemon::class),
            DaemonKind::Reconcile => $this->reconcileDaemon(),
            DaemonKind::Feed => app(FeederDaemon::class),
            DaemonKind::Project => app(ProjectorDaemon::class),
        };
    }

    /**
     * Категории RecoveryReconciler: expired running/admitted, release blocked, независимая
     * blocked-provider категория и historical handler-fault sweep. Без регистрации daemon
     * ничего бы не восстанавливал, поэтому она часть запуска, а не опция.
     */
    private function reconcileDaemon(): ReconcileDaemon
    {
        $daemon = app(ReconcileDaemon::class);
        foreach (app(RecoveryReconciler::class)->categories() as $category) {
            $daemon->register($category);
        }

        return $daemon;
    }

    /** TERM/INT/QUIT ставят флаг: текущий короткий tick завершается, выход 0. */
    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            $this->warn('pcntl недоступен: graceful stop по сигналу не установлен.');

            return;
        }
        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopping = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        if (defined('SIGQUIT')) {
            pcntl_signal(SIGQUIT, $stop);
        }
    }
}
