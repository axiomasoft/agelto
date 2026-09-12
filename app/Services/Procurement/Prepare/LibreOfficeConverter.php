<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Обёртка над `soffice --headless --convert-to pdf` (P3.4, D7) — единственный вызывающий
 * `LibreOffice` в фабрике представлений; конвертер не знает о `Document`/представлениях, домен
 * живёт в вызывающей стратегии (переиспользуется бенчмарком P6.1).
 *
 * Профиль (`-env:UserInstallation`) — УНИКАЛЬНЫЙ на вызов, а не общий: параллельные headless-вызовы
 * на разных воркерах Horizon дерутся за один профиль и его lock-файл, что даёт молчаливые сбои
 * (RAG:✅ 2026-07-26, `findings/rag-P3-tooling-2026-07-26.md` §3).
 */
final readonly class LibreOfficeConverter
{
    public function toPdf(string $inputPath, string $outputDir): string
    {
        $profileDir = PipelineTemporaryFile::createDirectory(prefix: 'soffice_profile_');

        try {
            $result = Process::timeout($this->integerConfig('ai.tools.default_timeout_seconds'))
                ->run([
                    $this->stringConfig('ai.tools.soffice_bin'),
                    '--headless',
                    '--norestore',
                    "-env:UserInstallation=file://{$profileDir}",
                    '--convert-to', 'pdf',
                    '--outdir', $outputDir,
                    $inputPath,
                ]);

            $outputPath = rtrim($outputDir, '/').'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf';

            if ($result->failed() || ! file_exists($outputPath)) {
                throw new RuntimeException('LibreOffice-конвертация провалилась: '.$result->errorOutput());
            }

            return $outputPath;
        } finally {
            PipelineTemporaryFile::deleteDirectory($profileDir);
        }
    }

    public function version(): ?string
    {
        $result = Process::timeout($this->integerConfig('ai.tools.default_timeout_seconds'))
            ->run([$this->stringConfig('ai.tools.soffice_bin'), '--version']);

        if ($result->failed()) {
            return null;
        }

        $firstLine = trim(strtok(trim($result->output()), "\n") ?: '');

        return $firstLine !== '' ? $firstLine : null;
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
