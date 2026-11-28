<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummaries;
use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummary;
use App\Dto\Procurement\Acquisition\Listing\ListingPage;
use App\Dto\Procurement\Acquisition\Listing\ListingPlan;
use App\Dto\Procurement\Acquisition\Listing\ListingResult;
use App\Dto\Procurement\Acquisition\Listing\ListingWindow;
use App\Dto\Procurement\Platform\Diagnostics\ResponseAssessment;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Dto\Procurement\Process\Inputs\ListingInput;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\RunKind;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\CrawlStoreRepository;
use App\Repositories\Procurement\Crawler\ListingFinalizer;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\Projection\RunStoreRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\Detail\DetailValueCodec;
use App\Services\Procurement\Acquisition\Listing\ListingStageCodec;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;

final class ListingFixtures
{
    public static function bind(): void
    {
        ExecutionFixtures::authority([StageCode::Listing]);
        self::bindLocal();
    }

    public static function bindLocal(): void
    {
        ExecutionFixtures::registerHandler(StageCode::Listing);
        app(StageRegistry::class)->bindFinalization(StageCode::Listing, new ListingStageCodec(app(DetailValueCodec::class)), app(ListingFinalizer::class));
    }

    /** @return array{run: ProcessUuid, crawl: ProcessUuid, work: WorkId, input: ListingInput, stream: ContentHash} */
    public static function run(string $from = '2026-01-01', ?string $through = null, int $budget = 10000, ?ContentHash $stream = null, ?Registry $registry = null, ?string $closesAt = null, int $blockSize = 500, AcquisitionKind $kind = AcquisitionKind::Archive): array
    {
        $registry ??= Registry::factory()->eis()->create();
        $partitioner = new EisListingPartitioner;
        $filter = new EisListingFilter(['123']);
        $snapshot = $partitioner->snapshot($filter);
        $run = LedgerFixtures::uuid();
        $crawl = LedgerFixtures::uuid();
        $stream ??= ContentHash::of($run->value);

        return DB::transaction(function () use ($registry, $partitioner, $filter, $snapshot, $run, $crawl, $stream, $from, $through, $budget, $closesAt, $blockSize, $kind): array {
            app(RunStoreRepository::class)->storeRun($run, RunKind::Manual, null, null, new VersionedPayload('{}', 1), null, LedgerFixtures::uuid(), LedgerFixtures::uuid());
            app(CrawlStoreRepository::class)->storeCrawlRun($crawl, $run, $kind,
                new VersionedPayload($snapshot->serializedPayload, 1), ContentHash::of('access'), 'fixture-listing/1');
            $plan = $partitioner->plan($filter, $from, $through ?? $from, blockSize: $blockSize);
            if ($closesAt !== null) {
                $w = $plan->windows[0];
                $plan = new ListingPlan([
                    new ListingWindow($w->cursor, $w->start, $w->end, $closesAt),
                ]);
            }
            $inputs = app(ListingStoreRepository::class)->allocate($crawl, $plan,
                $registry->id, 'fz44', $snapshot, $stream, $budget);
            $first = null;
            foreach ($inputs as $input) {
                $work = LedgerFixtures::storeWork($input, originRunId: $run);
                app(WorkStoreRepository::class)->linkRun($run, $work, RunWorkRelation::Created, AccountingRole::Required);
                $first ??= $work;
            }

            return ['run' => $run, 'crawl' => $crawl, 'work' => $first ?? throw new \LogicException('No root'), 'input' => $inputs[0], 'stream' => $stream];
        });
    }

    public static function summary(string $id, string $content = 'A'): ProcurementSummary
    {
        return new ProcurementSummary(new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType('registry:44fz'), new SourceIdentifierValue($id)),
            $content, 'Подача заявок', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'), ContentHash::of($content), initialPrice: '1250000.50', currency: 'RUB');
    }

    /** @param list<ProcurementSummary> $items */
    public static function page(array $items = []): ListingPage
    {
        return new ListingPage(new ProcurementSummaries($items), count($items), new ResponseAssessment(
            $items === [] ? Completeness::CompleteEmpty : Completeness::Complete,
            $items === [] ? AssessmentReason::Empty : AssessmentReason::Valid, ContentHash::of('body')),
            $items === [] ? TerminalProof::SemanticEmpty : TerminalProof::ShortPage, null);
    }

    /**
     * @return array{work: WorkId, input: ListingInput}|null
     *
     * @phpstan-impure
     */
    public static function next(ProcessUuid $crawl): ?array
    {
        $row = DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
            ->where('b.crawl_run_id', $crawl->value)->whereIn('w.state', ['ready', 'admitted'])->orderBy('w.created_at')->first(['w.id', 'w.input_payload']);
        if ($row === null) {
            return null;
        }
        if (! is_string($row->id) || ! is_string($row->input_payload)) {
            throw new \LogicException('Invalid work payload.');
        }
        $input = (new ProcessPayloadCodec)->decodeInput(new VersionedPayload($row->input_payload, 1));
        if (! $input instanceof ListingInput) {
            throw new \LogicException('Not listing');
        }

        return ['work' => new WorkId(new ProcessUuid($row->id)), 'input' => $input];
    }

    public static function finish(WorkId $work, ListingInput $input, ListingPage $page): void
    {
        $lease = FinalizeFixtures::running($work);
        $result = new ListingResult(app(ListingStoreRepository::class)->task($input), $page);
        $completion = app(WorkFinalizer::class)->finish($lease, new StageSucceeded($result));
        if ($completion->status->value !== 'applied') {
            throw new \LogicException($completion->status->value);
        }
    }
}
