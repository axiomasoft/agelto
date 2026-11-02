<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Enums\Llm\OperationPurpose;
use App\Services\Llm\Schema\CanonicalJson;

/** Integer microcurrency; rates are per million tokens, storage per token-hour. */
final readonly class PricingSnapshot
{
    public string $json;

    /** @var numeric-string */
    public string $upperBound;

    public function __construct(
        public string $currency,
        public string $version,
        public OperationPurpose $purpose,
        public int $inputMaxTokens,
        public int $outputMaxTokens,
        public int $thinkingMaxTokens,
        public bool $thinkingIncludedInOutput,
        public int $inputPerMillion,
        public int $outputPerMillion,
        public int $thinkingPerMillion,
        public int $fixedCharge,
        public int $cacheCreateCharge,
        public int $storageTokenHoursMax,
        public int $storagePerTokenHour,
        public string $boundEvidence,
        public string $validUntil,
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $version === '' || $boundEvidence === ''
            || strtotime($validUntil) === false) {
            throw new \InvalidArgumentException('pricing_configuration_hold');
        }
        foreach ([$inputMaxTokens, $outputMaxTokens, $thinkingMaxTokens, $inputPerMillion, $outputPerMillion,
            $thinkingPerMillion, $fixedCharge, $cacheCreateCharge, $storageTokenHoursMax, $storagePerTokenHour] as $value) {
            if ($value < 0 || $value > 1_000_000_000_000) {
                throw new \InvalidArgumentException('Unbounded pricing.');
            }
        }
        $tokens = bcadd(bcmul((string) $inputMaxTokens, (string) $inputPerMillion), bcmul((string) $outputMaxTokens, (string) $outputPerMillion));
        if (! $thinkingIncludedInOutput) {
            $tokens = bcadd($tokens, bcmul((string) $thinkingMaxTokens, (string) $thinkingPerMillion));
        }
        $micros = bcadd(bcdiv(bcadd($tokens, '999999'), '1000000', 0), bcadd((string) $fixedCharge, (string) $cacheCreateCharge));
        $micros = bcadd($micros, bcmul((string) $storageTokenHoursMax, (string) $storagePerTokenHour));
        if (bccomp($micros, '1000000000000000') > 0) {
            throw new \InvalidArgumentException('Unbounded pricing.');
        }
        $this->upperBound = bcdiv($micros, '1000000', 8);
        $this->json = CanonicalJson::encode(['version' => $version, 'currency' => $currency, 'purpose' => $purpose->value,
            'units' => 'microcurrency_per_million_tokens', 'input_max_tokens' => $inputMaxTokens, 'output_max_tokens' => $outputMaxTokens,
            'thinking_max_tokens' => $thinkingMaxTokens, 'thinking_included_in_output' => $thinkingIncludedInOutput,
            'input_per_million' => $inputPerMillion, 'output_per_million' => $outputPerMillion, 'thinking_per_million' => $thinkingPerMillion,
            'fixed_charge' => $fixedCharge, 'cache_create_charge' => $cacheCreateCharge, 'storage_token_hours_max' => $storageTokenHoursMax,
            'storage_per_token_hour' => $storagePerTokenHour, 'bound_evidence' => $boundEvidence, 'valid_until' => $validUntil, 'upper_bound' => $this->upperBound]);
    }
}
