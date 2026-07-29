<?php

declare(strict_types=1);

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\LlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Services\Llm\AiRouteResolver;
use App\Services\Llm\Providers\PrismGeminiProvider;

function aiRouteResolver(): AiRouteResolver
{
    return app(AiRouteResolver::class);
}

test('resolve() выбирает активный маршрут с минимальным priority', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => LlmPurpose::ExtractRows,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-pro',
        'priority' => 5,
    ]);
    $primary = LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => LlmPurpose::ExtractRows,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);

    $resolved = aiRouteResolver()->resolve(AiDocumentType::Estimate, LlmPurpose::ExtractRows);

    expect($resolved->options->model)->toBe('gemini-2.5-flash')
        ->and($resolved->options->provider)->toBe($provider->code)
        ->and($resolved->route->is($primary))->toBeTrue()
        ->and($resolved->provider)->toBeInstanceOf(PrismGeminiProvider::class);
});

test('candidatesFor() отдаёт весь список priority ASC для эскалации', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'priority' => 10,
    ]);
    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'priority' => 1,
    ]);

    $priorities = aiRouteResolver()
        ->candidatesFor(AiDocumentType::Estimate, LlmPurpose::Screening)
        ->map(fn ($resolved) => $resolved->route->priority)
        ->values()
        ->all();

    expect($priorities)->toBe([1, 10]);
});

test('точный document_type предпочитается зарезервированному any при равном priority', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'any', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'model' => 'generic-model', 'priority' => 0,
    ]);
    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'model' => 'estimate-specific-model', 'priority' => 0,
    ]);

    $resolved = aiRouteResolver()->resolve(AiDocumentType::Estimate, LlmPurpose::Screening);

    expect($resolved->options->model)->toBe('estimate-specific-model');
});

test('неактивные маршруты не участвуют в резолве', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'is_active' => false, 'priority' => 0,
    ]);

    expect(aiRouteResolver()->candidatesFor(AiDocumentType::Estimate, LlmPurpose::Screening))->toBeEmpty();
});

test('resolve() бросает исключение, если нет ни одного активного маршрута', function (): void {
    aiRouteResolver()->resolve(AiDocumentType::Estimate, LlmPurpose::ExtractTotals);
})->throws(RuntimeException::class);

test('неизвестный driver провайдера бросает исключение', function (): void {
    $provider = Provider::factory()->create(['driver' => 'unknown-driver']);
    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => LlmPurpose::Screening,
        'provider_id' => $provider->id, 'priority' => 0,
    ]);

    aiRouteResolver()->resolve(AiDocumentType::Estimate, LlmPurpose::Screening);
})->throws(RuntimeException::class);

test('resolve() не принимает произвольную строку — неизвестный document_type не резолвится в кросс-документный маршрут, а рвётся типом', function (): void {
    /** @phpstan-ignore-next-line намеренно нарушаем типизацию — негативный тест на семантику отказа */
    aiRouteResolver()->resolve('not-a-real-type', LlmPurpose::Screening);
})->throws(TypeError::class);
