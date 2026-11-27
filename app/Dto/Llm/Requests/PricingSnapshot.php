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
        public ?int $cachedInputPerMillion = null,
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $version === '' || $boundEvidence === ''
            || strtotime($validUntil) === false) {
            throw new \InvalidArgumentException('pricing_configuration_hold');
        }
        $bounded = [$inputMaxTokens, $outputMaxTokens, $thinkingMaxTokens, $inputPerMillion, $outputPerMillion,
            $thinkingPerMillion, $fixedCharge, $cacheCreateCharge, $storageTokenHoursMax, $storagePerTokenHour];
        if ($cachedInputPerMillion !== null) {
            $bounded[] = $cachedInputPerMillion;
        }
        foreach ($bounded as $value) {
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
        $encoded = ['version' => $version, 'currency' => $currency, 'purpose' => $purpose->value,
            'units' => 'microcurrency_per_million_tokens', 'input_max_tokens' => $inputMaxTokens, 'output_max_tokens' => $outputMaxTokens,
            'thinking_max_tokens' => $thinkingMaxTokens, 'thinking_included_in_output' => $thinkingIncludedInOutput,
            'input_per_million' => $inputPerMillion, 'output_per_million' => $outputPerMillion, 'thinking_per_million' => $thinkingPerMillion,
            'fixed_charge' => $fixedCharge, 'cache_create_charge' => $cacheCreateCharge, 'storage_token_hours_max' => $storageTokenHoursMax,
            'storage_per_token_hour' => $storagePerTokenHour, 'bound_evidence' => $boundEvidence, 'valid_until' => $validUntil, 'upper_bound' => $this->upperBound];
        if ($cachedInputPerMillion !== null) {
            $encoded['cached_input_per_million'] = $cachedInputPerMillion;
        }
        $this->json = CanonicalJson::encode($encoded);
    }

    public static function restore(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('Invalid pricing snapshot.');
        }

        return new self(
            currency: self::stringField($data, 'currency'),
            version: self::stringField($data, 'version'),
            purpose: OperationPurpose::from(self::stringField($data, 'purpose')),
            inputMaxTokens: self::intField($data, 'input_max_tokens'),
            outputMaxTokens: self::intField($data, 'output_max_tokens'),
            thinkingMaxTokens: self::intField($data, 'thinking_max_tokens'),
            thinkingIncludedInOutput: self::boolField($data, 'thinking_included_in_output'),
            inputPerMillion: self::intField($data, 'input_per_million'),
            outputPerMillion: self::intField($data, 'output_per_million'),
            thinkingPerMillion: self::intField($data, 'thinking_per_million'),
            fixedCharge: self::intField($data, 'fixed_charge'),
            cacheCreateCharge: self::intField($data, 'cache_create_charge'),
            storageTokenHoursMax: self::intField($data, 'storage_token_hours_max'),
            storagePerTokenHour: self::intField($data, 'storage_per_token_hour'),
            boundEvidence: self::stringField($data, 'bound_evidence'),
            validUntil: self::stringField($data, 'valid_until'),
            cachedInputPerMillion: array_key_exists('cached_input_per_million', $data) && $data['cached_input_per_million'] !== null
                ? self::intField($data, 'cached_input_per_million')
                : null,
        );
    }

    /** @return numeric-string */
    public function calculateActualCost(ProviderUsage $usage): string
    {
        $cachedRate = $this->cachedInputPerMillion ?? $this->inputPerMillion;
        $uncachedTokens = bcmul((string) $usage->uncachedInput, (string) $this->inputPerMillion, 0);
        $cachedTokens = bcmul((string) $usage->cachedInput, (string) $cachedRate, 0);
        $output = (string) $usage->output;
        $thinking = (string) $usage->thinking;
        if ($this->thinkingIncludedInOutput) {
            $output = bcadd($output, $thinking, 0);
            $thinking = '0';
        }
        $tokens = bcadd(bcadd($uncachedTokens, $cachedTokens, 0), bcmul($output, (string) $this->outputPerMillion, 0), 0);
        $tokens = bcadd($tokens, bcmul($thinking, (string) $this->thinkingPerMillion, 0), 0);
        $micros = bcdiv(bcadd($tokens, '999999', 0), '1000000', 0);
        $charges = bcadd((string) $this->fixedCharge, (string) $this->cacheCreateCharge, 0);
        $storage = bcmul((string) $usage->cacheStorageTokenHours, (string) $this->storagePerTokenHour, 0);

        return bcdiv(bcadd(bcadd($micros, $charges, 0), $storage, 0), '1000000', 8);
    }

    /** @param array<mixed> $data */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Invalid pricing snapshot field: '.$key);
        }

        return $value;
    }

    /** @param array<mixed> $data */
    private static function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value)) {
            throw new \InvalidArgumentException('Invalid pricing snapshot field: '.$key);
        }

        return $value;
    }

    /** @param array<mixed> $data */
    private static function boolField(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (! is_bool($value)) {
            throw new \InvalidArgumentException('Invalid pricing snapshot field: '.$key);
        }

        return $value;
    }
}
