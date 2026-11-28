<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Enums\Llm\ProviderSendDenial;

final readonly class SendClaimResult
{
    public function __construct(public ?PreparedProviderTransport $transport, public ?ProviderSendDenial $denial,
        public ?\DateTimeImmutable $retryAt = null)
    {
        if (($transport === null) === ($denial === null)) {
            throw new \InvalidArgumentException('Claim grants one transport or a denial.');
        }
    }
}
