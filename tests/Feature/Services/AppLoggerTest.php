<?php

declare(strict_types=1);

use App\Enums\LogOutput;
use App\Models\Crawler\CrawlerRun;
use App\Services\AppLogger;
use Sys\Models\SysLog;

test('event() пишет веху в sys_logs с привязкой к сущности', function () {
    $run = CrawlerRun::factory()->create();
    $logger = loggerForRun($run);

    $logger->event('веха старта');

    expect(SysLog::query()
        ->where('entity_type', CrawlerRun::class)
        ->where('entity_id', $run->id)
        ->where('level', 'info')
        ->where('message', 'веха старта')
        ->exists())->toBeTrue();
});

test('info() не попадает в журнал, а error() — попадает', function () {
    $run = CrawlerRun::factory()->create();
    $logger = loggerForRun($run);

    $logger->info('просто инфо');
    expect(SysLog::query()->where('entity_id', $run->id)->count())->toBe(0);

    $logger->error('случилась ошибка');
    expect(SysLog::query()
        ->where('entity_id', $run->id)
        ->where('level', 'error')
        ->where('message', 'случилась ошибка')
        ->exists())->toBeTrue();
});

test('без контекста сущности в журнал ничего не пишет', function () {
    $logger = app(AppLogger::class);
    $logger->clearContext();

    $logger->error('безадресная ошибка');

    expect(SysLog::query()->count())->toBe(0);
});

test('debug() не пишет в журнал', function () {
    $run = CrawlerRun::factory()->create();
    $logger = loggerForRun($run);

    $logger->debug('детальный трейс');

    expect(SysLog::query()->where('entity_id', $run->id)->count())->toBe(0);
});

test('пишет в канал pipeline — JSON daily', function () {
    // Запись не должна падать — канал должен быть сконфигурирован.
    app(AppLogger::class)->info('тест', LogOutput::File, ['stage' => 'crawling']);

    expect(config('logging.channels.pipeline.driver'))->toBe('daily')
        ->and(config('logging.channels.pipeline.formatter'))->toBe(Monolog\Formatter\JsonFormatter::class);
});
