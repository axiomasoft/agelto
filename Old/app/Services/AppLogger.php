<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LogOutput;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;
use Sys\Models\SysLog;
use Throwable;

final class AppLogger
{
    private ConsoleOutput $console;

    private LogOutput $defaultMode = LogOutput::Full;

    /**
     * Сквозной контекст стадии (run_id / crawler_id / entity_type / entity_id / stage),
     * который примешивается ко всем записям до следующего setContext()/clearContext().
     *
     * @var array<string, mixed>
     */
    private array $defaultContext = [];

    public function __construct()
    {
        $this->console = new ConsoleOutput();
    }

    /**
     * Задаёт сквозной контекст стадии — дальше каждый лог автоматически получает run_id/entity и т.п.
     *
     * @param  array<string, mixed>  $context
     */
    public function setContext(array $context): void
    {
        $this->defaultContext = $context;
    }

    public function clearContext(): void
    {
        $this->defaultContext = [];
    }

    /**
     * Основной метод логирования. $context — структурный контекст (stage, link_id, run_id и т.п.).
     *
     * @param  array<string, mixed>  $context
     */
    public function log(string $message, ?LogOutput $output = null, string $level = 'info', array $context = []): void
    {
        $context = array_merge($this->defaultContext, $context);
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

        // 3. Ошибки фиксируем ещё и в журнале sys_logs (видны в админке/таймлайне сущности).
        if ($level === 'error') {
            $this->persistToJournal($level, $message, $context);
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

    /**
     * Детальные трейсы (готовим переход на страницу N и т.п.) — отдельный уровень,
     * чтобы не засорять production-логи (в проде канал режет debug по LOG_LEVEL).
     *
     * @param  array<string, mixed>  $context
     */
    public function debug(string $message, ?LogOutput $output = null, array $context = []): void
    {
        $this->log($message, $output, 'debug', $context);
    }

    /**
     * Веха прогона/стадии — пишем и в файл-канал, и в журнал sys_logs (для админ-таймлайна:
     * «старт / возобновление с дня X / обнаружен оборванный прогон / завершение»).
     *
     * @param  array<string, mixed>  $context
     */
    public function event(string $message, array $context = [], ?LogOutput $output = null): void
    {
        $this->log($message, $output, 'info', $context);
        $this->persistToJournal('info', $message, array_merge($this->defaultContext, $context));
    }

    /**
     * Пишет строку в единый журнал sys_logs. Журналируем только записи с привязкой к сущности
     * (entity_type+entity_id), чтобы не плодить безадресный шум. Сбой записи в журнал не должен
     * рушить сам процесс — глушим в stack-канал.
     *
     * @param  array<string, mixed>  $context
     */
    private function persistToJournal(string $level, string $message, array $context): void
    {
        $entityType = $context['entity_type'] ?? null;
        $entityId = $context['entity_id'] ?? null;

        if ($entityType === null || $entityId === null) {
            return;
        }

        try {
            SysLog::query()->create([
                'level' => $level,
                'stage' => (string) ($context['stage'] ?? ''),
                'source' => (string) ($context['source'] ?? ''),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'message' => $message,
                'context' => Arr::except($context, ['entity_type', 'entity_id', 'stage', 'source', 'trace']),
                'trace' => $context['trace'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::channel('stack')->warning('SysLog persist failed: '.$e->getMessage());
        }
    }
}
