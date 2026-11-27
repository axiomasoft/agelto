<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\TaskManifest;
use App\Dto\Llm\Requests\TaskSnapshot;
use App\Dto\Procurement\Ai\Execution\AnalysisRecipe;
use App\Dto\Procurement\Ai\Screening\ScreeningPolicySnapshot;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Inputs\ExtractionWindowInput;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\Orchestration\ExtractionHandler;
use App\Services\Procurement\Extraction\Provider\AnalysisCallCoordinator;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Screening\ScreeningContract;
use App\Services\Procurement\Screening\ScreeningPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('structured-extraction-contract', 'analysis-execution');

test('recipe v2 seals complete purpose snapshots and rejects old or tampered codecs', function (): void {
    $recipe = AiFixtures::recipe();
    $manifest = $recipe->tasks;
    $screen = $manifest->task(TaskPurpose::Screening);
    $rows = $manifest->task(TaskPurpose::Rows);
    expect($screen->schema->hash)->not->toBe($rows->schema->hash)
        ->and(AnalysisRecipe::restore($recipe->json, 2))->toEqual($recipe)
        ->and(new TaskManifest(array_reverse($manifest->tasks)))->toEqual($manifest)
        ->and(fn () => AnalysisRecipe::restore($recipe->json, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TaskManifest([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TaskManifest([$screen, $screen]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TaskManifest(['screening' => $screen]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TaskManifest::restore(str_repeat(' ', 2_097_153)))->toThrow(InvalidArgumentException::class, 'bound')
        ->and(fn () => $manifest->task(TaskPurpose::Repair))->toThrow(InvalidArgumentException::class);
    foreach (['version', 'purpose', 'prompt_hash', 'schema_hash', 'extra', 'contract_version'] as $defect) {
        $value = json_decode($manifest->json, true, flags: JSON_THROW_ON_ERROR);
        match ($defect) {
            'version' => $value['version'] = 99,
            'purpose' => $value['tasks'][0]['purpose'] = 'unregistered_step',
            'prompt_hash', 'schema_hash' => $value['tasks'][0][$defect] = str_repeat('0', 64),
            'extra' => $value['tasks'][0]['extra'] = true,
            'contract_version' => $value['tasks'][0]['contract_version'] = 99,
        };
        expect(fn () => TaskManifest::restore(json_encode($value, JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class);
    }
    Http::assertNothingSent();
});

test('semantic task purpose changes both typed subrange input and sealed wire fingerprint', function (): void {
    FinancialFixtures::reset();
    $window = AiFixtures::window();
    $snapshot = $window->run->recipe->tasks->task(TaskPurpose::Rows);
    $identity = new RequestIdentity($window->run->recipe->hash->hex, $window->subrangeInputHash($window->range)->hex,
        OperationPurpose::Generate, 0, $window->run->id->value);
    $wire = static function (TaskPurpose $purpose) use ($window, $snapshot, $identity): ProviderWireRequest {
        $input = new ProviderGenerationInput($snapshot->prompt, 'same untrusted input', (new ProviderSchemaCompiler)->compile($snapshot->schema), purpose: $purpose);
        $plan = new ProviderOperationPlan($identity, $window->run->recipe->llm, FinancialFixtures::pricing(), 'fixture', generation: $input);

        return new ProviderWireRequest($plan, 'POST', 'https://generativelanguage.googleapis.com/v1beta/models/fixture-model:generateContent',
            'https://generativelanguage.googleapis.com', '{}');
    };
    expect($window->subrangeInputHash($window->range, TaskPurpose::Rows))->not->toEqual($window->subrangeInputHash($window->range, TaskPurpose::Passport))
        ->and($wire(TaskPurpose::Rows)->fingerprint)->not->toBe($wire(TaskPurpose::Screening)->fingerprint)
        ->and($window->run->recipe->tasks->matches($wire(TaskPurpose::Screening)->operation->generation))->toBeFalse();
    Http::assertNothingSent();
});

test('one run authorizes its distinct pinned screening and rows contracts and rejects schema prompt or purpose substitution', function (): void {
    $period = FinancialFixtures::reset();
    ExtractionRuntimeFixtures::bind();
    app(ProcessAuthoritySync::class)->registerHandler(StageCode::Extraction, ExtractionRuntimeFixtures::VERSION);
    $window = AiFixtures::window();
    $screen = new WorkId(LedgerFixtures::uuid());
    $rows = new WorkId(LedgerFixtures::uuid());
    app(ProcessTransaction::class)->run(function () use ($window, $screen, $rows): void {
        $works = app(WorkStoreRepository::class);
        $works->storeWork(new StoreWorkData(
            id: $screen,
            input: new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash),
            inputRevision: $window->run->expectedAnalysisGeneration,
            intentId: null,
            cohortKey: 'manual',
            platformId: null,
            originRunId: null,
            priority: 100,
            eventId: LedgerFixtures::uuid(),
            projectionMessageId: LedgerFixtures::uuid(),
        ));
        $works->storeWork(new StoreWorkData(
            id: $rows,
            input: new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash),
            inputRevision: $window->run->expectedDocumentHeadGeneration,
            intentId: null,
            cohortKey: 'manual',
            platformId: null,
            originRunId: null,
            priority: 100,
            eventId: LedgerFixtures::uuid(),
            projectionMessageId: LedgerFixtures::uuid(),
        ));
    });
    $screenLease = FinalizeFixtures::running($screen);
    $rowsLease = FinalizeFixtures::running($rows);
    $plans = [];
    foreach ([TaskPurpose::Screening, TaskPurpose::Rows] as $purpose) {
        $task = $window->run->recipe->tasks->task($purpose);
        $lease = $purpose === TaskPurpose::Screening ? $screenLease : $rowsLease;
        $inputHash = $purpose === TaskPurpose::Screening ? $window->run->screeningInputHash() : $window->subrangeInputHash($window->range);
        $input = new ProviderGenerationInput($task->prompt, 'fixture input', (new ProviderSchemaCompiler)->compile($task->schema), purpose: $purpose);
        $plan = new ProviderOperationPlan(new RequestIdentity($window->run->recipe->hash->hex, $inputHash->hex, OperationPurpose::Generate, 0,
            $window->run->id->value), $window->run->recipe->llm, FinancialFixtures::pricing(), $lease->workId->value->value, generation: $input);
        app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $period);
        $plans[$purpose->value] = $plan;
    }
    $base = $plans['screening'];
    foreach (['prompt', 'schema', 'purpose'] as $defect) {
        $task = $base->generation;
        $input = new ProviderGenerationInput($defect === 'prompt' ? new PromptSnapshot('changed after sealing', 2) : $task->system,
            $task->user, $defect === 'schema' ? $plans['rows']->generation->schema : $task->schema,
            purpose: $defect === 'purpose' ? TaskPurpose::Rows : TaskPurpose::Screening);
        $plan = new ProviderOperationPlan($base->identity, $base->context, $base->pricing, $base->consumerCorrelationId, generation: $input);
        expect(fn () => app(AnalysisCallCoordinator::class)->authorize($screenLease, $window->run->id, $plan, $period))->toThrow(OperationDenied::class);
    }
    expect(app(AnalysisRepository::class)->getRun($window->run->id)->recipe)->toEqual($window->run->recipe)
        ->and(DB::table('prc_analysis_runs')->count())->toBe(1)
        ->and(DB::table('llm_provider_requests')->count())->toBe(2)
        ->and(DB::table('llm_provider_requests')->distinct()->count('recipe_hash'))->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(2);
    Http::assertNothingSent();
});

test('screening semantic validation rejects invalid confidence and contradictory cardinality', function (): void {
    $task = (new ScreeningContract)->task(new PromptSnapshot('Classify untrusted input without tools.', 1));
    $raw = ['document_type' => 'estimate', 'confidence' => 0.9, 'summary' => 'Fixture estimate', 'estimate_cardinality' => 'single', 'tags' => ['estimate'], 'classification_note' => null];
    $validator = app(StructuredTaskValidator::class);
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::Accepted);
    $raw['confidence'] = 2;
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::InvalidSemantics);
    $raw['confidence'] = 0.9;
    $raw['estimate_cardinality'] = 'none';
    expect($validator->validate($task, json_encode($raw, JSON_THROW_ON_ERROR), 'STOP')->disposition)->toBe(StructuredDisposition::InvalidSemantics);
});

test('backend screening uses only the sealed confidence and rule policy', function (string $type, float $confidence, bool $ignored, string $expected): void {
    $documentType = AiDocumentType::from($type);
    $result = new ScreeningResult($documentType, $confidence, 'Fixture',
        $type === 'estimate' ? EstimateCardinality::Single : EstimateCardinality::None, []);
    $snapshot = new ScreeningPolicySnapshot(0.4, 0.6, $ignored);
    config()->set('ai.screening.confidence_low', 0.99);
    config()->set('ai.screening.confidence_high', 1.0);
    $decision = (new ScreeningPolicy)->decide($result, $snapshot);
    expect($decision->reason->value)->toBe($expected)
        ->and($decision->extractionDispatched)->toBe($expected === 'analyzer_available');
})->with([
    ['estimate', 0.39, false, 'low_confidence'],
    ['estimate', 0.4, false, 'borderline_confidence'],
    ['estimate', 0.6, false, 'borderline_confidence'],
    ['estimate', 0.61, false, 'analyzer_available'],
    ['unknown', 0.9, false, 'unknown_type'],
    ['tech_spec', 0.9, false, 'known_type_no_analyzer'],
    ['estimate', 0.9, true, 'ignore_rule'],
]);

test('invalid confidence policy is rejected before a run can seal it', function (): void {
    expect(fn () => new ScreeningPolicySnapshot(0.6, 0.4))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScreeningPolicySnapshot(0.4, 1.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScreeningPolicySnapshot(NAN, 0.6))->toThrow(InvalidArgumentException::class);
});

test('missing or incompatible pinned rows contract blocks before authorization and semantic reasks', function (bool $missing): void {
    $recipe = AiFixtures::recipe();
    $screening = $recipe->tasks->task(TaskPurpose::Screening);
    $tasks = $missing ? [$screening] : [$screening, new TaskSnapshot(TaskPurpose::Rows, $screening->prompt, $screening->schema)];
    [$work, $lease, $window] = ExtractionRuntimeFixtures::work(recipe: AiFixtures::recipe(tasks: new TaskManifest($tasks)));
    $outcome = app(ExtractionHandler::class)->execute(
        new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash),
        app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StageBlocked::class)
        ->and($outcome->reason)->toBe(BlockedReason::InvalidRecipeConfiguration)
        ->and(DB::table('llm_provider_requests')->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0);
})->with([true, false]);
