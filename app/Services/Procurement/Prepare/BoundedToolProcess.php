<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Dto\Procurement\Process\ExecutionContext;
use App\Exceptions\Procurement\Prepare\ToolCleanupUnconfirmed;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use Symfony\Component\Process\Process;

/** No shell, alarms or heartbeat fork. The independent wrapper enforces the hard wall bound. */
final readonly class BoundedToolProcess
{
    /** @param non-empty-list<string> $command */
    public function run(array $command, string $directory, ExecutionContext $execution, float $deadline,
        int $tmpBytes = 1073741824, int $memoryBytes = 4294967296, int $cpuSeconds = 200, bool $includeStderr = false): string
    {
        $execution->tick();
        $seconds = $deadline - hrtime(true) / 1e9;
        if ($seconds <= 0) {
            throw new ToolFailed('page_timeout');
        }
        $receipt = $directory.'/tool-'.bin2hex(random_bytes(8));
        file_put_contents($receipt.'.active', 'supervised');
        $process = new Process(['python3', __DIR__.'/tool_supervisor.py', $receipt, (string) $seconds,
            (string) $tmpBytes, (string) $memoryBytes, (string) $cpuSeconds, ...$command], $directory,
            ['TMPDIR' => $directory, 'OMP_THREAD_LIMIT' => '1']);
        $process->setTimeout(null);
        try {
            $process->start();
            while ($this->running($process)) {
                $execution->tick();
                usleep(100000);
            }
        } finally {
            // Signal supervisor only: it stops the entire tool group and reaps before acknowledging.
            if ($this->running($process)) {
                $process->signal(SIGTERM);
                $until = hrtime(true) + 4_000_000_000;
                while (hrtime(true) < $until) {
                    if (! $this->running($process)) {
                        break;
                    }
                    usleep(20000);
                }
            }
            if ($this->running($process) || ! is_file($receipt)) {
                throw new ToolCleanupUnconfirmed('Converter stop/reap is unconfirmed; retain tmp reservation.');
            }
            $process->wait();
            $status = json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($status) || ($status['stopped'] ?? null) !== true || is_file($receipt.'.active')) {
                throw new ToolCleanupUnconfirmed('Converter group is not confirmed stopped.');
            }
        }
        if (($status['exit'] ?? null) === 127) {
            throw new ToolUnavailable('converter_unavailable');
        }
        if (($status['exit'] ?? null) !== 0 || ($status['reason'] ?? null) !== null) {
            throw new ToolFailed(($status['reason'] ?? null) === 'page_timeout' ? 'page_timeout' : 'page_ocr_error');
        }
        $size = filesize($receipt.'.out');
        if ($size === false || $size + ($includeStderr ? (filesize($receipt.'.err') ?: 0) : 0) > 8388608) {
            throw new ToolFailed('tool_output_limit');
        }

        return (string) file_get_contents($receipt.'.out').($includeStderr ? (string) file_get_contents($receipt.'.err') : '');
    }

    /** @phpstan-impure */
    private function running(Process $process): bool
    {
        return $process->isRunning();
    }
}
