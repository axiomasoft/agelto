<?php

declare(strict_types=1);

use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use App\Models\Procurement\Ai\Provider;
use App\Services\Llm\AiRouteResolver;
use Database\Seeders\AiRoutingSeeder;

test('сидер создаёт провайдера gemini и стартовую матрицу маршрутов', function (): void {
    (new AiRoutingSeeder)->run();

    $provider = Provider::query()->where('code', 'gemini')->sole();
    expect($provider->driver)->toBe('gemini')
        ->and($provider->is_active)->toBeTrue();

    expect(AiRoute::query()->count())->toBe(11);

    $screening = AiRoute::query()
        ->where('document_type', 'any')
        ->where('purpose', LlmPurpose::Screening->value)
        ->where('priority', 0)
        ->sole();
    expect($screening->model)->toBe('gemini-2.5-flash-lite')
        ->and($screening->provider_id)->toBe($provider->id)
        ->and($screening->is_active)->toBeTrue()
        ->and($screening->shadow)->toBeFalse();

    $discovery = AiRoute::query()
        ->where('document_type', 'estimate')
        ->where('purpose', LlmPurpose::Discovery->value)
        ->where('priority', 0)
        ->sole();
    expect($discovery->model)->toBe('gemini-2.5-flash-lite');

    $extractRows = AiRoute::query()
        ->where('document_type', 'estimate')
        ->where('purpose', LlmPurpose::ExtractRows->value)
        ->where('priority', 0)
        ->sole();
    expect($extractRows->model)->toBe('gemini-2.5-flash');

    $escalation = AiRoute::query()
        ->where('document_type', 'estimate')
        ->where('purpose', LlmPurpose::ExtractRows->value)
        ->where('priority', 1)
        ->sole();
    expect($escalation->model)->toBe('gemini-2.5-pro');

    $promptCritic = AiRoute::query()
        ->where('document_type', 'any')
        ->where('purpose', LlmPurpose::PromptCritic->value)
        ->where('priority', 0)
        ->sole();
    expect($promptCritic->model)->toBe('gemini-2.5-flash-lite')
        ->and($promptCritic->provider_id)->toBe($provider->id);
});

test('сидер идемпотентен — повторный запуск не плодит дублей и не создаёт второго провайдера', function (): void {
    (new AiRoutingSeeder)->run();
    (new AiRoutingSeeder)->run();

    expect(Provider::query()->count())->toBe(1)
        ->and(AiRoute::query()->count())->toBe(11);
});

test('после сидирования AiRouteResolver находит активный маршрут для каждого purpose стартовой матрицы', function (): void {
    (new AiRoutingSeeder)->run();
    $resolver = app(AiRouteResolver::class);

    expect($resolver->resolve('any', LlmPurpose::Screening)->options->model)->toBe('gemini-2.5-flash-lite')
        ->and($resolver->resolve('estimate', LlmPurpose::Discovery)->options->model)->toBe('gemini-2.5-flash-lite')
        ->and($resolver->resolve('estimate', LlmPurpose::ExtractPassport)->options->model)->toBe('gemini-2.5-flash')
        ->and($resolver->resolve('estimate', LlmPurpose::ExtractRows)->options->model)->toBe('gemini-2.5-flash')
        ->and($resolver->resolve('estimate', LlmPurpose::ExtractTotals)->options->model)->toBe('gemini-2.5-flash');

    $candidates = $resolver->candidatesFor('estimate', LlmPurpose::ExtractRows)
        ->map(fn ($resolved) => $resolved->options->model)
        ->values()
        ->all();
    expect($candidates)->toBe(['gemini-2.5-flash', 'gemini-2.5-pro']);
});
