<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use RuntimeException;

final class InstallClock
{
    public static function configure(): void
    {
        if (getenv('PROCESS_CORE_TEST') !== '1' || getenv('PROCESS_CORE_INSTALL') !== '1') {
            return;
        }

        $anchor = getenv('PROCESS_CORE_INSTALL_AT');
        $utc = new DateTimeZone('UTC');
        $instant = is_string($anchor) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $anchor, $utc) : false;

        if ($instant === false || $instant->format('Y-m-d\TH:i:s\Z') !== $anchor
            || $instant->format('Y-m') !== (new DateTimeImmutable('now', $utc))->format('Y-m')) {
            throw new RuntimeException('Install clock requires a verified current-month UTC PROCESS_CORE_INSTALL_AT.');
        }

        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }
}
