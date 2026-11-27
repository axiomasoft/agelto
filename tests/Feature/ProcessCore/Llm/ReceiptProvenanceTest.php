<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\ProviderUsage;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ProviderCodecs;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Llm\Providers\GeminiWireCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\FinancialFixtures;

pest()->group('structured-extraction-contract', 'analysis-execution', 'provider-financial-ledger');

test('большой оплаченный ответ восстанавливается после экранирования внутри immutable квитанции', function (): void {
    $period = FinancialFixtures::reset();
    [, , , , $operation] = FinancialFixtures::authorized($period);
    FinancialFixtures::claim($operation);
    $value = json_decode(FinancialFixtures::body(), true, flags: JSON_THROW_ON_ERROR);
    $value['padding'] = str_repeat('"', intdiv(ReceiptStore::MAX_BYTES, 3));
    $body = json_encode($value, JSON_THROW_ON_ERROR);
    expect(strlen($body))->toBeLessThan(ReceiptStore::MAX_BYTES - 4096);

    $receipt = app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 200, $body);
    $call = app(RequestRepository::class)->get($operation->physicalCallId);
    expect(Storage::disk($call->receiptDisk)->size($call->receiptUploadKey))->toBeGreaterThan(ReceiptStore::MAX_BYTES)
        ->and(app(ReceiptStore::class)->inspect($call)?->body)->toBe($body)
        ->and(app(ReceiptStore::class)->inspect($call)?->hash)->toBe($receipt->hash)
        ->and($call->requestState)->toBe('completed')
        ->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe('0.00200001');
});

test('settled conservative charge сохраняет unknown usage и отсутствие provider version без выдуманных значений', function (bool $reported): void {
    $period = FinancialFixtures::reset();
    app(ReceiptPolicies::class)->register('fixture-provider', new GeminiAssetReceiptPolicy);
    app(ProviderCodecs::class)->register('fixture-provider', new GeminiWireCodec);
    [, , , , $operation] = FinancialFixtures::authorized($period);
    FinancialFixtures::claim($operation);
    $body = ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{}']]]]]];
    if ($reported) {
        $body['modelVersion'] = 'gemini-fixture-001';
    }
    $receipt = app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 200, json_encode($body, JSON_THROW_ON_ERROR));
    $row = DB::table('llm_provider_calls')->sole();
    $restored = app(ReceiptStore::class)->inspect(app(RequestRepository::class)->get($operation->physicalCallId));
    expect($row->usage_status)->toBe('unknown')
        ->and($row->resolved_model_version)->toBe($reported ? 'gemini-fixture-001' : null)
        ->and($row->finish_reason)->toBe('MAX_TOKENS')
        ->and($restored?->assessment->usage)->toEqual($receipt->assessment->usage)
        ->and($restored?->assessment->resolvedModelVersion)->toBe($receipt->assessment->resolvedModelVersion)
        ->and($restored?->assessment->cost)->toBe(DB::table('llm_budget_reservations')->sole()->upper_bound)
        ->and($restored?->assessment->usage?->unknown)->toBeTrue()
        ->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe(DB::table('llm_budget_reservations')->sole()->upper_bound);
})->with([true, false]);

test('unsafe или unbounded provider metadata остаётся только в protected raw и не срывает financial capture', function (mixed $version): void {
    $assessment = (new GeminiAssetReceiptPolicy)->assess(OperationPurpose::Generate, 200, json_encode([
        'modelVersion' => $version, 'candidates' => [['finishReason' => "STOP\nsecret"]],
    ], JSON_THROW_ON_ERROR));
    expect($assessment->resolvedModelVersion)->toBeNull()->and($assessment->finishReason)->toBeNull()
        ->and($assessment->usage?->unknown)->toBeTrue();
})->with([["model\nsecret"], [str_repeat('a', 129)], [123], [['model' => 'unexpected']]]);

test('usage codec отвергает scalar coercion missing unknown extra fields и отрицательные counts', function (string $fault): void {
    $value = json_decode((new ProviderUsage(1, 2, 3, 4, 5, false))->json(), true, flags: JSON_THROW_ON_ERROR);
    match ($fault) {
        'string' => $value['output'] = '3',
        'float' => $value['output'] = 3.5,
        'negative' => $value['output'] = -1,
        'missing' => $value = array_diff_key($value, ['thinking' => true]),
        'extra' => $value['extra'] = 0,
        'unknown' => $value['unknown'] = 0,
    };
    expect(fn () => ProviderUsage::restore(json_encode($value, JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class);
})->with(['string', 'float', 'negative', 'missing', 'extra', 'unknown']);

test('known usage roundtrip сохраняет отдельные counters без double counting thinking', function (): void {
    $usage = new ProviderUsage(80, 20, 200, 10, 0, false);
    expect(ProviderUsage::restore($usage->json()))->toEqual($usage);
});

test('typed receipt provenance отвергает небезопасные metadata до persistence', function (string $field): void {
    expect(fn () => new ReceiptAssessment(ReceiptOutcome::Success, null, 'fixture-policy',
        resolvedModelVersion: $field === 'model' ? "model\nsecret" : null,
        finishReason: $field === 'finish' ? str_repeat('x', 129) : null))->toThrow(InvalidArgumentException::class);
})->with(['model', 'finish']);
