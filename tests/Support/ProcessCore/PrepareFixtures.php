<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\StoreWorkData;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Document\PrepareFinalizer;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\PrepareHandler;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Document\PrepareStageCodec;
use App\Services\Procurement\Prepare\PdfPreparationTools;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Support\Facades\DB;

final class PrepareFixtures
{
    public readonly PrepareInput $input;

    public readonly WorkId $work;

    public function __construct(string $bytes = '%PDF-fixture', ?PreparePolicy $policy = null)
    {
        $domain = DocumentStorageFixtures::domain(1073741824, 'prepare');
        $tmp = DocumentStorageFixtures::domain(2147483647, 'prepare-tmp');
        config()->set('documents.prepare.durable_domain', DB::table('prc_storage_domains')->where('id', $domain->value)->value('domain_key'));
        config()->set('documents.prepare.temporary_domain', DB::table('prc_storage_domains')->where('id', $tmp->value)->value('domain_key'));
        $policy ??= app(PreparePolicy::class);
        ExecutionFixtures::authority([StageCode::Prepare]);
        app(StageRegistry::class)->bindFinalization(StageCode::Prepare, new PrepareStageCodec, app(PrepareFinalizer::class));
        $document = DocumentStorageFixtures::document();
        $revision = DocumentStorageFixtures::observe(new DocumentHead($document->id, 1, null), DocumentStorageFixtures::upload($bytes, $domain));
        $this->input = new PrepareInput($revision->id, $policy->hash);
        $this->work = new WorkId(LedgerFixtures::uuid());
        DB::transaction(fn () => app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
            id: $this->work,
            input: $this->input,
            inputRevision: $revision->head->generation,
            intentId: null,
            cohortKey: 'manual',
            platformId: null,
            originRunId: null,
            priority: 100,
            eventId: LedgerFixtures::uuid(),
            projectionMessageId: LedgerFixtures::uuid(),
        )));
        ExecutionFixtures::ensureWorkScopes(StageCode::Prepare, 'manual');
    }

    public function context(?LeaseKeeper $keeper = null): ExecutionContext
    {
        return new ExecutionContext(FinalizeFixtures::running($this->work), $keeper ?? app(WorkStoreRepository::class), app(ProcessClock::class),
            Duration::seconds(240), Duration::seconds(360), Duration::seconds(15), Duration::seconds(30), Duration::seconds(30));
    }

    public function handler(PdfPreparationTools $tools): PrepareHandler
    {
        return app()->makeWith(PrepareHandler::class, ['tools' => $tools]);
    }
}
