<?php

declare(strict_types=1);

namespace Eis\Console;

use Eis\Facades\EisCrawling;
use Illuminate\Console\Command;
use Platform\Enums\Platform as PlatformEnum;
use Platform\Models\Crawling\Crawler;

final class EisCrawlingCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'eis:crawling';

    /**
     * The console command description.
     */
    protected $description = 'Crawling EIS data.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        do {
            $crawler = Crawler::query()
                ->whereHas('platform', fn ($q) => $q->where('code', PlatformEnum::EIS->value))
                ->first();

            if ($crawler === null) {
                $this->warn('Краулер не найден. Ожидание 30 минут...');
                sleep(30 * 60);
            }
        } while ($crawler === null);

        EisCrawling::execute($crawler);

        return self::SUCCESS;
    }
}
