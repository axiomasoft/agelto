<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Mutable head реальной subject row, прочитанный уже под её FOR UPDATE lock. */
final readonly class LockedSubject
{
    public function __construct(public SubjectKey $subject, public int $headGeneration, public ?int $inputRevision)
    {
        ProcessValue::positive($headGeneration);
        if ($inputRevision !== null) {
            ProcessValue::positive($inputRevision);
        }
    }
}
