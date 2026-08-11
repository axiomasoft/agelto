<?php

declare(strict_types=1);

namespace App\Dto\Actions\Procurement\Proxy;

final readonly class UpdateCrawlerProxySettingsCommand
{
    public function __construct(
        public ?int $defaultProxyId,
    ) {}
}
