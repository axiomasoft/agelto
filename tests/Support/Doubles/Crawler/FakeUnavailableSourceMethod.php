<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

final class FakeUnavailableSourceMethod extends FakeSourceMethod
{
    public function isAvailable(): bool
    {
        return false;
    }
}
