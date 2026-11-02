<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DocumentRange;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Document\DocumentRepresentationRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;

/** Fixture analysis stays entirely in tests. Same input semantics for manual and both platform cohorts. */
final class DocumentFeederFixtures
{
    public static function analysis(string $cohort): WorkId
    {
        $manual = $cohort === 'manual';
        $code = $manual ? 'fixture-manual' : substr($cohort, strlen('platform:'));
        $platform = Platform::query()->firstOrCreate(['code' => $code],
            ['name' => $code, 'base_url' => 'https://'.$code.'.test', 'is_active' => false, 'is_public' => ! $manual]);
        $procurement = null;
        if (! $manual) {
            $registry = Registry::query()->firstOrCreate(['platform_id' => $platform->id, 'code' => 'fixture'],
                ['name' => 'Fixture', 'settings' => []]);
            $procurement = Procurement::factory()->create(['registry_id' => $registry->id, 'platform_id' => $platform->id])->id;
        }
        $doc = Document::factory()->create(['procurement_id' => $procurement, 'platform_id' => $platform->id]);
        $cohort = $manual ? 'manual' : 'platform:'.$platform->id;
        $revision = DocumentStorageFixtures::observe(new DocumentHead($doc->id, 1, null),
            DocumentStorageFixtures::upload('immutable fixture source', DocumentStorageFixtures::domain()));
        $domain = DocumentStorageFixtures::domain();
        $prepared = DocumentStorageFixtures::upload('anchored fixture representation', $domain);
        $representationId = app(ProcessTransaction::class)->run(function () use ($revision, $prepared, $domain): int {
            app(StorageCapacityRepository::class)->lockDomains([$domain]);

            return app(DocumentRepresentationRepository::class)->storeLocked($revision->head, $prepared, ContentHash::of('prepare-fixture/1'),
                RepresentationKind::Text, 'complete', 'fixture/1', 'text/plain',
                new RepresentationCoverage(CoverageUnit::Page, 1,
                    [new DocumentRange(0, 1)], []));
        });
        $analysis = AiFixtures::pinScreening($representationId);
        $input = new ScreeningInput($revision->id, $analysis->representationHash, $analysis->recipe->hash);
        $id = new WorkId(LedgerFixtures::uuid());
        app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork($id, $input,
            $analysis->expectedAnalysisGeneration, null, $cohort, $platform->id, null, 100, LedgerFixtures::uuid(), LedgerFixtures::uuid()));

        return $id;
    }

    public static function bindAnalysis(): void
    {
        FinalizeFixtures::bind(StageCode::Screening);
        app(StageRegistry::class)->bindHandler(StageCode::Screening, new FixtureAnalysisHandler);
    }
}
