<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Primitives;

/** Неотрицательный интервал monotonic/DB времени в миллисекундах; не wall-clock instant. */
final readonly class Duration
{
    public function __construct(public int $milliseconds)
    {
        ProcessValue::nonNegative($milliseconds);
    }

    public static function seconds(int $seconds): self
    {
        return new self($seconds * 1000);
    }

    public static function nanoseconds(int $nanoseconds): self
    {
        return new self(max(0, intdiv($nanoseconds, 1_000_000)));
    }

    public function plus(self $other): self
    {
        return new self($this->milliseconds + $other->milliseconds);
    }

    public function nanos(): int
    {
        return $this->milliseconds * 1_000_000;
    }
}
