<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Services\Procurement\Prepare\ToolchainProbe;
use Illuminate\Console\Command;

/**
 * Гейт воспроизводимости образа (D23/D24) и первый шаг диагностики на проде — печатает наличие
 * и версию каждого системного бинаря; проверка ушла в `ToolchainProbe`
 * (`root/service-layer-map.md` §3).
 */
final class ToolsDoctorCommand extends Command
{
    protected $signature = 'ai:tools:doctor {--json : машинный вывод}';

    protected $description = 'Проверить наличие и версию системных бинарей фабрики представлений';

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
