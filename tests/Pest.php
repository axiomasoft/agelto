<?php

declare(strict_types=1);

use App\Interfaces\Crawling\BrowserClient;
use App\Models\Crawler\CrawlerRun;
use App\Services\AppLogger;
use Illuminate\Support\Sleep;
use Sys\Models\SysLog;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Журнал сущности (sys_logs) содержит веху с сообщением по LIKE-шаблону.
 * Пример: expect($run)->toHaveLoggedMessage('%Старт прогона%').
 */
expect()->extend('toHaveLoggedMessage', function (string $messageLike) {
    expect(sysLogExists($messageLike, $this->value->getKey()))->toBeTrue(
        "Ожидали веху в sys_logs по сущности #{$this->value->getKey()} с сообщением LIKE '{$messageLike}'."
    );

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Доменные тест-хелперы. Персистентные модели создаём через фабрики
| (X::factory()), здесь — только сборки моков и точечные проверки журнала.
|
*/

/**
 * Есть ли в журнале sys_logs запись по LIKE-шаблону (опционально — по сущности).
 */
function sysLogExists(string $messageLike, ?int $entityId = null): bool
{
    $query = SysLog::query()->where('message', 'like', $messageLike);

    if ($entityId !== null) {
        $query->where('entity_id', $entityId);
    }

    return $query->exists();
}

/**
 * Мок браузера для краулинга: прокси и репорты заглушены, реальной сети нет.
 */
function fakeBrowserClient(): BrowserClient
{
    $browser = Mockery::mock(BrowserClient::class);
    $browser->shouldReceive('withRandomProxy')->andReturnSelf();
    $browser->shouldReceive('reportSuccess')->andReturnNull();
    $browser->shouldReceive('reportFail')->andReturnNull();
    $browser->shouldReceive('close')->andReturnNull();

    return $browser;
}

/**
 * Логгер с контекстом конкретного прогона краулера — для проверок журналирования.
 */
function loggerForRun(CrawlerRun $run): AppLogger
{
    $logger = app(AppLogger::class);
    $logger->setContext([
        'entity_type' => CrawlerRun::class,
        'entity_id' => $run->id,
        'stage' => 'Crawling',
        'source' => 'crawler',
    ]);

    return $logger;
}
