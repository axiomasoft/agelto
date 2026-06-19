<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

use Illuminate\Support\Sleep;

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
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Профиль краулера с платформой — общий хелпер для тестов краулинга.
 */
function crawlerProfile(array $attributes = []): App\Models\Crawler\Crawler
{
    $platform = App\Models\Platform::query()->create([
        'name' => 'ЕИС',
        'code' => 'eis-'.uniqid(),
        'url' => 'https://zakupki.gov.ru',
    ]);

    return App\Models\Crawler\Crawler::query()->create(array_merge([
        'platform_id' => $platform->id,
        'url' => 'https://zakupki.gov.ru',
    ], $attributes));
}

function crawlerRunManager(): App\Process\Crawling\Run\CrawlerRunManager
{
    return new App\Process\Crawling\Run\CrawlerRunManager(app(App\Services\AppLogger::class));
}
