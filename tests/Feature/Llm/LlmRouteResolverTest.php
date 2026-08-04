<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Llm\Providers\PrismGeminiProvider;

function llmRouteResolver(): LlmRouteResolver
{
    return app(LlmRouteResolver::class);
}

test('resolve() выбирает активный маршрут с минимальным priority', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-pro',
        'priority' => 5,
    ]);
    $primary = LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);

    $resolved = llmRouteResolver()->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);

    expect($resolved->options->model)->toBe('gemini-2.5-flash')
        ->and($resolved->options->provider)->toBe($provider->code)
        ->and($resolved->route->is($primary))->toBeTrue()
        ->and($resolved->provider)->toBeInstanceOf(PrismGeminiProvider::class);
});

test('candidatesFor() отдаёт весь список priority ASC для эскалации', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id, 'priority' => 10,
    ]);
    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id, 'priority' => 1,
    ]);

    $priorities = llmRouteResolver()
        ->candidatesFor(AiDocumentType::Estimate, CoreLlmPurpose::Screening)
        ->map(fn ($resolved) => $resolved->route->priority)
        ->values()
        ->all();

    expect($priorities)->toBe([1, 10]);
});

test('точный document_type предпочитается зарезервированному any при равном priority', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'any', 'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id, 'model' => 'generic-model', 'priority' => 0,
    ]);
    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id, 'model' => 'estimate-specific-model', 'priority' => 0,
    ]);

    $resolved = llmRouteResolver()->resolve(AiDocumentType::Estimate, CoreLlmPurpose::Screening);

    expect($resolved->options->model)->toBe('estimate-specific-model');
});

test('неактивные маршруты не участвуют в резолве', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id, 'is_active' => false, 'priority' => 0,
    ]);

    expect(llmRouteResolver()->candidatesFor(AiDocumentType::Estimate, CoreLlmPurpose::Screening))->toBeEmpty();
});

test('resolve() бросает исключение, если нет ни одного активного маршрута', function (): void {
    llmRouteResolver()->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractTotals);
})->throws(RuntimeException::class);

test('неизвестный driver провайдера бросает исключение', function (): void {
    $provider = Provider::factory()->create(['driver' => 'unknown-driver']);
    LlmRoute::factory()->create([
        'document_type' => 'estimate', 'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id, 'priority' => 0,
    ]);

    llmRouteResolver()->resolve(AiDocumentType::Estimate, CoreLlmPurpose::Screening);
})->throws(RuntimeException::class);

test('resolve() не принимает произвольную строку — неизвестный document_type не резолвится в кросс-документный маршрут, а рвётся типом', function (): void {
    /** @phpstan-ignore-next-line намеренно нарушаем типизацию — негативный тест на семантику отказа */
    llmRouteResolver()->resolve('not-a-real-type', CoreLlmPurpose::Screening);
})->throws(TypeError::class);

// D99 (P2.9): бюджет маршрута (`llm_routes.options.max_tokens`) сверх ИЗВЕСТНОГО потолка вывода
// модели (`config('ai.capabilities.<model>.max_output_tokens')`) — fail fast, не молчаливый кламп
// (тихо урезанный бюджет — операторская опечатка, живущая годами).
test('resolve() бросает исключение, когда бюджет маршрута выше известного потолка вывода модели', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-3.5-flash', // config('ai.capabilities.gemini-3.5-flash.max_output_tokens') = 65536
        'priority' => 0,
        'options' => ['max_tokens' => 70000],
    ]);

    llmRouteResolver()->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);
})->throws(RuntimeException::class);

test('resolve() не бросает исключение, если потолок вывода модели неизвестен (ключ max_output_tokens отсутствует в capability-матрице)', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash', // capability-запись без max_output_tokens (не RAG-подтверждён)
        'priority' => 0,
        'options' => ['max_tokens' => 999999],
    ]);

    $resolved = llmRouteResolver()->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);

    expect($resolved->options->maxTokens)->toBe(999999);
});

// D98/D99 (P2.9): `LlmOptions::$thinkingLevel` ← `llm_routes.options.thinking_level` — только
// `thinkingLevel`, `thinkingBudget` не эмитится (рычаг линейки 2.5, несовместим в одном запросе
// Gemini 3 с `thinkingLevel`).
test('resolve() прокидывает thinking_level из options маршрута в LlmOptions::$thinkingLevel', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'priority' => 0,
        'options' => ['thinking_level' => 'LOW'],
    ]);

    $resolved = llmRouteResolver()->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);

    expect($resolved->options->thinkingLevel)->toBe('LOW');
});

test('resolve() оставляет thinkingLevel null, если thinking_level в options маршрута не задан', function (): void {
    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'priority' => 0,
    ]);

    $resolved = llmRouteResolver()->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);

    expect($resolved->options->thinkingLevel)->toBeNull();
});
