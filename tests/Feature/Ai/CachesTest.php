<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\ProviderReportFixtures;

beforeEach(fn () => ProviderReportFixtures::reset());

test('cache CLI lists canonical assets and known usage without a model API call', function (): void {
    ProviderReportFixtures::store(['cached_tokens' => 50, 'prompt_tokens' => 100]);
    $this->artisan('ai:caches', ['action' => 'list'])->assertSuccessful();
    $this->artisan('ai:caches', ['action' => 'list', '--hits' => true])->expectsOutputToContain('generate')->assertSuccessful();
    expect(DB::table('llm_provider_calls')->count())->toBe(1);
    Http::assertNothingSent();
});

test('cache CLI rejects unowned remote prune and invalid action without new financial effects', function (): void {
    $this->artisan('ai:caches', ['action' => 'prune'])->expectsOutputToContain('файлов 0, кэшей 0')->assertSuccessful();
    $this->artisan('ai:caches', ['action' => 'bogus'])->expectsOutputToContain('Unsupported')->assertFailed();
    expect(DB::table('llm_provider_requests')->count())->toBe(0);
    Http::assertNothingSent();
});
