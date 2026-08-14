<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Services\Llm\PromptCanonSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Синхронизация `resources/ai/prompts/**\/*.md` → `llm_prompts` (P8.1, D5/D17). Без `--check`
 * делает ровно то же самое, что `PromptSeeder` внутри `db:seed` — прямой путь для деплоя промтов
 * без прогона остального `DatabaseSeeder`. С `--check` НИЧЕГО не пишет — только сверяет файлы с
 * текущим состоянием БД и завершает CI-джобу ошибкой при найденном дрейфе.
 */
#[Description('Синхронизация файловых промтов resources/ai/prompts/** в llm_prompts')]
#[Signature('ai:prompts:sync {--check : только сверить дрейф файлы↔БД, ничего не писать}')]
final class PromptsSyncCommand extends Command
{
    public function __construct(private readonly PromptCanonSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('check')) {
            return $this->check();
        }

        $this->synchronizer->sync();

        $this->info('Промты синхронизированы.');

        return self::SUCCESS;
    }

    private function check(): int
    {
        $drift = $this->synchronizer->drift();

        if ($drift === []) {
            $this->info('Дрейф файлы↔БД не обнаружен.');

            return self::SUCCESS;
        }

        foreach ($drift as $line) {
            $this->error($line);
        }

        return self::FAILURE;
    }
}
