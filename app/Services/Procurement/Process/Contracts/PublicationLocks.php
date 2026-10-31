<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

/** DB-only additional publication subjects, resolved before the prefix; never an adapter callback. */
interface PublicationLocks
{
    public function lockIdentities(): void;

    /** Called after the work's owner subject, before work. */
    public function lockSubjects(): void;
}
