<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Network;

/** A pure contract rejection already recorded by the canonical adapter authority. */
final class AdapterInputRejected extends \RuntimeException
{
    /** @param array<string,list<string>> $missingSelectors */
    public function __construct(public readonly string $checkCode, public readonly array $missingSelectors = [])
    {
        parent::__construct('Adapter input rejected: '.$checkCode);
    }
}
