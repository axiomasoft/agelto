<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\IntentState;

final readonly class ReferenceUpsertResult
{
    public function __construct(public DocumentReferenceId $reference, public ProcessUuid $intent, public IntentState $disposition) {}
}
