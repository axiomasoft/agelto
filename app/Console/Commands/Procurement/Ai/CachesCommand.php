<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Services\Llm\LlmCacheManager;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;

/**
 * CRUD-обвязка `LlmCacheManager` (P7.1, ТЗ 04 §2.1/§8): `list` — живые explicit-кэши (или
 * `--hits` — доля implicit cache-хитов по `purpose` за окно); `prune` — снимает осиротевшие/
 * просроченные регистрации (провайдер резолвится по `analysis.provider` через
 * `LlmProviderFactory`, единственный `match` в проекте — `root/service-layer-map.md` §8).
 */
final class CachesCommand extends Command
{
    protected $signature = 'ai:caches
        {action : list|prune}
        {--hits : list — вывести долю implicit cache-хитов по purpose вместо списка explicit-кэшей}
        {--window=24 : окно в часах для --hits}';

    protected $description = 'Explicit context cache: список живых/осиротевших кэшей, доля implicit-хитов, prune';

    public function __construct(private readonly LlmCacheManager $caches)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->option('hits') ? $this->listHits() : $this->listActive(),
            'prune' => $this->prune(),
            default => $this->invalidAction(),
        };
    }

    private function listActive(): int
    {
        $analyses = $this->caches->listActive();

        if ($analyses->isEmpty()) {
            $this->info('Явных (explicit) context cache сейчас нет.');

            return self::SUCCESS;
        }

        $this->table(
            ['analysis_id', 'document_version_id', 'provider', 'model', 'cache_id', 'expires_at'],
            $analyses->map(fn ($analysis): array => [
                $analysis->id,
                $analysis->document_version_id,
                $analysis->provider,
                $analysis->model,
                $analysis->cache_id,
                $analysis->cache_expires_at?->toIso8601String(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function listHits(): int
    {
        $window = CarbonInterval::hours(max(1, (int) $this->option('window')));
        $stats = $this->caches->implicitHitStats($window);

        if ($stats->isEmpty()) {
            $this->info('Вызовов LLM за указанное окно не найдено.');

            return self::SUCCESS;
        }

        $this->table(
            ['purpose', 'calls', 'prompt_tokens', 'cached_tokens', 'hit_ratio'],
            $stats->map(fn (object $row): array => [
                $row->purpose,
                $row->calls,
                $row->prompt_tokens,
                $row->cached_tokens,
                sprintf('%.2f%%', $row->hit_ratio * 100),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function prune(): int
    {
        $pruned = $this->caches->prune();

        $this->info("Снято регистраций кэша: {$pruned}.");

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Неизвестное действие — используйте list|prune.');

        return self::FAILURE;
    }
}
