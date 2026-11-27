<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Outcome;

/** Numeric cURL phases only; URLs, credentials, error text and certificate dumps never cross. */
final readonly class TransportMetrics
{
    /** @param array<string,int|float> $values */
    private function __construct(public array $values) {}

    /** @param array<array-key,mixed> $stats */
    public static function fromCurlStats(array $stats): self
    {
        $values = [];
        foreach (['connect_time', 'appconnect_time', 'pretransfer_time', 'starttransfer_time', 'total_time', 'http_code', 'size_download'] as $key) {
            $value = $stats[$key] ?? 0;
            $number = (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 ? $value : 0;
            $values[$key] = in_array($key, ['http_code', 'size_download'], true) ? (int) $number : (float) $number;
        }

        return new self($values);
    }
}
