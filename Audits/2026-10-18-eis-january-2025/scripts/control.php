<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\Crawler\RequestRunStopAction;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use Database\Seeders\Procurement\EisJanuary2025AuditSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$evidence = ['utc' => gmdate(DATE_ATOM), 'action' => $argv[1] ?? null];
try {
    $crawler = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole();
    $evidence['crawler_id'] = $crawler->id;
    if (in_array($evidence['action'], ['start', 'resume'], strict: true)
        && (config('crawler.downstream_enabled', true) || config('ai.screening.enabled') || config('ai.extraction.enabled'))
    ) {
        throw new LogicException('January CSV operation requires disabled downstream/AI.');
    }
    if (in_array($evidence['action'], ['start', 'resume'], strict: true)
        && Queue::connection('redis_crawl_long')->size('crawl_long') !== 0
    ) {
        throw new LogicException('The archive queue already contains work; inspect ready/delayed/reserved jobs.');
    }
    if ($evidence['action'] === 'start') {
        app(StartCrawlerRunAction::class)->start(crawler: $crawler);
        $evidence['outcome'] = 'queued';
    } elseif (in_array($evidence['action'], ['pause', 'resume'], strict: true)) {
        $run = Run::query()->where('crawler_id', $crawler->id)->findOrFail($argv[2] ?? null);
        $evidence['run_id'] = $run->id;
        if ($evidence['action'] === 'pause') {
            if ($run->status === RunStatus::Paused) {
                $evidence['outcome'] = 'already_paused';
            } else {
                app(RequestRunStopAction::class)->pause(run: $run, requestedBy: null);
                $evidence['outcome'] = 'pause_requested';
            }
        } else {
            $latestPaused = Run::query()->where('crawler_id', $crawler->id)
                ->where('purpose', RunPurpose::Profile)->whereNull('subject_key')
                ->where('status', RunStatus::Paused)->latest('id')->value('id');
            if ($run->status !== RunStatus::Paused || $run->id !== $latestPaused
                || $run->purpose !== RunPurpose::Profile || $run->subject_key !== null
                || $run->stop_requested_at !== null
            ) {
                throw new LogicException('Only the latest paused profile run without a pending stop may be resumed.');
            }
            // The bounded profile owns the January date window.
            app(StartCrawlerRunAction::class)->resume(crawler: $crawler);
            $evidence['outcome'] = 'resume_queued';
        }
    } else {
        throw new InvalidArgumentException('Usage: control.php start | pause RUN_ID | resume RUN_ID');
    }
    echo json_encode($evidence, JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) {
    $evidence['outcome'] = 'refused_or_failed';
    $evidence['exception_class'] = $exception::class;
    echo json_encode($evidence, JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
