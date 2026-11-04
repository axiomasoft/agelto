<?php

declare(strict_types=1);

namespace App\Console\Support;

use App\Services\Procurement\Contracts\Dto\ProgressTick;
use App\Services\Procurement\Contracts\ProgressReporter;
use Illuminate\Console\OutputStyle;

/**
 * Выводит прогресс сервисного слоя через {@see OutputStyle} команды.
 */
final readonly class ConsoleProgressReporter implements ProgressReporter
{
    public function __construct(private OutputStyle $output) {}

    public function report(ProgressTick $tick): void
    {
        $message = "[{$tick->done}/{$tick->total}] {$tick->label}";

        if ($tick->note !== null) {
            $message .= ": {$tick->note}";
        }

        $this->output->writeln($message);
    }
}
