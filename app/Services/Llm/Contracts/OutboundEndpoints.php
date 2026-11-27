<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

/** Pure endpoint declaration for the explicit network catalog sync, never an HTTP operation. */
interface OutboundEndpoints
{
    /** @return list<string> */
    public function outboundOrigins(?string $baseUrl): array;
}
