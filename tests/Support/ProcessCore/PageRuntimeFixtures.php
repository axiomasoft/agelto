<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\Execution\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\Planning\CoverageStrategy;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Document\Download\DocumentHead;
use App\Dto\Procurement\Document\Download\DocumentRange;
use App\Dto\Procurement\Document\Prepare\PreparePage;
use App\Dto\Procurement\Document\Prepare\RepresentationCoverage;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Document\DocumentRepresentationRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Procurement\Extraction\Orchestration\AnalysisRecipeFactory;
use App\Services\Procurement\Extraction\Orchestration\AnalysisRepresentation;
use App\Services\Procurement\Extraction\Provider\AnalysisCallCoordinator;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Facades\DB;

final class PageRuntimeFixtures
{
    /** @return array{AnalysisRunSnapshot,int,string} */
    public static function prepared(?PinnedLlmContext $context = null): array
    {
        $period = FinancialFixtures::reset();
        AiFixtures::storageDomains();
        ScreeningRuntimeFixtures::bind();
        app(ProcessAuthoritySync::class)->registerHandler(StageCode::Screening, ScreeningRuntimeFixtures::VERSION);
        app(ProcessAuthoritySync::class)->registerHandler(StageCode::Extraction, ExtractionRuntimeFixtures::VERSION);
        app(ProcessAuthoritySync::class)->registerHandler(StageCode::Publication, ExtractionRuntimeFixtures::VERSION);
        $base = AiFixtures::recipe($context);
        $recipe = (new AnalysisRecipeFactory)->make($base->preparationHash, $base->llm, new CoverageStrategy(2, 0));
        $document = DocumentStorageFixtures::document();
        $domain = DocumentStorageFixtures::domain();
        $version = DocumentStorageFixtures::observe(new DocumentHead($document->id, 1, null), DocumentStorageFixtures::upload('immutable PDF bytes', $domain));
        $text = "[page:1]\nСмета1 строка1\nСмета1 строка2\n[page:2]\nСмета1 строка3\n[page:3]\nСмета2 строка1\n[page:4]\nСмета2 строка2\n";
        $upload = DocumentStorageFixtures::upload($text, $domain);
        $coverage = new RepresentationCoverage(CoverageUnit::Page, 4, [new DocumentRange(0, 4)], [],
            array_map(static fn (int $page): PreparePage => new PreparePage($page, PageTextStatus::NativeText), range(1, 4)), 'complete');
        $source = (new AnalysisRepresentation)->source($text, ContentHash::of($text), $coverage->json());
        $rep = app(ProcessTransaction::class)->run(function () use ($domain, $version, $upload, $recipe, $coverage): int {
            app(StorageCapacityRepository::class)->lockDomains([$domain]);

            return app(DocumentRepresentationRepository::class)->storeLocked($version->head, $upload, $recipe->preparationHash,
                RepresentationKind::Text, 'complete', 'fixture/1', 'text/plain', $coverage);
        });
        $run = new AnalysisRunSnapshot(LedgerFixtures::uuid(), $version->id, $rep, $upload->hash, $recipe, 1, $version->head->generation, 2, 'fixture', source: $source);

        return [$run, $document->id, $period];
    }

    /** @return array{WorkId,ExecutionLease,AnalysisRunSnapshot,string} */
    public static function work(?PinnedLlmContext $context = null): array
    {
        [$run, $documentId, $period] = self::prepared($context);
        $work = new WorkId(LedgerFixtures::uuid());
        app(ProcessTransaction::class)->run(function () use ($run, $documentId, $work): void {
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$documentId]);
            DB::select('SELECT id FROM prc_document_versions WHERE id=? FOR UPDATE', [$run->documentVersionId]);
            app(AnalysisRepository::class)->storeRunLocked($run);
            app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
                id: $work,
                input: new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash),
                inputRevision: $run->expectedAnalysisGeneration,
                intentId: null,
                cohortKey: 'manual',
                platformId: null,
                originRunId: null,
                priority: 100,
                eventId: LedgerFixtures::uuid(),
                projectionMessageId: LedgerFixtures::uuid(),
            ));
        });

        return [$work, FinalizeFixtures::running($work), $run, $period];
    }

    public static function capture(ExecutionLease $lease, AnalysisRunSnapshot $run, string $period, string $raw,
        ?PageRange $range = null, string $finish = 'STOP', int $ordinal = 0): void
    {
        $purpose = $range === null ? TaskPurpose::Screening : TaskPurpose::Discovery;
        $snapshot = $run->recipe->tasks->task($purpose);
        $plan = new ProviderOperationPlan(new RequestIdentity($run->recipe->hash->hex,
            $range === null ? $run->screeningInputHash()->hex : $run->discoveryInputHash($range)->hex,
            OperationPurpose::Generate, $ordinal, $run->id->value), $run->recipe->llm, FinancialFixtures::pricing(), $lease->workId->value->value,
            generation: new ProviderGenerationInput($snapshot->prompt, 'fixture input', (new ProviderSchemaCompiler)->compile($snapshot->schema), purpose: $purpose));
        $operation = app(AnalysisCallCoordinator::class)->authorize($lease, $run->id, $plan, $period);
        FinancialFixtures::claim($operation);
        $body = json_encode(['fixture_receipt' => 1, 'outcome' => 'success', 'cost' => '0.00200001',
            'candidates' => [['finishReason' => $finish, 'content' => ['parts' => [['text' => $raw]]]]]], JSON_THROW_ON_ERROR);
        app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 200, $body);
    }
}
