<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Requests\ProviderCall;
use App\Dto\Llm\Requests\ProviderUsage;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Dto\Llm\Schema\CanonicalSchema;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\AuthHeaderScheme;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Providers\OpenAiChatCompletionsClient;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use App\Services\Llm\Schema\ProviderSchemaCompiler;

function makeOpenAiPlan(string $model, array $options, array $thinking): ProviderOperationPlan
{
    $context = new PinnedLlmContext(
        provider: 'openai',
        account: 'openai-primary',
        apiVersion: 'v1',
        model: $model,
        routePolicyVersion: 'config/1',
        options: $options,
        thinking: $thinking,
    );
    $pricing = new PricingSnapshot(
        currency: 'USD',
        version: 'catalog/1',
        purpose: OperationPurpose::Generate,
        inputMaxTokens: 128000,
        outputMaxTokens: 16384,
        thinkingMaxTokens: 16384,
        thinkingIncludedInOutput: true,
        inputPerMillion: 400_000,
        outputPerMillion: 1_600_000,
        thinkingPerMillion: 1_600_000,
        fixedCharge: 0,
        cacheCreateCharge: 0,
        storageTokenHoursMax: 0,
        storagePerTokenHour: 0,
        boundEvidence: 'config:llm.model_pricing:'.$model,
        validUntil: '2027-12-31T23:59:59Z',
        cachedInputPerMillion: 100_000,
    );
    $schema = (new ProviderSchemaCompiler)->compile(new CanonicalSchema((new JsonSchemaGenerator)->generate(ScreeningResult::class, canonical: true)));
    $identity = new RequestIdentity(
        recipeHash: str_repeat('a', 64),
        inputHash: str_repeat('b', 64),
        purpose: OperationPurpose::Generate,
        semanticReaskOrdinal: 0,
        replayIntentId: '00000000-0000-4000-8000-000000000001',
    );

    return new ProviderOperationPlan(
        identity: $identity,
        context: $context,
        pricing: $pricing,
        consumerCorrelationId: 'corr-1',
        generation: new ProviderGenerationInput(
            system: new PromptSnapshot('System prompt.', 1),
            user: 'User document text.',
            schema: $schema,
            purpose: TaskPurpose::Screening,
        ),
    );
}

function makeOpenAiReceipt(string $body, string $model = 'gpt-4.1-mini'): VerifiedReceipt
{
    $context = new PinnedLlmContext('openai', 'openai-primary', 'v1', $model, 'config/1', ['max_output_tokens' => 4096], []);
    $auth = new AuthorizedProviderOperation(
        logicalRequestId: '00000000-0000-4000-8000-000000000010',
        physicalCallId: '00000000-0000-4000-8000-000000000011',
        requestEpoch: 1,
        sendAuthorizationToken: 'token',
        ownerAttemptId: '00000000-0000-4000-8000-000000000012',
        ownerWorkGeneration: 1,
        reservationId: '00000000-0000-4000-8000-000000000013',
    );
    $call = new ProviderCall(
        authorization: $auth,
        purpose: OperationPurpose::Generate,
        recipeHash: str_repeat('a', 64),
        inputHash: str_repeat('b', 64),
        context: $context,
        currency: 'USD',
        periodId: '00000000-0000-4000-8000-000000000014',
        upperBound: '0.10000000',
        requestState: 'authorized',
        claimed: true,
        receiptId: '00000000-0000-4000-8000-000000000015',
        receiptDisk: 'local',
        receiptUploadKey: 'receipt.json',
        receiptHash: hash('sha256', $body),
        outcome: 'succeeded',
        recordedAssessment: null,
    );
    $assessment = new ReceiptAssessment(
        outcome: ReceiptOutcome::Success,
        cost: null,
        policyEvidence: 'openai/1:generation_reported_usage',
        usage: new ProviderUsage(100, 0, 20, 0, 0, false),
    );

    return new VerifiedReceipt($call, hash('sha256', $body), 200, $body, $assessment);
}

test('клиент компилирует wire-запрос для моделей OpenAI с Bearer-схемой и strict json_schema', function (
    string $model,
    array $options,
    array $thinking,
    ?string $expectedEffort,
    bool $expectTemperature,
): void {
    $client = new OpenAiChatCompletionsClient;
    $plan = makeOpenAiPlan($model, $options, $thinking);
    $wire = $client->compileGeneration($plan, 'https://api.openai.com');
    $decoded = json_decode($wire->body, true, flags: JSON_THROW_ON_ERROR);

    expect($wire->method)->toBe('POST')
        ->and($wire->endpoint)->toBe('https://api.openai.com/v1/chat/completions')
        ->and($wire->authScheme)->toBe(AuthHeaderScheme::Bearer)
        ->and($decoded['model'])->toBe($model)
        ->and($decoded['max_completion_tokens'])->toBe($options['max_output_tokens'])
        ->and($decoded['response_format']['type'])->toBe('json_schema')
        ->and($decoded['response_format']['json_schema']['strict'])->toBeTrue()
        ->and($decoded['response_format']['json_schema']['schema']['additionalProperties'])->toBeFalse()
        ->and(array_key_exists('temperature', $decoded))->toBe($expectTemperature);

    if ($expectedEffort !== null) {
        expect($decoded['reasoning_effort'])->toBe($expectedEffort);
    } else {
        expect(array_key_exists('reasoning_effort', $decoded))->toBeFalse();
    }
})->with([
    'gpt-4.1-mini без temperature' => ['gpt-4.1-mini', ['max_output_tokens' => 4096], [], null, false],
    'gpt-4.1 с temperature' => ['gpt-4.1', ['max_output_tokens' => 8192, 'temperature' => '0.2'], [], null, true],
    'gpt-4o без reasoning' => ['gpt-4o', ['max_output_tokens' => 4096], [], null, false],
    'o4-mini с reasoning_effort' => ['o4-mini', ['max_output_tokens' => 16384], ['effort' => 'medium'], 'medium', false],
]);

test('клиент декодирует finish_reason, refusal и tool_calls в канонический GenerationResponse', function (
    string $body,
    string $expectedFinish,
    string $expectedJson,
    bool $expectedUnexpected,
): void {
    $client = new OpenAiChatCompletionsClient;
    $response = $client->decodeGeneration(makeOpenAiReceipt($body));

    expect($response->finishReason)->toBe($expectedFinish)
        ->and($response->modelJson)->toBe($expectedJson)
        ->and($response->unexpectedContent)->toBe($expectedUnexpected);
})->with([
    'stop' => [
        json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"ok":true}']]]], JSON_THROW_ON_ERROR),
        'STOP',
        '{"ok":true}',
        false,
    ],
    'length' => [
        json_encode(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"ok":']]]], JSON_THROW_ON_ERROR),
        'MAX_TOKENS',
        '{"ok":',
        false,
    ],
    'content_filter' => [
        json_encode(['choices' => [['finish_reason' => 'content_filter', 'message' => ['content' => null]]]], JSON_THROW_ON_ERROR),
        'SAFETY',
        '',
        true,
    ],
    'refusal' => [
        json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => null, 'refusal' => 'Policy refusal']]]], JSON_THROW_ON_ERROR),
        'REFUSAL',
        '',
        false,
    ],
    'tool_calls' => [
        json_encode(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => null, 'tool_calls' => [['id' => 'call_1']]]]]], JSON_THROW_ON_ERROR),
        'UNSUPPORTED',
        '',
        true,
    ],
]);
