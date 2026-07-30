<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Services\Llm\AiRouteResolver;
use Database\Seeders\AiRoutingSeeder;

test('сидер создаёт провайдера gemini и стартовую матрицу маршрутов', function (): void {
    (new AiRoutingSeeder)->run();

    $provider = Provider::query()->where('code', 'gemini')->sole();
    expect($provider->driver)->toBe('gemini')
        ->and($provider->is_active)->toBeTrue();

    expect(LlmRoute::query()->count())->toBe(11);

    $screening = LlmRoute::query()
        ->where('document_type', 'any')
        ->where('purpose', CoreLlmPurpose::Screening->value)
        ->where('priority', 0)
        ->sole();
    expect($screening->model)->toBe('gemini-3.1-flash-lite')
        ->and($screening->provider_id)->toBe($provider->id)
        ->and($screening->is_active)->toBeTrue()
        ->and($screening->shadow)->toBeFalse();

    $discovery = LlmRoute::query()
        ->where('document_type', 'estimate')
        ->where('purpose', EstimateLlmPurpose::Discovery->value)
        ->where('priority', 0)
        ->sole();
    expect($discovery->model)->toBe('gemini-3.1-flash-lite');

    $extractRows = LlmRoute::query()
        ->where('document_type', 'estimate')
        ->where('purpose', EstimateLlmPurpose::ExtractRows->value)
        ->where('priority', 0)
        ->sole();
    expect($extractRows->model)->toBe('gemini-3.5-flash');

    $escalation = LlmRoute::query()
        ->where('document_type', 'estimate')
        ->where('purpose', EstimateLlmPurpose::ExtractRows->value)
        ->where('priority', 1)
        ->sole();
    expect($escalation->model)->toBe('gemini-3.1-pro-preview');

    $promptCritic = LlmRoute::query()
        ->where('document_type', 'any')
        ->where('purpose', CoreLlmPurpose::PromptCritic->value)
        ->where('priority', 0)
        ->sole();
    expect($promptCritic->model)->toBe('gemini-3.1-flash-lite')
        ->and($promptCritic->provider_id)->toBe($provider->id);
});

test('сидер идемпотентен — повторный запуск не плодит дублей и не создаёт второго провайдера', function (): void {
    (new AiRoutingSeeder)->run();
    (new AiRoutingSeeder)->run();

    expect(Provider::query()->count())->toBe(1)
        ->and(LlmRoute::query()->count())->toBe(11);
});

test('после сидирования AiRouteResolver находит активный маршрут для каждого purpose стартовой матрицы', function (): void {
    (new AiRoutingSeeder)->run();
    $resolver = app(AiRouteResolver::class);

    expect($resolver->resolve(DocumentTypeScope::Any, CoreLlmPurpose::Screening)->options->model)->toBe('gemini-3.1-flash-lite')
        ->and($resolver->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::Discovery)->options->model)->toBe('gemini-3.1-flash-lite')
        ->and($resolver->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractPassport)->options->model)->toBe('gemini-3.5-flash')
        ->and($resolver->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows)->options->model)->toBe('gemini-3.5-flash')
        ->and($resolver->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractTotals)->options->model)->toBe('gemini-3.5-flash');

    $candidates = $resolver->candidatesFor(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows)
        ->map(fn ($resolved) => $resolved->options->model)
        ->values()
        ->all();
    expect($candidates)->toBe(['gemini-3.5-flash', 'gemini-3.1-pro-preview']);
});
