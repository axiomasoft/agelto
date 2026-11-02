<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Exceptions\Procurement\Ai\AnalysisReanalysisUnavailable;
use App\Exceptions\Procurement\Ai\AnalysisRunAlreadyActive;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\Ai\AnalysisReadRepository;
use App\Repositories\Procurement\Ai\AnalysisRunReadRepository;
use App\Repositories\Procurement\Document\DocumentWorkRepository;
use App\Services\Procurement\Extraction\RerunAnalysisAction;

/** Manual fixture input follows the same preparation and independent analysis producers. */
final readonly class RunFixtureAnalysisAction
{
    public function __construct(private WorkingCopyProvisioner $provisioner, private AnalysisRunReadRepository $runs,
        private DocumentWorkRepository $works, private AnalysisReadRepository $reads, private RerunAnalysisAction $reruns) {}

    public function run(Fixture $fixture, ?int $initiatedBy = null): void
    {
        [$document, $version] = $this->provisioner->provision($fixture, dryRun: false);
        if ($this->runs->findActive($version->id) !== null) {
            throw new AnalysisRunAlreadyActive;
        }
        $current = $version->getAttribute('current_analysis_run_id');
        if (is_string($current)) {
            $accepted = $this->reads->lastForRun($current) ?? throw new AnalysisReanalysisUnavailable;
            $this->reruns->run($accepted, actorId: $initiatedBy);

            return;
        }
        $this->works->prepare($document->id, $initiatedBy, manual: true);
    }
}
