<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use App\Enums\Llm\PromptStep;
use App\Exceptions\Llm\UnsupportedConsumer;
use App\Models\Procurement\Ai\PromptSuggestion;

/** Critic has no registered durable request owner. Human patch review remains available. */
final readonly class PromptCritic
{
    /** @param array<string,mixed> $diff */
    public function critique(PromptStep $step, array $diff, ?int $analysisId, ?int $fixtureId): ?PromptSuggestion
    {
        throw new UnsupportedConsumer;
    }
}
