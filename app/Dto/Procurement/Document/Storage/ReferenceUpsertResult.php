<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Storage;

use App\Dto\Procurement\Document\Download\DocumentReferenceId;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Governance\IntentState;

final readonly class ReferenceUpsertResult
{
    public function __construct(public DocumentReferenceId $reference, public ProcessUuid $intent, public IntentState $disposition) {}
}
