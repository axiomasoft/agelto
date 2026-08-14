<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Тонкая точка входа archive-перекачки (`02-crawling.md` §4.2) — операторская
 * bulk-задача по явной команде, не по расписанию (в отличие от fresh/detail). Зовёт
 * `StartCrawlerRunAction` (P3.4) — единственный носитель проверки «можно ли запускать», тот же
 * класс диспетчерит кнопка админки. `--from`/`--to` — OVERRIDE поверх окна профиля (P3.8, D113):
 * без `--from` резолвится окно профиля через `HasArchiveWindow`, отказ (в т.ч. «окно не заполнено»)
 * формирует ТОЛЬКО `StartCrawlerRunAction` — вторая проверка «обязателен --from» здесь не
 * заводится (Implementation Rules п. 7 item'а P3.8).
 */
#[Description('Запускает archive-перекачку профиля краулера за период [--from, --to] (по умолчанию — окно профиля)')]
#[Signature('crawler:run-archive {crawler : ID или name профиля} {--from=} {--to=}')]
final class RunArchiveCrawler extends Command
{
    public function handle(StartCrawlerRunAction $starter): int
    {
        $crawler = $this->resolveCrawler();

        if ($crawler === null) {
            $this->error('Не найден профиль краулера с таким id/name.');

            return self::FAILURE;
        }

        $from = $this->option('from') !== null ? Carbon::parse($this->option('from')) : null;
        $to = match (true) {
            $this->option('to') !== null => Carbon::parse($this->option('to')),
            $from !== null => Carbon::now(),
            default => null,
        };

        try {
            $starter->start($crawler, mode: CrawlerMode::Archive, from: $from, to: $to);
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
