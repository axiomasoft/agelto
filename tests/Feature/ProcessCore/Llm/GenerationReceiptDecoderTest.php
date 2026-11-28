<?php

declare(strict_types=1);

use App\Enums\Llm\OperationPurpose;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Execution\StructuredCallExecutor;
use Tests\Support\ProcessCore\FinancialFixtures;

pest()->group('structured-extraction-contract', 'analysis-execution');

test('successful assets and verified errors never enter the generation model decoder', function (OperationPurpose $purpose, string $outcome, ?string $cost): void {
    $period = FinancialFixtures::reset();
    [,,,,$operation] = FinancialFixtures::authorized($period, $purpose);
    FinancialFixtures::claim($operation);
    $receipt = app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 200, FinancialFixtures::body($outcome, $cost));
    expect(fn () => app(StructuredCallExecutor::class)->decodeGeneration($receipt))->toThrow(OperationDenied::class, 'successful_generation_receipt_required');
})->with([
    [OperationPurpose::FileUpload, 'success', '0.00200001'],
    [OperationPurpose::CacheCreate, 'success', '0.00200001'],
    [OperationPurpose::Lookup, 'success', '0.00200001'],
    [OperationPurpose::Generate, 'unknown', null],
    [OperationPurpose::Generate, 'billed_error', '0.00200001'],
]);
