<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class RetryAt
{
    public \DateTimeImmutable $value;

    public function __construct(\DateTimeImmutable $value)
    {
        $this->value = $value->setTimezone(new \DateTimeZone('UTC'));
    }
}
