<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;

final readonly class ListingPage
{
    public function __construct(
        public ProcurementSummaries $summaries,
        public int $rowsTotal,
        public ResponseAssessment $assessment,
        public TerminalProof $terminalProof,
        public ?CursorEnvelope $nextCursor,
    ) {
        if ($rowsTotal < count($summaries->items)
            || (! $assessment->usable() && ($nextCursor !== null || $terminalProof !== TerminalProof::None))
            || ($terminalProof !== TerminalProof::None && $nextCursor !== null)
            || ($assessment->usable() && $terminalProof === TerminalProof::None && $nextCursor === null)
            || ($assessment->completeness === Completeness::CompleteEmpty && ($rowsTotal !== 0 || $terminalProof !== TerminalProof::SemanticEmpty))
            || ($assessment->completeness === Completeness::Complete && $rowsTotal === 0)
            || ($terminalProof === TerminalProof::SemanticEmpty && $rowsTotal !== 0)) {
            throw new \InvalidArgumentException('Inconsistent listing result.');
        }
    }
}
