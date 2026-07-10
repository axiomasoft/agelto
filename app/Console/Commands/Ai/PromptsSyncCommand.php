<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Models\Procurement\Ai\Prompt;
use Database\Seeders\PromptSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Синхронизация `resources/ai/prompts/**\/*.md` → `prc_ai_prompts` (P8.1, D5/D17). Без `--check`
 * делает ровно то же самое, что `PromptSeeder` внутри `db:seed` — прямой путь для деплоя промтов
 * без прогона остального `DatabaseSeeder`. С `--check` НИЧЕГО не пишет — только сверяет файлы с
 * текущим состоянием БД и завершает CI-джобу ошибкой при найденном дрейфе.
 */
final class PromptsSyncCommand extends Command
{
    protected $signature = 'ai:prompts:sync {--check : только сверить дрейф файлы↔БД, ничего не писать}';

    protected $description = 'Синхронизация файловых промтов resources/ai/prompts/** в prc_ai_prompts';

    public function handle(): int
    {
        if ($this->option('check')) {
            return $this->check();
        }

        $this->laravel->make(PromptSeeder::class)->__invoke();

        $this->info('Промты синхронизированы.');

        return self::SUCCESS;
    }

    private function check(): int
    {
        $records = PromptSeeder::loadFiles();
        $drift = [];

        foreach ($records as $record) {
            /** @var Prompt|null $existing */
            $existing = Prompt::query()
                ->where('key', $record['key'])
                ->where('version', $record['version'])
                ->first();

            if ($existing === null) {
                $drift[] = "новый: {$record['key']} v{$record['version']} (нет в БД)";

                continue;
            }

            $mismatched = $existing->step !== $record['step']
                || $existing->content !== $record['content']
                || $existing->priority !== $record['priority']
                || $existing->is_active !== $record['is_active']
                || ! $existing->is_protected;

            if ($mismatched) {
                $drift[] = "изменён: {$record['key']} v{$record['version']} (файл ≠ БД)";
            }
        }

        $latestVersionByKey = $records->groupBy('key')
            ->map(fn (Collection $group): int => (int) $group->max('version'));

        Prompt::query()
            ->where('is_protected', true)
            ->where('is_active', true)
            ->get()
            ->each(function (Prompt $prompt) use ($latestVersionByKey, &$drift): void {
                $latest = $latestVersionByKey->get($prompt->key);

                if ($latest === null) {
                    $drift[] = "удалён из файлов, но активен в БД: {$prompt->key} v{$prompt->version}";
                } elseif ($prompt->version < $latest) {
                    $drift[] = "устаревшая версия ещё активна в БД: {$prompt->key} v{$prompt->version} (файл v{$latest})";
                }
            });

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
