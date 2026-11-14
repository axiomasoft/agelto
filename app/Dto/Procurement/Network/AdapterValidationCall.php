<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Network\TrafficClass;
use App\Services\Procurement\Proxyma\ProfileBindings;

/** One bounded read-only capability invocation, including every tab and its pure validators. */
final readonly class AdapterValidationCall
{
    /** @param list<string> $controls */
    public function __construct(public string $callerCode, public int $platformId,
        public AdapterContractRef $contract, public ContentHash $inputHash, public SafeUrl $firstUrl,
        public TrafficClass $trafficClass, public ProfileBindings $routes, public array $controls,
        public int $deadlineSeconds)
    {
        if ($platformId < 1 || $deadlineSeconds < 1 || $deadlineSeconds > 420
            || preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $callerCode) !== 1) {
            throw new \InvalidArgumentException('Invalid full-validation call.');
        }
    }
}
