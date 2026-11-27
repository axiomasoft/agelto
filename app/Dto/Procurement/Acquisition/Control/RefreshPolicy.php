<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Control;

use App\Dto\Procurement\Process\Primitives\ContentHash;

final readonly class RefreshPolicy
{
    public function __construct(public int $activeSeconds = 21600, public int $recentSeconds = 86400,
        public int $terminalSeconds = 604800, public int $recentDays = 30, public int $detailSeconds = 86400)
    {
        foreach ([$activeSeconds, $recentSeconds, $terminalSeconds, $detailSeconds] as $seconds) {
            if ($seconds < 1 || $seconds > 31536000) {
                throw new \InvalidArgumentException('Invalid refresh cadence.');
            }
        }
        if ($recentDays < 0 || $recentDays > 365) {
            throw new \InvalidArgumentException('Invalid recent horizon.');
        }
    }

    public function hash(): ContentHash
    {
        return ContentHash::of(json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
    }
}
