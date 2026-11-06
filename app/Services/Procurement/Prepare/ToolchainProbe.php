<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use Illuminate\Support\Facades\Process;

/**
 * Гейт воспроизводимости образа и первый шаг диагностики на проде: проверяет наличие
 * и версию каждого системного бинаря, на котором стоят конвертеры фабрики представлений
 * (`ai:tools:doctor`). Только диагностирует — ничего не
 * устанавливает и не правит конфиг.
 */
final readonly class ToolchainProbe
{
    /**
     * @var array<string, list<string>>
     */
    private const array VERSION_ARGS = [
        'soffice_bin' => ['--version'],
        'sevenzip_bin' => ['i'],
        'unar_bin' => ['-v'],
        'tesseract_bin' => ['--version'],
        'ocrmypdf_bin' => ['--version'],
        'pdftotext_bin' => ['-v'],
        'pdfinfo_bin' => ['-v'],
        'gs_bin' => ['--version'],
    ];

    /**
     * @return array<string, array{bin: string, found: bool, version: string|null}>
     */
    public function probeAll(): array
    {
        $timeout = $this->integerConfig(key: 'ai.tools.default_timeout_seconds', default: 180);
        $rows = [];

        foreach (self::VERSION_ARGS as $configKey => $versionArgs) {
            $bin = $this->stringConfig("ai.tools.{$configKey}");
            $rows[$configKey] = $this->probe($bin, $versionArgs, $timeout);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $versionArgs
     * @return array{bin: string, found: bool, version: string|null}
     */
    private function probe(string $bin, array $versionArgs, int $timeout): array
    {
        $result = Process::timeout($timeout)->run([$bin, ...$versionArgs]);

        if ($result->failed()) {
            return ['bin' => $bin, 'found' => false, 'version' => null];
        }

        $output = trim($result->output()) !== '' ? $result->output() : $result->errorOutput();
        $firstLine = trim(explode("\n", trim($output), 2)[0]);

        return ['bin' => $bin, 'found' => true, 'version' => $firstLine !== '' ? $firstLine : null];
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new \UnexpectedValueException("{$key} must be an integer.");
        }

        return $value;
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        if (! is_string($value)) {
            throw new \UnexpectedValueException("{$key} must be a string.");
        }

        return $value;
    }
}
