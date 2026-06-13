<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LogOutput;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

final class AppLogger
{
    private ConsoleOutput $console;

    private LogOutput $defaultMode = LogOutput::Full;

    public function __construct()
    {
        $this->console = new ConsoleOutput();
    }

    /**
     * Основной метод логирования. $context — структурный контекст (stage, link_id, run_id и т.п.).
     *
     * @param  array<string, mixed>  $context
     */
    public function log(string $message, ?LogOutput $output = null, string $level = 'info', array $context = []): void
    {
        $mode = $output ?? $this->defaultMode;

        // 1. Запись в выделенный JSON-канал пайплайна со структурным контекстом
        if (in_array($mode, [LogOutput::Full, LogOutput::File])) {
            Log::channel('pipeline')->log($level, $message, $context);
        }

        // 2. Вывод в консоль (если запущен из терминала)
        if (in_array($mode, [LogOutput::Full, LogOutput::Console])) {
            if (app()->runningInConsole()) {
                $style = $level === 'error' ? 'error' : 'info';
                $this->console->writeln("<$style>[".mb_strtoupper($level)."]</$style> $message");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, ?LogOutput $output = null, array $context = []): void
    {
        $this->log($message, $output, 'info', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, ?LogOutput $output = null, array $context = []): void
    {
        $this->log($message, $output, 'error', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, ?LogOutput $output = null, array $context = []): void
    {
        $this->log($message, $output, 'warning', $context);
    }
}
