<?php

declare(strict_types=1);

use App\Enums\Procurement\LlmPurpose;
use App\Repositories\Llm\AiCallStoreRepository;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\LlmCallRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

// Директория tests/Unit не подключает RefreshDatabase по умолчанию (tests/Pest.php) — этому файлу
// нужна тестовая БД (`llm_calls`), подключаем трейт точечно, не трогая остальной Unit-сьют.
uses(RefreshDatabase::class);

function llmCallRecorder(): LlmCallRecorder
{
    return new LlmCallRecorder(new AiCallStoreRepository);
}

test('record() пишет успешный вызов: usage, cost_micros по капе config/ai.php, refs в object storage', function (): void {
    Storage::fake('s3');
    // Значение — ЦЕЛЫЙ вложенный массив под ключом 'ai.capabilities' (реальная форма
    // config/ai.php: capabilities['gemini-2.5-flash'] = [...], БЕЗ дот-нотации в имени модели).
    // Дотовый вариант `config(['ai.capabilities.gemini-2.5-flash' => [...]])` маскировал бы
    // P7.2-баг costMicros() — Laravel Arr::set() тем же образом дробит точки в 'gemini-2.5-flash'
    // на вложенность, искусственно совпадая с прежним багованным dot-notation чтением.
    config([
        'crawler.raw_disk' => 's3',
        'ai.capabilities' => array_merge((array) config('ai.capabilities', []), [
            'gemini-2.5-flash' => [
                'input_per_1m' => 0.30,
                'output_per_1m' => 2.50,
                'cached_per_1m' => 0.03,
            ],
        ]),
    ]);

    $usage = new LlmUsage(promptTokens: 1_000_000, completionTokens: 1_000_000, cachedTokens: 1_000_000, totalTokens: 3_000_000);

    $call = llmCallRecorder()->record(
        analysisId: null,
        purpose: LlmPurpose::Screening,
        provider: 'gemini',
        model: 'gemini-2.5-flash',
        promptVersionSet: ['screening:global' => '1'],
        cacheId: null,
        requestPayload: '{"request":true}',
        responsePayload: '{"response":true}',
        usage: $usage,
        success: true,
        error: null,
        durationMs: 250,
        calledAt: CarbonImmutable::now(),
    );

    // 1_000_000 non-cached prompt tok × 0.30 + 1_000_000 cached × 0.03 + 1_000_000 completion × 2.50
    expect($call->cost_micros)->toBe(300_000 + 30_000 + 2_500_000)
        ->and($call->success)->toBeTrue()
        ->and($call->purpose)->toBe(LlmPurpose::Screening)
        ->and($call->prompt_tokens)->toBe(1_000_000)
        ->and($call->cached_tokens)->toBe(1_000_000)
        ->and($call->completion_tokens)->toBe(1_000_000)
        ->and($call->total_tokens)->toBe(3_000_000)
        ->and($call->prompt_version_set->toArray())->toBe(['screening:global' => '1']);

    Storage::disk('s3')->assertExists($call->request_ref);
    Storage::disk('s3')->assertExists($call->response_ref);
    expect(Storage::disk('s3')->get($call->request_ref))->toBe('{"request":true}');
});

test('record() пишет неуспешный вызов без usage: cost_micros=0, response_ref пуст', function (): void {
    Storage::fake('s3');
    config(['crawler.raw_disk' => 's3']);

    $call = llmCallRecorder()->record(
        analysisId: null,
        purpose: LlmPurpose::ExtractRows,
        provider: 'gemini',
        model: 'gemini-2.5-flash',
        promptVersionSet: [],
        cacheId: null,
        requestPayload: '{"request":true}',
        responsePayload: null,
        usage: null,
        success: false,
        error: 'провайдер вернул 503',
        durationMs: 30_000,
        calledAt: CarbonImmutable::now(),
    );

    expect($call->success)->toBeFalse()
        ->and($call->cost_micros)->toBe(0)
        ->and($call->response_ref)->toBeNull()
        ->and($call->error)->toBe('провайдер вернул 503');

    Storage::disk('s3')->assertExists($call->request_ref);
});

test('record() модель отсутствует в capability-матрице → cost_micros=0, запись не падает', function (): void {
    Storage::fake('s3');
    config(['crawler.raw_disk' => 's3']);

    $usage = new LlmUsage(promptTokens: 10, completionTokens: 5, cachedTokens: 0, totalTokens: 15);

    $call = llmCallRecorder()->record(
        analysisId: null,
        purpose: LlmPurpose::Screening,
        provider: 'local-vllm',
        model: 'qwen3-4b-unlisted',
        promptVersionSet: [],
        cacheId: null,
        requestPayload: '{}',
        responsePayload: '{}',
        usage: $usage,
        success: true,
        error: null,
        durationMs: 100,
        calledAt: CarbonImmutable::now(),
    );

    expect($call->cost_micros)->toBe(0);
});
