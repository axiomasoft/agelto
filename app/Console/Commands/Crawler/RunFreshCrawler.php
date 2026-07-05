<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Enums\Crawler\CrawlerMode;
use App\Models\Crawler\Crawler;
use App\Services\Crawler\Modes\FreshRunProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Тонкая точка входа fresh-скана: резолвит профиль(и) и делегирует `FreshRunProcessor`.
 * Ошибка одного профиля при мульти-профильном вызове не прерывает остальные.
 */
final class RunFreshCrawler extends Command
{
    protected $signature = 'crawler:run-fresh {crawler? : ID или name профиля}';

    protected $description = 'Запускает fresh-скан одного профиля краулера (или всех активных fresh-профилей)';

    public function handle(FreshRunProcessor $processor): int
    {
        $crawlers = $this->resolveCrawlers();

        if ($crawlers->isEmpty()) {
            $this->error('Нет ни одного подходящего профиля краулера для fresh-скана.');

            return self::FAILURE;
        }

        $hasFailure = false;

        foreach ($crawlers as $crawler) {
            try {
                $run = $processor->run(crawler: $crawler);
                $this->info("Профиль «{$crawler->name}» (#{$crawler->id}): прогон #{$run->id} завершён со статусом {$run->status->value}.");
            } catch (Throwable $exception) {
                $hasFailure = true;
                $this->error("Профиль «{$crawler->name}» (#{$crawler->id}): {$exception->getMessage()}");
            }
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Crawler>
     */
    private function resolveCrawlers(): Collection
    {
        $identifier = $this->argument('crawler');

        if ($identifier === null) {
            return Crawler::query()
                ->where('mode', CrawlerMode::Fresh)
                ->where('is_active', true)
                ->get();
        }

        $crawler = is_numeric($identifier)
            ? Crawler::query()->find((int) $identifier)
            : Crawler::query()->where('name', $identifier)->first();

        return collect($crawler === null ? [] : [$crawler]);
    }
}
