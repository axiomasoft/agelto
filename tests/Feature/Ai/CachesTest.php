<?php

declare(strict_types=1);

use App\Enums\Procurement\FinishReason;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Carbon\CarbonImmutable;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * P3.2 — `ai:caches` бьёт через `artisan(...)` (Implementation Rule 7): сервис `LlmCacheManager`
 * уже покрыт `LlmCacheManagerTest`, здесь проверяется только команда — разбор `action`/`--hits`/
 * `--window` и печать.
 */
test('list без активных explicit-кэшей — сообщение без падения', function (): void {
    $this->artisan('ai:caches', ['action' => 'list'])
        ->assertExitCode(0)
        ->expectsOutputToContain('сейчас нет');
});

test('list печатает активные explicit-кэши таблицей', function (): void {
    Analysis::factory()->create([
        'provider' => 'gemini',
        'cache_id' => 'cachedContents/abc',
        'cache_expires_at' => CarbonImmutable::now()->addHour(),
    ]);

    // Одно ожидание на строку вывода (как в CostReportTest) — analysis_id и cache_id живут
    // в ОДНОЙ строке таблицы, второе совпадающее ожидание «съело» бы первое.
    $this->artisan('ai:caches', ['action' => 'list'])
        ->assertExitCode(0)
        ->expectsOutputToContain('cachedContents/abc');
});

test('list --hits без вызовов LLM — сообщение без падения', function (): void {
    $this->artisan('ai:caches', ['action' => 'list', '--hits' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('не найдено');
});

test('prune снимает регистрацию просроченного кэша через фабрику провайдера', function (): void {
    $stale = Analysis::factory()->create([
        'provider' => 'gemini',
        'cache_id' => 'cachedContents/stale',
        'cache_expires_at' => CarbonImmutable::now()->subMinute(),
    ]);

    app()->instance(PrismGeminiProvider::class, FakeLlmProvider::succeeding(
        new LlmResponse(
            data: 'ok',
            usage: new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0),
            finishReason: FinishReason::Stop,
            raw: '',
        ),
    ));

    $this->artisan('ai:caches', ['action' => 'prune'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Снято регистраций кэша: 1');

    expect($stale->fresh()->cache_id)->toBeNull();
});

test('неизвестное действие — ошибка и код возврата 1', function (): void {
    $this->artisan('ai:caches', ['action' => 'bogus'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Неизвестное действие');
});
