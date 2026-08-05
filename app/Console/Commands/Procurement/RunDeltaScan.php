<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Modes\DeltaScanProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Тонкая точка входа планового delta-scan (П-3): резолвит fresh-профиль(и) — по нему
 * `DeltaScanProcessor` находит активный archive-профиль того же реестра — и делегирует дренаж
 * окна `[сегодня − window_overlap_days; сегодня]` по дате обновления. Регистрация в расписании —
 * `routes/console.php`, под флагом `crawler.tracking.delta_scan_enabled`.
 */
final class RunDeltaScan extends Command
{
    protected $signature = 'crawler:run-delta {crawler? : ID или name fresh-профиля}';

    protected $description = 'Плановый delta-scan окна обновления на активном archive-профиле реестра';

    public function handle(DeltaScanProcessor $processor): int
    {
        $crawlers = $this->resolveCrawlers();

        if ($crawlers->isEmpty()) {
            $this->error('Нет ни одного подходящего fresh-профиля краулера для delta-scan.');

            return self::FAILURE;
        }

        $hasFailure = false;

        foreach ($crawlers as $crawler) {
            try {
                $run = $processor->run(crawler: $crawler);
                $message = $run === null
                    ? "Профиль «{$crawler->name}» (#{$crawler->id}): нет активного archive-профиля реестра, пропущен."
                    : "Профиль «{$crawler->name}» (#{$crawler->id}): delta-scan #{$run->id} завершён со статусом {$run->status->value}.";
                $this->info($message);
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
