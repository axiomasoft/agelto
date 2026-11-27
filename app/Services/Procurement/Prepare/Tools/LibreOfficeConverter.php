<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Exceptions\Procurement\Prepare\OperationBudgetExceeded;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Обёртка над `soffice --headless --convert-to pdf` — единственный вызывающий
 * `LibreOffice` в фабрике представлений; конвертер не знает о `Document`/представлениях, домен
 * живёт в вызывающей стратегии (переиспользуется бенчмарком).
 *
 * Профиль (`-env:UserInstallation`) — УНИКАЛЬНЫЙ на вызов, а не общий: параллельные headless-вызовы
 * на разных воркерах Horizon дерутся за один профиль и его lock-файл, что даёт молчаливые сбои
 */
final readonly class LibreOfficeConverter
{
    public function __construct(private BoundedToolProcess $tools) {}

    public function toPdf(string $inputPath, string $outputDir, ?float $deadlineAt = null): string
    {
        try {
            $source = $this->tools->inputPath($inputPath);
            $deadline = $this->deadline($deadlineAt);

            return $this->tools->inWorkspace(prefix: 'soffice_', operation: function (string $directory) use ($source, $inputPath, $outputDir, $deadline): string {
                $sandboxOutput = $directory.'/'.pathinfo($source, PATHINFO_FILENAME).'.pdf';
                $this->tools->run(
                    command: $this->conversionCommand(directory: $directory, source: $source, target: $sandboxOutput),
                    directory: $directory,
                    execution: null,
                    deadline: $deadline,
                    inputPath: $source,
                    reportStderr: true,
                );

                $outputPath = rtrim($outputDir, '/').'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf';
                $this->tools->copyOutput(
                    source: $sandboxOutput,
                    target: $outputPath,
                );

                return $outputPath;
            });
        } catch (ToolFailed|ToolUnavailable $exception) {
            if ($exception instanceof ToolFailed && $exception->reason === 'page_timeout'
                && $deadlineAt !== null && $deadlineAt - microtime(true) < 1) {
                throw OperationBudgetExceeded::forOperation(operation: 'LibreOffice', availableSeconds: 0, requiredSeconds: 1);
            }

            throw new RuntimeException('LibreOffice-конвертация провалилась: '.$exception->getMessage(), previous: $exception);
        }
    }

    /** @return non-empty-list<string> */
    private function conversionCommand(string $directory, string $source, string $target): array
    {
        $binary = $this->stringConfig('ai.tools.soffice_bin');
        $executable = (new ExecutableFinder)->find($binary);
        $resolved = $executable === null ? false : realpath($executable);
        if ($resolved !== false && basename($resolved) === 'soffice') {
            $kit = dirname($resolved).'/libmergedlo.so';
            if (is_file($kit)) {
                // Desktop soffice requires an IPC listener. The embedded exporter needs no listener.
                $exporter = $directory.'/libreoffice_export.py';
                if (! copy(__DIR__.'/libreoffice_export.py', $exporter)) {
                    throw new RuntimeException('Не удалось подготовить скрипт экспорта LibreOffice.');
                }

                return ['python3', $exporter, $kit, $source, $target];
            }
        }

        return [
            $binary,
            '--headless',
            '--nolockcheck',
            '--nodefault',
            '--nofirststartwizard',
            '--norestore',
            "-env:UserInstallation=file://{$directory}/profile",
            '--convert-to', 'pdf',
            '--outdir', $directory,
            $source,
        ];
    }

    public function version(?float $deadlineAt = null): ?string
    {
        try {
            $deadline = $this->deadline($deadlineAt);
            $output = $this->tools->inWorkspace(
                prefix: 'soffice_version_',
                operation: fn (string $directory): string => $this->tools->run(
                    command: [$this->stringConfig('ai.tools.soffice_bin'), '--version'],
                    directory: $directory,
                    execution: null,
                    deadline: $deadline,
                ),
            );
        } catch (OperationBudgetExceeded|ToolFailed|ToolUnavailable) {
            return null;
        }

        $firstLine = trim(explode("\n", trim($output), 2)[0]);

        return $firstLine !== '' ? $firstLine : null;
    }

    private function deadline(?float $deadlineAt): float
    {
        $seconds = $this->integerConfig('ai.tools.default_timeout_seconds');
        $remaining = $deadlineAt === null ? $seconds : (int) floor($deadlineAt - microtime(true));
        if ($remaining < 1) {
            throw OperationBudgetExceeded::forOperation(operation: 'LibreOffice', availableSeconds: $remaining, requiredSeconds: 1);
        }

        return hrtime(true) / 1e9 + min($seconds, $remaining);
    }

    private function integerConfig(string $key): int
    {
        $value = config($key);

        if (! is_int($value)) {
            throw new RuntimeException("{$key} must be an integer.");
        }

        return $value;
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        if (! is_string($value)) {
            throw new RuntimeException("{$key} must be a string.");
        }

        return $value;
    }
}
