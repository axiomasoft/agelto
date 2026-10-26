<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Момент по часам PostgreSQL (`clock_timestamp()`), UTC: authority решений lease/due, не PHP clock. */
final readonly class DbInstant
{
    public \DateTimeImmutable $value;

    public function __construct(\DateTimeImmutable $value)
    {
        $this->value = $value->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Миллисекунды от $earlier до этого момента; отрицательный интервал не допускается. */
    public function since(self $earlier): Duration
    {
        $micro = (int) $this->value->format('Uu') - (int) $earlier->value->format('Uu');

        return new Duration(max(0, intdiv($micro, 1000)));
    }
}
