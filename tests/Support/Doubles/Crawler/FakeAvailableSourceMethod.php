<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

final class FakeAvailableSourceMethod extends FakeSourceMethod
{
    public function isAvailable(): bool
    {
        return true;
    }
}
