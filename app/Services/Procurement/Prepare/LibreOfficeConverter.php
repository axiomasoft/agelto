<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Process;
use RuntimeException;

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

    public function toPdf(string $inputPath, string $outputDir): string
    {
        $profileDir = PipelineTemporaryFile::createDirectory(prefix: 'soffice_profile_');

        try {
            $result = Process::timeout($this->integerConfig('ai.tools.default_timeout_seconds'))
                ->env($this->sanitizedEnv($profileDir))
                ->run([
                    $this->stringConfig('ai.tools.soffice_bin'),
                    '--headless',
                    '--nolockcheck',
                    '--nodefault',
                    '--nofirststartwizard',
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
            ->env($this->sanitizedEnv(sys_get_temp_dir()))
            ->run([$this->stringConfig('ai.tools.soffice_bin'), '--version']);

        if ($result->failed()) {
            return null;
        }

        $firstLine = trim(explode("\n", trim($result->output()), 2)[0]);

        return $firstLine !== '' ? $firstLine : null;
    }

    /** @return array<string, string|false> */
    private function sanitizedEnv(string $tmpDir): array
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
        $env['TMPDIR'] = $tmpDir;
        $env['HOME'] = $tmpDir;
        $env['OMP_THREAD_LIMIT'] = '1';

        return $env;
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
