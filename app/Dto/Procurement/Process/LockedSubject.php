<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Mutable head реальной subject row, прочитанный уже под её FOR UPDATE lock. */
final readonly class LockedSubject
{
    public function __construct(public SubjectKey $subject, public int $headGeneration, public ?int $inputRevision, public ?int $currentDocumentVersionId = null,
        public ?int $analysisGeneration = null, public ?ProcessUuid $currentAnalysisRunId = null,
        public ?ContentHash $recipeHash = null, public ?int $expectedDocumentHeadGeneration = null, public ?int $expectedAnalysisGeneration = null,
        public ?ContentHash $currentAnalysisRecipeHash = null, public ?ContentHash $currentAnalysisRepresentationHash = null)
    {
        ProcessValue::positive($headGeneration);
        if ($currentDocumentVersionId !== null) {
            ProcessValue::positive($currentDocumentVersionId);
        }
        if ($inputRevision !== null) {
            ProcessValue::positive($inputRevision);
        }
    }
}
