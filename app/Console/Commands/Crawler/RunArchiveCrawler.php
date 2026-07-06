<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\Crawler\Crawler;
use App\Services\Crawler\Modes\ArchiveRunProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Тонкая точка входа archive-перекачки (`02-crawling.md` §4.2) — операторская
 * bulk-задача по явной команде, не по расписанию (в отличие от fresh/detail).
 */
final class RunArchiveCrawler extends Command
{
    protected $signature = 'crawler:run-archive {crawler : ID или name профиля} {--from=} {--to=}';

    protected $description = 'Запускает archive-перекачку профиля краулера за период [--from, --to]';

    public function handle(ArchiveRunProcessor $processor): int
    {
        $crawler = $this->resolveCrawler();

        if ($crawler === null) {
            $this->error('Не найден профиль краулера с таким id/name.');

            return self::FAILURE;
        }

        $from = $this->option('from');

        if ($from === null) {
            $this->error('Обязателен параметр --from (начало периода перекачки).');

            return self::FAILURE;
        }

        $to = $this->option('to') !== null ? Carbon::parse($this->option('to')) : Carbon::now();

        $run = $processor->run(crawler: $crawler, from: Carbon::parse($from), to: $to);

        $this->info("Профиль «{$crawler->name}» (#{$crawler->id}): archive-прогон #{$run->id} завершён со статусом {$run->status->value}.");

        return self::SUCCESS;
    }

    private function resolveCrawler(): ?Crawler
    {
        $identifier = $this->argument('crawler');

        return is_numeric($identifier)
            ? Crawler::query()->find((int) $identifier)
            : Crawler::query()->where('name', $identifier)->first();
    }
}
