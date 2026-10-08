<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Procurement\Proxy\LegacyProxyPasswordUpgrade;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crawler:upgrade-proxy-passwords {--apply : Encrypt verified legacy plaintext; otherwise inspect counts only}')]
final class UpgradeProxyPasswords extends Command
{
    public function handle(LegacyProxyPasswordUpgrade $upgrade): int
    {
        $counts = $upgrade->run(apply: (bool) $this->option('apply'));
        $this->info(sprintf('Encrypted: %d; legacy plaintext: %d.', $counts['encrypted'], $counts['plaintext']));

        return self::SUCCESS;
    }
}
