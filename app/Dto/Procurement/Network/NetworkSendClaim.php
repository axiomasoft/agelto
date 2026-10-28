<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** Only Claimed permits a caller to build a single-use transport; duplicates grant nothing. */
final readonly class NetworkSendClaim
{
    public function __construct(public bool $claimed, public \DateTimeImmutable $authorizedAt, public \DateTimeImmutable $expiresAt) {}
}
