<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Гейт воспроизводимости образа (D23/D24) и первый шаг диагностики на проде: печатает
 * наличие и версию каждого системного бинаря, на котором стоят конвертеры P3.3–P3.7.
 * Только диагностирует — ничего не устанавливает и не правит конфиг.
 */
final class ToolsDoctorCommand extends Command
{
    protected $signature = 'ai:tools:doctor {--json : машинный вывод}';

    protected $description = 'Проверить наличие и версию системных бинарей фабрики представлений';

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

    public function handle(): int
    {
        $timeout = (int) config('ai.tools.default_timeout_seconds', 180);
        $rows = [];
        $allFound = true;

        foreach (self::VERSION_ARGS as $configKey => $versionArgs) {
            $bin = (string) config("ai.tools.{$configKey}");
            $verdict = $this->probe($bin, $versionArgs, $timeout);
            $rows[$configKey] = $verdict;

            if (! $verdict['found']) {
                $allFound = false;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['инструмент', 'путь', 'версия', 'вердикт'],
                array_map(
                    static fn (string $configKey, array $row): array => [
                        $configKey,
                        $row['bin'],
                        $row['version'] ?? '—',
                        $row['found'] ? 'ok' : 'не найден',
                    ],
                    array_keys($rows),
                    array_values($rows),
                ),
            );
        }

        return $allFound ? self::SUCCESS : self::FAILURE;
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
