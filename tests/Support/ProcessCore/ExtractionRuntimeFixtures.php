<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\StoreWorkData;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\PublicationRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\ChunkCommitter;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Extraction\ExtractionStageCodec;
use App\Services\Procurement\Extraction\PublicationHandler;
use App\Services\Procurement\Extraction\PublicationStageCodec;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;

final class ExtractionRuntimeFixtures
{
    public const string VERSION = 'analysis/1';

    public static function bind(): void
    {
        $registry = app(StageRegistry::class);
        $registry->register(StageCode::Extraction, self::VERSION);
        $registry->bindFinalization(StageCode::Extraction, new ExtractionStageCodec, app(ChunkCommitter::class));
        $registry->bindHandler(StageCode::Extraction, app(ExtractionHandler::class));
        $registry->register(StageCode::Publication, self::VERSION);
        $registry->bindFinalization(StageCode::Publication, new PublicationStageCodec, app(PublicationRepository::class));
        $registry->bindHandler(StageCode::Publication, app(PublicationHandler::class));
    }

    /** @return array{WorkId,ExecutionLease,AnalysisWindowSnapshot,string} */
    public static function work(?RowRange $range = null, ?AnalysisRecipe $recipe = null, ?RepresentationCoverage $coverage = null, bool $sealPlan = true): array
    {
        $period = FinancialFixtures::reset();
        self::bind();
        app(ProcessAuthoritySync::class)->registerHandler(StageCode::Extraction, self::VERSION);
        app(ProcessAuthoritySync::class)->registerHandler(StageCode::Publication, self::VERSION);
        $window = AiFixtures::window(range: $range, recipe: $recipe, coverage: $coverage, sealPlan: $sealPlan);
        $work = new WorkId(LedgerFixtures::uuid());
        $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
        app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
            id: $work,
            input: $input,
            inputRevision: $window->run->expectedDocumentHeadGeneration,
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

    /** A deterministic provider receipt, through the actual ledger/immutable receipt store. */
    public static function capture(ExecutionLease $lease, AnalysisWindowSnapshot $window, string $period, string $raw, string $finish = 'STOP', int $ordinal = 0, RowRange|PageRange|null $range = null): void
    {
        $body = json_encode(['fixture_receipt' => 1, 'outcome' => 'success', 'cost' => '0.00200001',
            'candidates' => [['finishReason' => $finish, 'content' => ['parts' => [['text' => $raw]]]]]], JSON_THROW_ON_ERROR);
        self::captureEnvelope($lease, $window, $period, $body, $ordinal, $range);
    }

    public static function captureEnvelope(ExecutionLease $lease, AnalysisWindowSnapshot $window, string $period, string $body, int $ordinal = 0, RowRange|PageRange|null $range = null): void
    {
        $range ??= $window->range;
        $plan = new ProviderOperationPlan(new RequestIdentity($window->run->recipe->hash->hex, $window->subrangeInputHash($range)->hex,
            OperationPurpose::Generate, $ordinal, $window->run->id->value), $window->run->recipe->llm, FinancialFixtures::pricing(), $lease->workId->value->value);
        $operation = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $period);
        FinancialFixtures::claim($operation);
        app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 200, $body);
    }
}
