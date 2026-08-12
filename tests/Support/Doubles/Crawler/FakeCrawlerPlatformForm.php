<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Contracts\Procurement\Crawler\CrawlerPlatformForm;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use Filament\Schemas\Components\Utilities\Get;

final class FakeCrawlerPlatformForm implements CrawlerPlatformForm
{
    public function components(Registry $registry, Get $get): array
    {
        return [];
    }

    public function previewUrl(Crawler $crawler): string
    {
        return 'https://example.test/preview';
    }
}
