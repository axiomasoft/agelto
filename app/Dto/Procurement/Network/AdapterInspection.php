<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Network\AdapterVerdict;

/**
 * Pure full-capability result. Persistence, network and unexpected programming faults cannot
 * masquerade as corroborated source contract failures.
 *
 * @template-covariant T
 */
final readonly class AdapterInspection
{
    /** @param T $value */
    public function __construct(public mixed $value, public AdapterVerdict $verdict,
        public string $checkCode, public ?ContentHash $signature = null, public bool $knownDefect = false)
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $checkCode) !== 1
            || ($verdict === AdapterVerdict::ContractFailure) !== ($signature !== null)
            || ($knownDefect && $verdict !== AdapterVerdict::ContractFailure)) {
            throw new \InvalidArgumentException('Invalid adapter validation evidence.');
        }
    }
}
