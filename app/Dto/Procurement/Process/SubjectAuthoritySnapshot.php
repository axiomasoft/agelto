<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class SubjectAuthoritySnapshot
{
    public function __construct(
        public SubjectKey $subject,
        public ?SubjectKey $parent,
        public ?int $headGeneration,
        public ?int $analysisGeneration,
        public ?ContentHash $recipeHash,
    ) {
        if ($headGeneration !== null) {
            ProcessValue::positive($headGeneration);
        }
        if ($analysisGeneration !== null) {
            ProcessValue::positive($analysisGeneration);
        }
    }
}
