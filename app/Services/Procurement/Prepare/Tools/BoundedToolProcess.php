<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Exceptions\Procurement\Prepare\ToolCleanupUnconfirmed;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use App\Support\Files\PipelineTemporaryFile;
use Closure;
use RuntimeException;
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

    /**
     * @param  non-empty-list<string>  $command
     * @param  (Closure(resource): void)|null  $consumeOutput
     */
    public function run(
        array $command,
        string $directory,
        ?ExecutionContext $execution,
        float $deadline,
        int $tmpBytes = 1073741824,
        int $memoryBytes = 4294967296,
        int $cpuSeconds = 200,
        bool $includeStderr = false,
        ?string $inputPath = null,
        ?Closure $consumeOutput = null,
        int $maxOutputBytes = 8388608,
        bool $reportStderr = false,
    ): string {
        $execution?->tick();
        $seconds = $deadline - hrtime(true) / 1e9;
        if ($seconds <= 0) {
            throw new ToolFailed('page_timeout', reason: 'page_timeout');
        }
        $receipt = $directory.'/tool-'.bin2hex(random_bytes(8));
        $proofToken = bin2hex(random_bytes(32));
        file_put_contents($receipt.'.active', 'supervised');
        $options = $inputPath === null ? [] : ["--input-file={$inputPath}"];
        if ($consumeOutput !== null) {
            $options[] = "--max-output-bytes={$maxOutputBytes}";
        }
        $process = new Process(
            ['python3', __DIR__.'/tool_supervisor.py', $receipt, (string) $seconds,
                (string) $tmpBytes, (string) $memoryBytes, (string) $cpuSeconds, ...$options, ...$command],
            $directory,
            $this->supervisorEnv($directory, $proofToken),
        );
        $process->setTimeout(null);
        try {
            $process->start();
            while ($this->running($process)) {
                $execution?->tick();
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
            $detail = $reportStderr ? $this->errorDetail($receipt.'.err') : '';
            $message = ($reason === 'page_timeout' || $reportStderr) ? ($reason ?? 'page_ocr_error') : 'page_ocr_error';

            throw new ToolFailed($detail !== '' ? $detail : $message, reason: $reason, exitCode: $exit);
        }
        $stdout = $receipt.'.out';
        $stderr = $receipt.'.err';
        if (is_link($stdout) || ! is_file($stdout) || ($includeStderr && (is_link($stderr) || ! is_file($stderr)))) {
            throw new ToolFailed('page_ocr_error', reason: 'invalid_output', exitCode: 0);
        }
        $size = filesize($stdout);
        if ($size === false || $size + ($includeStderr ? (filesize($stderr) ?: 0) : 0) > $maxOutputBytes) {
            throw new ToolFailed('tool_output_limit', reason: 'tool_output_limit', exitCode: 0);
        }
        if ($consumeOutput !== null) {
            $stream = fopen($stdout, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open converter output.');
            }
            try {
                $consumeOutput($stream);
            } finally {
                fclose($stream);
            }

            return '';
        }

        return (string) file_get_contents($stdout).($includeStderr ? (string) file_get_contents($stderr) : '');
    }

    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $operation
     * @return TResult
     */
    public function inWorkspace(string $prefix, Closure $operation): mixed
    {
        $directory = PipelineTemporaryFile::createDirectory($prefix);
        $cleanup = true;
        try {
            return $operation($directory);
        } catch (ToolCleanupUnconfirmed $exception) {
            $cleanup = false;

            throw $exception;
        } finally {
            if ($cleanup) {
                PipelineTemporaryFile::deleteDirectory($directory);
            }
        }
    }

    public function copyOutput(string $source, string $target): void
    {
        if (is_link($source) || ! is_file($source) || ! copy($source, $target)) {
            throw new ToolFailed('Не удалось получить выходной файл конвертера.', reason: 'invalid_output');
        }
    }

    public function inputPath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || is_link($path)) {
            throw new ToolFailed('Входной файл конвертера недоступен.', reason: 'invalid_converter_input');
        }

        return $resolved;
    }

    private function errorDetail(string $stderr): string
    {
        return is_file($stderr) && ! is_link($stderr)
            ? trim((string) file_get_contents(filename: $stderr, length: 4096))
            : '';
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
