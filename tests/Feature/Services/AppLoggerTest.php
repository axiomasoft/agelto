<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\CrawlerRun;
use App\Services\AppLogger;
use Sys\Models\SysLog;

function loggerBoundToRun(): array
{
    $crawler = crawlerProfile();
    $run = CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'started_at' => now(),
    ]);

    $logger = app(AppLogger::class);
    $logger->setContext([
        'entity_type' => CrawlerRun::class,
        'entity_id' => $run->id,
        'stage' => 'Crawling',
        'source' => 'crawler',
    ]);

    return [$logger, $run];
}

it('event() пишет веху в sys_logs с привязкой к сущности', function () {
    [$logger, $run] = loggerBoundToRun();

    $logger->event('веха старта');

    expect(SysLog::query()
        ->where('entity_type', CrawlerRun::class)
        ->where('entity_id', $run->id)
        ->where('level', 'info')
        ->where('message', 'веха старта')
        ->exists())->toBeTrue();
});

it('info() не попадает в журнал, а error() — попадает', function () {
    [$logger, $run] = loggerBoundToRun();

    $logger->info('просто инфо');
    expect(SysLog::query()->where('entity_id', $run->id)->count())->toBe(0);

    $logger->error('случилась ошибка');
    expect(SysLog::query()
        ->where('entity_id', $run->id)
        ->where('level', 'error')
        ->where('message', 'случилась ошибка')
        ->exists())->toBeTrue();
});

it('без контекста сущности в журнал ничего не пишет', function () {
    $logger = app(AppLogger::class);
    $logger->clearContext();

    $logger->error('безадресная ошибка');

    expect(SysLog::query()->count())->toBe(0);
});

it('debug() не пишет в журнал', function () {
    [$logger, $run] = loggerBoundToRun();

    $logger->debug('детальный трейс');

    expect(SysLog::query()->where('entity_id', $run->id)->count())->toBe(0);
});
