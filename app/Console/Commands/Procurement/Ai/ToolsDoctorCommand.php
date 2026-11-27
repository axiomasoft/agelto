<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Services\Procurement\Prepare\Tools\ToolchainProbe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Гейт воспроизводимости образа и первый шаг диагностики на проде — печатает наличие
 * и версию каждого системного бинаря; проверка ушла в `ToolchainProbe`.
 */
#[Description('Проверить наличие и версию системных бинарей фабрики представлений')]
#[AsCommand(name: 'ai:tools:doctor')]
#[Signature('ai:tools:doctor {--json : машинный вывод}')]
final class ToolsDoctorCommand extends Command
{
    public function __construct(private readonly ToolchainProbe $probe)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->probe->probeAll();
        $allFound = ! in_array(false, array_column($rows, 'found'), true);

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
}
