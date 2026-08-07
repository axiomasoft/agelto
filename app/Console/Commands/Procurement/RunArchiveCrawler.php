<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Тонкая точка входа archive-перекачки (`02-crawling.md` §4.2) — операторская
 * bulk-задача по явной команде, не по расписанию (в отличие от fresh/detail). Зовёт
 * `StartCrawlerRunAction` (P3.4) — единственный носитель проверки «можно ли запускать», тот же
 * класс диспетчерит кнопка админки.
 */
final class RunArchiveCrawler extends Command
{
    protected $signature = 'crawler:run-archive {crawler : ID или name профиля} {--from=} {--to=}';

    protected $description = 'Запускает archive-перекачку профиля краулера за период [--from, --to]';

    public function handle(StartCrawlerRunAction $starter): int
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

        try {
            $starter->start($crawler, mode: CrawlerMode::Archive, from: Carbon::parse($from), to: $to);
        } catch (OperationFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Профиль «{$crawler->name}» (#{$crawler->id}): archive-перекачка поставлена в очередь crawl.");

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
