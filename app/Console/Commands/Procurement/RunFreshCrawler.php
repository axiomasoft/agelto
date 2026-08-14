<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Тонкая точка входа fresh-скана: резолвит профиль(и) и зовёт `StartCrawlerRunAction` (P3.4) —
 * единственный носитель проверки «можно ли запускать», тот же класс диспетчерит кнопка админки.
 * Ошибка одного профиля при мульти-профильном вызове не прерывает остальные.
 */
#[Description('Запускает fresh-скан одного профиля краулера (или всех активных fresh-профилей)')]
#[Signature('crawler:run-fresh {crawler? : ID или name профиля}')]
final class RunFreshCrawler extends Command
{
    public function handle(StartCrawlerRunAction $starter): int
    {
        $crawlers = $this->resolveCrawlers();

        if ($crawlers->isEmpty()) {
            $this->error('Нет ни одного подходящего профиля краулера для fresh-скана.');

            return self::FAILURE;
        }

        $hasFailure = false;

        foreach ($crawlers as $crawler) {
            try {
                $starter->start($crawler, mode: CrawlerMode::Fresh);
                $this->info("Профиль «{$crawler->name}» (#{$crawler->id}): fresh-скан поставлен в очередь crawl.");
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
