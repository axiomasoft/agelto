<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\LlmPurpose;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\Analysis;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Dto\CacheableContent;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\LlmCacheManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Tests\Support\Doubles\FakeLlmProvider;

function llmCacheManager(): LlmCacheManager
{
    return new LlmCacheManager(new AnalysisStoreRepository);
}

function fakeCachingProvider(): FakeLlmProvider
{
    return FakeLlmProvider::succeeding(new LlmResponse(
        data: 'ok',
        usage: new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0),
        finishReason: FinishReason::Stop,
        raw: '',
    ));
}

test('create() регистрирует explicit cache на Analysis и возвращает handle', function (): void {
    $analysis = Analysis::factory()->create();
    $provider = fakeCachingProvider();

    $handle = llmCacheManager()->create(
        analysis: $analysis,
        provider: $provider,
        content: new CacheableContent(systemPart: 'системная часть промта'),
        model: 'gemini-2.5-flash',
        ttl: CarbonInterval::hours(1),
        rulesHash: 'hash-v1',
    );

    expect($handle)->not->toBeNull()
        ->and($provider->createdCaches())->toHaveCount(1);

    $analysis->refresh();
    expect($analysis->cache_id)->toBe($handle->id)
        ->and($analysis->rules_hash)->toBe('hash-v1')
        ->and($analysis->cache_expires_at)->not->toBeNull();
});

test('create() провайдер без SupportsContextCaching → null без похода в API', function (): void {
    $analysis = Analysis::factory()->create();
    $provider = new class implements LlmProvider
    {
        public function complete($prompt, $options): LlmResponse
        {
            throw new RuntimeException('не должен вызываться');
        }

        public function completeStructured($prompt, $schema, $options): LlmStructuredResponse
        {
            throw new RuntimeException('не должен вызываться');
        }
    };

    $handle = llmCacheManager()->create(
        analysis: $analysis,
        provider: $provider,
        content: new CacheableContent(systemPart: 'x'),
        model: 'local-model',
        ttl: CarbonInterval::hours(1),
        rulesHash: 'hash',
    );

    expect($handle)->toBeNull();
    expect($analysis->fresh()->cache_id)->toBeNull();
});

test('create() ошибка провайдера → warning, путь без кэша, Analysis не тронут', function (): void {
    $analysis = Analysis::factory()->create();
    $provider = fakeCachingProvider()->cacheFailing();

    $handle = llmCacheManager()->create(
        analysis: $analysis,
        provider: $provider,
        content: new CacheableContent(systemPart: 'x'),
        model: 'gemini-2.5-flash',
        ttl: CarbonInterval::hours(1),
        rulesHash: 'hash',
    );

    expect($handle)->toBeNull();
    expect($analysis->fresh()->cache_id)->toBeNull();
});

test('validHandle() валиден при совпадении rules_hash и живом TTL', function (): void {
    $analysis = Analysis::factory()->create([
        'cache_id' => 'cachedContents/abc',
        'cache_expires_at' => CarbonImmutable::now()->addHour(),
        'rules_hash' => 'hash-v1',
    ]);

    $handle = llmCacheManager()->validHandle($analysis, 'hash-v1');

    expect($handle)->not->toBeNull()
        ->and($handle->id)->toBe('cachedContents/abc');
});

test('validHandle() null при смене rules_hash (промты изменились)', function (): void {
    $analysis = Analysis::factory()->create([
        'cache_id' => 'cachedContents/abc',
        'cache_expires_at' => CarbonImmutable::now()->addHour(),
        'rules_hash' => 'hash-v1',
    ]);

    expect(llmCacheManager()->validHandle($analysis, 'hash-v2'))->toBeNull();
});

test('validHandle() null при истёкшем TTL', function (): void {
    $analysis = Analysis::factory()->create([
        'cache_id' => 'cachedContents/abc',
        'cache_expires_at' => CarbonImmutable::now()->subMinute(),
        'rules_hash' => 'hash-v1',
    ]);

    expect(llmCacheManager()->validHandle($analysis, 'hash-v1'))->toBeNull();
});

test('validHandle() null без зарегистрированного кэша', function (): void {
    $analysis = Analysis::factory()->create();

    expect(llmCacheManager()->validHandle($analysis, 'любой'))->toBeNull();
});

test('refresh() продлевает TTL и обновляет cache_expires_at на Analysis', function (): void {
    $analysis = Analysis::factory()->create([
        'cache_id' => 'cachedContents/abc',
        'cache_expires_at' => CarbonImmutable::now()->addMinutes(5),
        'rules_hash' => 'hash-v1',
    ]);
    $provider = fakeCachingProvider();

    $expectedExpiry = CarbonImmutable::now()->addHours(2);
    llmCacheManager()->refresh($analysis, $provider, CarbonInterval::hours(2));

    $expiresAt = $analysis->fresh()->cache_expires_at;

    expect($expiresAt->diffInMinutes($expectedExpiry, true))->toBeLessThan(1);
});

test('delete() удаляет кэш у провайдера и снимает регистрацию с Analysis', function (): void {
    $analysis = Analysis::factory()->create([
        'cache_id' => 'cachedContents/abc',
        'cache_expires_at' => CarbonImmutable::now()->addHour(),
        'rules_hash' => 'hash-v1',
    ]);
    $provider = fakeCachingProvider();

    llmCacheManager()->delete($analysis, $provider);

    expect($provider->deletedCacheIds())->toBe(['cachedContents/abc']);

    $analysis->refresh();
    expect($analysis->cache_id)->toBeNull()
        ->and($analysis->cache_expires_at)->toBeNull()
        ->and($analysis->rules_hash)->toBeNull();
});

test('delete() ошибка провайдера всё равно снимает регистрацию (best-effort)', function (): void {
    $analysis = Analysis::factory()->create([
        'cache_id' => 'cachedContents/abc',
        'cache_expires_at' => CarbonImmutable::now()->addHour(),
        'rules_hash' => 'hash-v1',
    ]);
    $provider = fakeCachingProvider()->cacheFailing();

    llmCacheManager()->delete($analysis, $provider);

    expect($analysis->fresh()->cache_id)->toBeNull();
});

test('listActive() отдаёт только анализы с зарегистрированным cache_id', function (): void {
    Analysis::factory()->create(['cache_id' => 'cachedContents/a', 'cache_expires_at' => CarbonImmutable::now()->addHour()]);
    Analysis::factory()->create(['cache_id' => null]);

    expect(llmCacheManager()->listActive())->toHaveCount(1);
});

test('prune() снимает регистрацию просроченных по TTL кэшей', function (): void {
    $stale = Analysis::factory()->create([
        'provider' => 'gemini',
        'cache_id' => 'cachedContents/stale',
        'cache_expires_at' => CarbonImmutable::now()->subMinute(),
    ]);
    $fresh = Analysis::factory()->create([
        'provider' => 'gemini',
        'cache_id' => 'cachedContents/fresh',
        'cache_expires_at' => CarbonImmutable::now()->addHour(),
    ]);
    $provider = fakeCachingProvider();

    $pruned = llmCacheManager()->prune(fn (string $driver): FakeLlmProvider => $provider);

    expect($pruned)->toBe(1)
        ->and($stale->fresh()->cache_id)->toBeNull()
        ->and($fresh->fresh()->cache_id)->toBe('cachedContents/fresh');
});

test('prune() снимает регистрацию у финализированных (terminal-статус) анализов', function (): void {
    $succeeded = Analysis::factory()->create([
        'provider' => 'gemini',
        'status' => AnalysisStatus::Succeeded,
        'cache_id' => 'cachedContents/done',
        'cache_expires_at' => CarbonImmutable::now()->addHour(),
    ]);
    $provider = fakeCachingProvider();

    llmCacheManager()->prune(fn (string $driver): FakeLlmProvider => $provider);

    expect($succeeded->fresh()->cache_id)->toBeNull();
});

test('implicitHitStats() считает долю cached_tokens по purpose за окно', function (): void {
    LlmCall::factory()->create([
        'purpose' => LlmPurpose::ExtractRows,
        'prompt_tokens' => 100,
        'cached_tokens' => 300,
        'called_at' => CarbonImmutable::now(),
    ]);
    LlmCall::factory()->create([
        'purpose' => LlmPurpose::ExtractRows,
        'prompt_tokens' => 100,
        'cached_tokens' => 100,
        'called_at' => CarbonImmutable::now(),
    ]);
    LlmCall::factory()->create([
        'purpose' => LlmPurpose::Screening,
        'prompt_tokens' => 1000,
        'cached_tokens' => 0,
        'called_at' => CarbonImmutable::now()->subDays(2),
    ]);

    $stats = llmCacheManager()->implicitHitStats(CarbonInterval::hours(24));
    $rows = $stats->keyBy('purpose');

    expect($stats)->toHaveCount(1)
        ->and($rows['extract_rows']->calls)->toBe(2)
        ->and($rows['extract_rows']->prompt_tokens)->toBe(200)
        ->and($rows['extract_rows']->cached_tokens)->toBe(400)
        ->and($rows['extract_rows']->hit_ratio)->toBe(round(400 / 600, 4));
});
