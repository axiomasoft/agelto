<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

/**
 * Физический rate/concurrency budget одного scope (origin, route-origin, probe). Rate не
 * умножается числом proxy. emission interval = ceil(60e6 / rate): округление никогда не
 * повышает фактический rate.
 */
final readonly class OriginBudgetPolicy
{
    public function __construct(public int $ratePerMinute, public int $burst, public int $maxInflight)
    {
        if ($ratePerMinute < 1 || $burst < 1 || $maxInflight < 1) {
            throw new \InvalidArgumentException('Budget rate, burst and max inflight must be positive.');
        }
        if ($burst > 32767 || $maxInflight > 32767) {
            throw new \InvalidArgumentException('Budget burst and max inflight must fit smallint.');
        }
    }

    public function emissionIntervalUs(): int
    {
        return intdiv(59_999_999, $this->ratePerMinute) + 1;
    }

    /** @return array{rate_per_minute: int, burst: int, max_inflight: int} */
    public function toArray(): array
    {
        return ['rate_per_minute' => $this->ratePerMinute, 'burst' => $this->burst, 'max_inflight' => $this->maxInflight];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $rate = $data['rate_per_minute'] ?? null;
        $burst = $data['burst'] ?? null;
        $maxInflight = $data['max_inflight'] ?? null;
        if (! is_int($rate) || ! is_int($burst) || ! is_int($maxInflight)) {
            throw new \InvalidArgumentException('Budget fields must be integers.');
        }

        return new self($rate, $burst, $maxInflight);
    }

    public function equals(self $other): bool
    {
        return $this->ratePerMinute === $other->ratePerMinute && $this->burst === $other->burst && $this->maxInflight === $other->maxInflight;
    }

    /** Совпадение со строкой budget по хранимым authority значениям. */
    public function matchesStored(int $emissionIntervalUs, int $burst, int $maxInflight): bool
    {
        return $this->emissionIntervalUs() === $emissionIntervalUs && $this->burst === $burst && $this->maxInflight === $maxInflight;
    }
}
