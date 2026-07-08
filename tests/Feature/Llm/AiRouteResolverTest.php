<?php

declare(strict_types=1);

use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use App\Models\Procurement\Ai\Provider;
use App\Services\Llm\AiRouteResolver;
use App\Services\Llm\Providers\PrismGeminiProvider;

function aiRouteResolver(): AiRouteResolver
{
    return app(AiRouteResolver::class);
}

test('resolve() выбирает активный маршрут с минимальным priority', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    AiRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => LlmPurpose::ExtractRows,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-pro',
        'priority' => 5,
    ]);
    $primary = AiRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => LlmPurpose::ExtractRows,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);

    $resolved = aiRouteResolver()->resolve('estimate', LlmPurpose::ExtractRows);

    expect($resolved->options->model)->toBe('gemini-2.5-flash')
        ->and($resolved->options->provider)->toBe($provider->code)
        ->and($resolved->route->is($primary))->toBeTrue()
        ->and($resolved->provider)->toBeInstanceOf(PrismGeminiProvider::class);
});

test('candidatesFor() отдаёт весь список priority ASC для эскалации', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    AiRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'priority' => 10,
    ]);
    AiRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'priority' => 1,
    ]);

    $priorities = aiRouteResolver()
        ->candidatesFor('estimate', LlmPurpose::Screening)
        ->map(fn ($resolved) => $resolved->route->priority)
        ->values()
        ->all();

    expect($priorities)->toBe([1, 10]);
});

test('точный document_type предпочитается зарезервированному any при равном priority', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    AiRoute::factory()->create([
        'document_type' => 'any', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'model' => 'generic-model', 'priority' => 0,
    ]);
    AiRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'model' => 'estimate-specific-model', 'priority' => 0,
    ]);

    $resolved = aiRouteResolver()->resolve('estimate', LlmPurpose::Screening);

    expect($resolved->options->model)->toBe('estimate-specific-model');
});

test('неактивные маршруты не участвуют в резолве', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    AiRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'is_active' => false, 'priority' => 0,
    ]);

    expect(aiRouteResolver()->candidatesFor('estimate', LlmPurpose::Screening))->toBeEmpty();
});

test('resolve() бросает исключение, если нет ни одного активного маршрута', function (): void {
    aiRouteResolver()->resolve('estimate', LlmPurpose::ExtractTotals);
})->throws(RuntimeException::class);

test('неизвестный driver провайдера бросает исключение', function (): void {
    $provider = Provider::factory()->create(['driver' => 'unknown-driver']);
    AiRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'priority' => 0,
    ]);

    aiRouteResolver()->resolve('estimate', LlmPurpose::Screening);
})->throws(RuntimeException::class);
