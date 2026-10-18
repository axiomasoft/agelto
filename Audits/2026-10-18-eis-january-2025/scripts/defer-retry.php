<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Carbon\CarbonImmutable;
use Database\Seeders\Procurement\EisJanuary2025AuditSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$result = ['utc' => gmdate(DATE_ATOM), 'outcome' => 'not_queued'];
try {
    $crawler = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole();
    $run = Run::query()->where('crawler_id', $crawler->id)->where('purpose', RunPurpose::Profile)
        ->whereNull('subject_key')->latest('id')->firstOrFail();
    $day = $run->cursor?->get('day');
    if ($run->status !== RunStatus::Failed || ! is_string($day) || $day < '2025-01-01' || $day > '2025-01-31'
        || Queue::connection('redis_crawl_long')->size('crawl_long') !== 0
        || config('ai.screening.enabled') || config('ai.extraction.enabled') || config('crawler.downstream_enabled', true)
    ) {
        throw new LogicException('Requires a failed January run, empty archive queue and disabled downstream/AI.');
    }
    $openUntil = $crawler->proxies()->max('open_until');
    $notBefore = $openUntil === null ? CarbonImmutable::now() : CarbonImmutable::parse($openUntil);
    $notBefore = $notBefore->greaterThan(CarbonImmutable::now()) ? $notBefore : CarbonImmutable::now();
    $notBefore = $notBefore->addSeconds(15);

    // Exactly one delayed operator command. The normal StartCrawlerRunAction still owns dispatch.
    $pending = Artisan::queue('crawler:run-archive', [
        'crawler' => $crawler->id, '--from' => $day, '--to' => '2025-01-31',
    ])->onConnection('redis_crawl_long')->onQueue('crawl_long')->delay($notBefore);
    unset($pending);
    $result += [
        'failed_run_id' => $run->id, 'crawler_id' => $crawler->id, 'from' => $day, 'to' => '2025-01-31',
        'not_before_utc' => $notBefore->toIso8601String(), 'maximum_automatic_restarts' => 1,
        'connection' => 'redis_crawl_long', 'queue' => 'crawl_long',
    ];
    $result['outcome'] = 'single_retry_queued';
} catch (Throwable $exception) {
    $result['exception_class'] = $exception::class;
    echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
