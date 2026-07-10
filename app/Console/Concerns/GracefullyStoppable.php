<?php

declare(strict_types=1);

namespace App\Console\Concerns;

/**
 * Шаблон graceful-SIGTERM для долгоживущих консольных команд пайплайна (D26, research/J §4).
 *
 * Канон: сигнал НЕ прерывает текущую единицу работы (страницу/окно/итерацию) немедленно —
 * `handleSignal()` только поднимает флаг; сама команда обязана проверять {@see shouldStop()}
 * МЕЖДУ единицами работы и завершаться `exit 0` — та же дисциплина, что checkpoint-requeue
 * `EstimateExtractionJob` (D31): SIGTERM/остановка не рвёт незавершённую единицу, а ждёт
 * границы прогресса.
 *
 * Использование — команда обязана сама объявить `implements SignalableCommandInterface`
 * (Symfony резолвит его по интерфейсу, не по трейту; трейт даёт только состояние):
 *
 * ```php
 * final class RunFreshCrawler extends Command implements SignalableCommandInterface, Isolatable
 * {
 *     use GracefullyStoppable;
 *
 *     public function handle(): int
 *     {
 *         while ($this->hasMoreWork() && ! $this->shouldStop()) {
 *             $this->processOneUnit();
 *         }
 *
 *         return self::SUCCESS;
 *     }
 * }
 * ```
 *
 * `Isolatable` (Illuminate\Contracts\Console\Isolatable) — отдельная забота команды
 * (двойной запуск одного и того же процесса), не входит в этот трейт.
 */
trait GracefullyStoppable
{
    private bool $stopRequested = false;

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopRequested = true;

        if (method_exists($this, 'info')) {
            $this->info('Получен сигнал остановки — дорабатываю текущую единицу и выхожу...');
        }

        return false;
    }

    protected function shouldStop(): bool
    {
        return $this->stopRequested;
    }
}
