<?php

declare(strict_types=1);

namespace Eis\Console;

use Eis\Facades\EisCrawling;
use Illuminate\Console\Command;
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
    public function handle()
    {
        $crawler = Crawler::query()->firstOrFail();

        EisCrawling::run($crawler);
    }
}
