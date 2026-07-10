<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Llm\Validation\ReaskPolicy;
use App\Services\Llm\Validation\StructuredResponseValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\Doubles\FakeLlmProvider;

// Директория tests/Unit не подключает RefreshDatabase по умолчанию (tests/Pest.php); routeWith()
// материализует AiRoute::factory()->make() с belongsTo-фабрикой Provider::factory(), которая
// персистит связанного провайдера даже при make() — нужна тестовая БД, подключаем трейт точечно.
uses(RefreshDatabase::class);

function reaskPolicy(): ReaskPolicy
{
    return new ReaskPolicy(new StructuredResponseValidator);
}

function basePrompt(): LlmPrompt
{
    return new LlmPrompt(systemPart: 'система', userParts: ['документ']);
}

function noopSchema(): JsonSchema
{
    return new JsonSchema(schema: []);
}

function zeroLlmUsage(): LlmUsage
{
    return new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0);
}

function routeWith(FakeLlmProvider $provider, int $priority = 0): ResolvedRoute
{
    return new ResolvedRoute(
        provider: $provider,
        options: new LlmOptions(provider: 'gemini', model: 'gemini-2.5-flash', temperature: 0.0, maxTokens: 8192),
        route: AiRoute::factory()->make(['priority' => $priority, 'purpose' => LlmPurpose::Screening]),
    );
}

function structuredOk(string $raw): LlmStructuredResponse
{
    return new LlmStructuredResponse(data: [], usage: zeroLlmUsage(), finishReason: FinishReason::Stop, raw: $raw);
}

test('успешный первый ответ возвращается без re-ask и без эскалации', function (): void {
    $provider = FakeLlmProvider::succeeding(structuredOk('{"document_type": "estimate", "confidence": 0.9}'));
    $candidates = new Collection([routeWith($provider)]);

    $outcome = reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, $candidates);

    expect($outcome->isOk())->toBeTrue()
        ->and($outcome->data)->toBeInstanceOf(ScreeningResult::class)
        ->and($provider->receivedPrompts())->toHaveCount(1);
});

test('невалидный JSON re-ask’ается на том же маршруте с текстом ошибки и прошлым выводом, затем проходит', function (): void {
    $provider = FakeLlmProvider::succeeding(structuredOk('{"document_type": "estimate", "confidence": 0.9')) // невалидный JSON
        ->andThen(structuredOk('{"document_type": "estimate", "confidence": 0.9}')); // исправленный
    $candidates = new Collection([routeWith($provider)]);

    $outcome = reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, $candidates);

    expect($outcome->isOk())->toBeTrue()
        ->and($provider->receivedPrompts())->toHaveCount(2);

    $reaskPrompt = $provider->receivedPrompts()[1];
    expect($reaskPrompt->userParts)->toHaveCount(2)
        ->and($reaskPrompt->userParts[1])->toContain('не прошёл валидацию')
        ->and($reaskPrompt->userParts[1])->toContain('confidence'); // прошлый вывод внутри сообщения
});

test('re-ask ограничен двумя попытками — после исчерпания на единственном маршруте возвращается последний retryable-исход', function (): void {
    $provider = FakeLlmProvider::succeeding(structuredOk('invalid-1'))
        ->andThen(structuredOk('invalid-2'))
        ->andThen(structuredOk('invalid-3'));
    $candidates = new Collection([routeWith($provider)]);

    $outcome = reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, $candidates);

    expect($outcome->isOk())->toBeFalse()
        ->and($outcome->isRetryable())->toBeTrue()
        ->and($provider->receivedPrompts())->toHaveCount(3); // 1 первичный + 2 re-ask
});

test('исчерпанные re-ask на первом маршруте эскалируют на следующий по priority маршрут', function (): void {
    $primary = FakeLlmProvider::succeeding(structuredOk('invalid-1'))
        ->andThen(structuredOk('invalid-2'))
        ->andThen(structuredOk('invalid-3'));
    $escalated = FakeLlmProvider::succeeding(structuredOk('{"document_type": "estimate", "confidence": 0.7}'));

    $candidates = new Collection([routeWith($primary, priority: 0), routeWith($escalated, priority: 10)]);

    $outcome = reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, $candidates);

    expect($outcome->isOk())->toBeTrue()
        ->and($primary->receivedPrompts())->toHaveCount(3)
        ->and($escalated->receivedPrompts())->toHaveCount(1);
});

test('fatal-исход (finishReason=safety) эскалирует на следующий маршрут немедленно, без re-ask', function (): void {
    $primary = FakeLlmProvider::succeeding(
        new LlmStructuredResponse(data: [], usage: zeroLlmUsage(), finishReason: FinishReason::Safety, raw: '{}'),
    );
    $escalated = FakeLlmProvider::succeeding(structuredOk('{"document_type": "estimate", "confidence": 0.7}'));

    $candidates = new Collection([routeWith($primary), routeWith($escalated, priority: 10)]);

    $outcome = reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, $candidates);

    expect($outcome->isOk())->toBeTrue()
        ->and($primary->receivedPrompts())->toHaveCount(1); // ни одного re-ask на fatal
});

test('MAX_TOKENS бабблится немедленно — без re-ask и без эскалации маршрутом', function (): void {
    $primary = FakeLlmProvider::truncated();
    $escalated = FakeLlmProvider::succeeding(structuredOk('{"document_type": "estimate", "confidence": 0.7}'));

    $candidates = new Collection([routeWith($primary), routeWith($escalated, priority: 10)]);

    $outcome = reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, $candidates);

    expect($outcome->isOk())->toBeFalse()
        ->and($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->toContain('MAX_TOKENS')
        ->and($primary->receivedPrompts())->toHaveCount(1)
        ->and($escalated->receivedPrompts())->toHaveCount(0);
});

test('все маршруты исчерпаны — возвращается последний исход (fatal остаётся fatal)', function (): void {
    $onlyRoute = FakeLlmProvider::succeeding(
        new LlmStructuredResponse(data: [], usage: zeroLlmUsage(), finishReason: FinishReason::Safety, raw: '{}'),
    );

    $candidates = new Collection([routeWith($onlyRoute)]);

    $outcome = reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, $candidates);

    expect($outcome->isOk())->toBeFalse()
        ->and($outcome->isFatal())->toBeTrue();
});

test('пустой список маршрутов — исключение', function (): void {
    reaskPolicy()->run(basePrompt(), noopSchema(), ScreeningResult::class, new Collection);
})->throws(InvalidArgumentException::class);
