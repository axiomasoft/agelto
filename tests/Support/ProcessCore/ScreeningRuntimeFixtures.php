<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\ScreeningFinalizer;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Procurement\Extraction\Provider\AnalysisCallCoordinator;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Screening\ScreeningStageCodec;

final class ScreeningRuntimeFixtures
{
    public const string VERSION = 'screening/1';

    public static function bind(): void
    {
        // These are the real AI codecs/repositories. Other domain handlers stay inactive in this fixture.
        $registry = new StageRegistry;
        app()->instance(StageRegistry::class, $registry);
        $registry->register(StageCode::Screening, self::VERSION);
        $registry->bindFinalization(StageCode::Screening, new ScreeningStageCodec, app(ScreeningFinalizer::class));
        ExtractionRuntimeFixtures::bind();
    }

    /** @return array{WorkId,ExecutionLease,AnalysisWindowSnapshot,string} */
    public static function work(): array
    {
        $period = FinancialFixtures::reset();
        self::bind();
        app(ProcessAuthoritySync::class)->registerHandler(StageCode::Screening, self::VERSION);
        app(ProcessAuthoritySync::class)->registerHandler(StageCode::Extraction, ExtractionRuntimeFixtures::VERSION);
        $window = AiFixtures::window();
        $work = new WorkId(LedgerFixtures::uuid());
        $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
        app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
            id: $work,
            input: $input,
            inputRevision: $window->run->expectedAnalysisGeneration,
            intentId: null,
            cohortKey: 'manual',
            platformId: null,
            originRunId: null,
            priority: 100,
            eventId: LedgerFixtures::uuid(),
            projectionMessageId: LedgerFixtures::uuid(),
        )));

        return [$work, FinalizeFixtures::running($work), $window, $period];
    }

    /** @param array<string, int|bool>|null $usage */
    public static function capture(
        ExecutionLease $lease,
        AnalysisWindowSnapshot $window,
        string $period,
        string $raw,
        int $ordinal = 0,
        string $finish = 'STOP',
        ?array $usage = null,
    ): void {
        $plan = new ProviderOperationPlan(new RequestIdentity($window->run->recipe->hash->hex, $window->run->screeningInputHash()->hex,
            OperationPurpose::Generate, $ordinal, $window->run->id->value), $window->run->recipe->llm, FinancialFixtures::pricing(), $lease->workId->value->value);
        $operation = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $period);
        FinancialFixtures::claim($operation);
        $payload = ['fixture_receipt' => 1, 'outcome' => 'success', 'cost' => '0.00200001',
            'candidates' => [['finishReason' => $finish, 'content' => ['parts' => [['text' => $raw]]]]]];
        if ($usage !== null) {
            $payload['usage'] = $usage;
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 200, $body);
    }

    public static function raw(): string
    {
        return json_encode(['document_type' => 'estimate', 'confidence' => 0.95, 'summary' => 'Локальная смета',
            'estimate_cardinality' => 'single', 'tags' => [], 'classification_note' => null], JSON_THROW_ON_ERROR);
    }
}
