<?php

namespace App\Services;

use App\Enums\LogOutput;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

class AppLogger
{
    protected ConsoleOutput $console;
    protected LogOutput $defaultMode = LogOutput::Full;

    public function __construct()
    {
        $this->console = new ConsoleOutput();
    }

    /**
     * Основной метод логирования
     */
    public function log(string $message, ?LogOutput $output = null, string $level = 'info'): void
    {
        $mode = $output ?? $this->defaultMode;

        // 1. Запись в файл (Laravel Log)
        if (in_array($mode, [LogOutput::Full, LogOutput::File])) {
            Log::log($level, $message);
        }

        // 2. Вывод в консоль (если запущен из терминала)
        if (in_array($mode, [LogOutput::Full, LogOutput::Console])) {
            if (app()->runningInConsole()) {
                $style = $level === 'error' ? 'error' : 'info';
                $this->console->writeln("<$style>[" . strtoupper($level) . "]</$style> $message");
            }
        }
    }

    public function info(string $message, ?LogOutput $output = null): void
    {
        $this->log($message, $output, 'info');
    }

    public function error(string $message, ?LogOutput $output = null): void
    {
        $this->log($message, $output, 'error');
    }

    public function warning(string $message, ?LogOutput $output = null): void
    {
        $this->log($message, $output, 'warning');
    }
}
