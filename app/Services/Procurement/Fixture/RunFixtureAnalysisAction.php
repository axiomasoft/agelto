<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Exceptions\Procurement\Ai\AnalysisNotActivated;
use App\Exceptions\Procurement\Ai\AnalysisRunAlreadyActive;
use App\Exceptions\Procurement\Ai\ScreeningDisabled;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\Ai\AnalysisReadRepository;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\Document\DocumentWorkRepository;
use Illuminate\Support\Facades\DB;

/** Manual fixture input goes through canonical preparation; paid AI handlers remain unregistered. */
final readonly class RunFixtureAnalysisAction
{
    public function __construct(private WorkingCopyProvisioner $provisioner, private AnalysisRunStoreRepository $runs,
        private AnalysisReadRepository $reads, private AnalysisStoreRepository $analyses,
        private DocumentStoreRepository $documents, private DocumentWorkRepository $works) {}

    public function run(Fixture $fixture, ?int $initiatedBy = null): void
    {
        if (! $fixture->exists) {
            throw new AnalysisNotActivated;
        }
        if (! config('ai.screening.enabled', false)) {
            throw new ScreeningDisabled;
        }
        [$document, $version] = $this->provisioner->provision($fixture, dryRun: false);
        if ($this->runs->findActive($version->id) !== null) {
            throw new AnalysisRunAlreadyActive;
        }
        DB::transaction(function () use ($document, $version, $initiatedBy): void {
            DB::select('SELECT id FROM prc_documents WHERE id=? FOR UPDATE', [$document->id]);
            foreach ([AnalysisStep::Screening, AnalysisStep::Extraction] as $step) {
                $analysis = $this->reads->activeForVersion($version->id, $step);
                if ($analysis !== null) {
                    $this->analyses->update($analysis, ['superseded_at' => now()]);
                }
            }
            $this->documents->update($document, ['status' => DocumentStatus::Downloaded]);
            $this->works->prepare($document->id, $initiatedBy, manual: true);
        });
    }
}
