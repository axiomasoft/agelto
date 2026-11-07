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
    private const array ALLOWED_ENV = [
        'PATH',
        'LANG',
        'LC_ALL',
        'LC_CTYPE',
        'LD_LIBRARY_PATH',
        'TESSDATA_PREFIX',
        'FONTCONFIG_PATH',
        'SOURCE_DATE_EPOCH',
        'TZ',
    ];

    /** @param non-empty-list<string> $command */
    public function run(array $command, string $directory, ExecutionContext $execution, float $deadline,
        int $tmpBytes = 1073741824, int $memoryBytes = 4294967296, int $cpuSeconds = 200, bool $includeStderr = false): string
    {
        $execution->tick();
        $seconds = $deadline - hrtime(true) / 1e9;
        if ($seconds <= 0) {
            throw new ToolFailed('page_timeout', reason: 'page_timeout');
        }
        $receipt = $directory.'/tool-'.bin2hex(random_bytes(8));
        $proofToken = bin2hex(random_bytes(32));
        file_put_contents($receipt.'.active', 'supervised');
        $process = new Process(
            ['python3', __DIR__.'/tool_supervisor.py', $receipt, (string) $seconds,
                (string) $tmpBytes, (string) $memoryBytes, (string) $cpuSeconds, ...$command],
            $directory,
            $this->supervisorEnv($directory, $proofToken),
        );
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
            try {
                $process->wait();
            } catch (\Throwable) {
            }
            try {
                $status = json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $status = null;
            }
            if (! is_array($status)
                || ($status['stopped'] ?? null) !== true
                || ! is_string($status['token'] ?? null)
                || ! hash_equals($proofToken, $status['token'])
                || is_file($receipt.'.active')) {
                throw new ToolCleanupUnconfirmed('Converter group is not confirmed stopped.');
            }
        }
        if (($status['exit'] ?? null) === 127) {
            throw new ToolUnavailable('converter_unavailable');
        }
        $reason = is_string($status['reason'] ?? null) ? $status['reason'] : null;
        $exit = is_int($status['exit'] ?? null) ? $status['exit'] : null;
        if ($exit !== 0 || $reason !== null) {
            throw new ToolFailed($reason === 'page_timeout' ? 'page_timeout' : 'page_ocr_error', reason: $reason, exitCode: $exit);
        }
        if (is_link($receipt.'.out') || ($includeStderr && is_link($receipt.'.err'))) {
            throw new ToolFailed('page_ocr_error', reason: 'invalid_output', exitCode: 0);
        }
        $size = filesize($receipt.'.out');
        if ($size === false || $size + ($includeStderr ? (filesize($receipt.'.err') ?: 0) : 0) > 8388608) {
            throw new ToolFailed('tool_output_limit', reason: 'tool_output_limit', exitCode: 0);
        }

        return (string) file_get_contents($receipt.'.out').($includeStderr ? (string) file_get_contents($receipt.'.err') : '');
    }

    /** @return array<string, string|false> */
    private function supervisorEnv(string $directory, string $proofToken): array
    {
        $env = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $key) {
            if (is_string($key) && $key !== '') {
                $env[$key] = false;
            }
        }
        foreach (self::ALLOWED_ENV as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        $env['TMPDIR'] = $directory;
        $env['HOME'] = $directory;
        $env['OMP_THREAD_LIMIT'] = '1';
        $env['_AGELTO_SUPERVISOR_TOKEN'] = $proofToken;

        return $env;
    }

    /** @phpstan-impure */
    private function running(Process $process): bool
    {
        return $process->isRunning();
    }
}
