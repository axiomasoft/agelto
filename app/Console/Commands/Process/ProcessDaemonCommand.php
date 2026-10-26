<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\DaemonHealth;
use App\Enums\Procurement\Process\DaemonKind;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\DaemonLoop;
use App\Services\Procurement\Process\DaemonReadiness;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\RelayDaemon;
use App\Services\Procurement\Process\StageQueueMap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Долгоживущий Process daemon (bp-daemons): `process:daemon relay|reconcile|feed`, свой instance
 * UUID на каждый start; docker перезапускает процесс после SIGTERM/max runtime/memory limit.
 * Режим project принадлежит P1.14–P1.18: до его активации команда project не запускает и не
 * считает готовым (registered command не равен activated projector). `--health` печатает readiness
 * daemon по тому же правилу, что ApplicationReadiness (liveness: свежий heartbeat, readiness: нет
 * текущей ошибки) и завершается кодом 0 только при Healthy — это healthcheck compose-сервиса.
 */
#[Description('Запускает Process daemon (relay, reconcile, feed) либо печатает его readiness (--health)')]
#[Signature('process:daemon {daemon : relay|reconcile|feed} {--health : Показать readiness daemon и выйти}')]
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
        if (! $kind->activated()) {
            $this->error("Daemon {$kind->value} не активирован: его reducers и receipts вводит P1.14–P1.18.");

            return self::FAILURE;
        }
        if ($this->option('health')) {
            $health = $readiness->of($kind);
            $this->line("{$kind->value}: {$health->value}");

            return $health === DaemonHealth::Healthy ? self::SUCCESS : self::FAILURE;
        }

        // Fail fast: несогласованная карта очередей/Horizon не должна молча публиковать wake.
        app(StageQueueMap::class);
        $daemon = $this->daemon($kind);
        if ($kind === DaemonKind::Feed) {
            // Control scopes и lanes существуют до первого admission (execution#s11).
            app(ProcessAuthoritySync::class)->ensureBaseline();
        }
        $this->trapSignals();
        $instance = new ProcessUuid(Str::lower((string) Str::uuid()));
        $this->info("Process daemon {$kind->value} запущен: instance {$instance->value}.");
        $reason = $loop->run($daemon, $instance, fn (): bool => $this->stopping);
        $this->info("Process daemon {$kind->value} остановлен: {$reason}.");

        return self::SUCCESS;
    }

    private function daemon(DaemonKind $kind): ProcessDaemon
    {
        return match ($kind) {
            DaemonKind::Relay => app(RelayDaemon::class),
            DaemonKind::Reconcile => app(ReconcileDaemon::class),
            DaemonKind::Feed => app(FeederDaemon::class),
            DaemonKind::Project => throw new \LogicException('Project daemon is not activated before P1.14–P1.18.'),
        };
    }

    /** TERM/INT ставят флаг: текущий короткий tick завершается, выход 0. */
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
    }
}
