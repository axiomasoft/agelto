<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\CoverageStrategy;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DocumentRange;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Document\DocumentRepresentationRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Extraction\ExtractionContract;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Actual immutable document/representation rows. No legacy AnalysisRun or paid provider. */
final class AiFixtures
{
    public static function recipe(?PinnedLlmContext $context = null): AnalysisRecipe
    {
        $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);

        return new AnalysisRecipe(ContentHash::of('prepare-test/1'), 'estimate-canonical/1', $task->prompt, new ContentHash($task->schema->hash),
            $context ?? new PinnedLlmContext('fixture-provider', 'fixture-account', 'fixture-api/1', 'fixture-model', 'route/1', ['max_output_tokens' => 4096], ['enabled' => false]),
            new CoverageStrategy(20, 1), 'RUB', 'ru-RU');
    }

    public static function window(?PinnedLlmContext $context = null): AnalysisWindowSnapshot
    {
        $recipe = self::recipe($context);
        $document = DocumentStorageFixtures::document();
        $domain = DocumentStorageFixtures::domain();
        $source = DocumentStorageFixtures::observe(new DocumentHead($document->id, 1, null), DocumentStorageFixtures::upload('fixture source', $domain));
        $representation = DocumentStorageFixtures::upload('fixture anchored representation', $domain);
        $repId = app(ProcessTransaction::class)->run(function () use ($source, $representation, $recipe, $domain): int {
            app(StorageCapacityRepository::class)->lockDomains([$domain]);

            return app(DocumentRepresentationRepository::class)->storeLocked($source->head, $representation, $recipe->preparationHash,
                RepresentationKind::Text, 'complete', 'fixture/1', 'text/plain', new RepresentationCoverage(CoverageUnit::Page, 1, [new DocumentRange(0, 1)], []));
        });
        $run = new AnalysisRunSnapshot(LedgerFixtures::uuid(), $source->id, $repId, $representation->hash, $recipe, 1, $source->head->generation, 2, 'fixture');
        $window = new AnalysisWindowSnapshot(LedgerFixtures::uuid(), $run, 'rows:0:2', new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
        app(ProcessTransaction::class)->run(function () use ($run, $window, $document): void {
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$document->id]);
            DB::select('SELECT id FROM prc_document_versions WHERE id=? FOR UPDATE', [$run->documentVersionId]);
            app(AnalysisRepository::class)->storeRunLocked($run);
            app(AnalysisRepository::class)->storeWindowLocked($window);
        });

        return $window;
    }

    public static function raw(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ProcessCore/Llm/extraction-v1.json')) ?: throw new \LogicException('Missing fixture.');
    }
}
