<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use Illuminate\Support\Facades\Process;

/**
 * Гейт воспроизводимости образа (D23/D24) и первый шаг диагностики на проде: проверяет наличие
 * и версию каждого системного бинаря, на котором стоят конвертеры фабрики представлений
 * (`ai:tools:doctor`, `root/service-layer-map.md` §3). Только диагностирует — ничего не
 * устанавливает и не правит конфиг.
 */
final readonly class ToolchainProbe
{
    /**
     * @var array<string, list<string>>
     */
    private const VERSION_ARGS = [
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
        $timeout = (int) config('ai.tools.default_timeout_seconds', 180);
        $rows = [];

        foreach (self::VERSION_ARGS as $configKey => $versionArgs) {
            $bin = (string) config("ai.tools.{$configKey}");
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
        $firstLine = trim(strtok(trim($output), "\n") ?: '');

        return ['bin' => $bin, 'found' => true, 'version' => $firstLine !== '' ? $firstLine : null];
    }
}
