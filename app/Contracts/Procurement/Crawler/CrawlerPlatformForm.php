<?php

declare(strict_types=1);

namespace App\Contracts\Procurement\Crawler;

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use Filament\Schemas\Components\Utilities\Get;

interface CrawlerPlatformForm
{
    /** @return array<int, mixed> */
    public function components(Registry $registry, Get $get): array;

    public function previewUrl(Crawler $crawler): string;
}
