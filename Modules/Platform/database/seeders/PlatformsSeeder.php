<?php

declare(strict_types=1);

namespace Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Platform\Enums\Platform as PlatformEnum;
use Platform\Models\Platform;

final class PlatformsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platforms = [
            PlatformEnum::EIS->value => [
                'name' => PlatformEnum::EIS->getLabel(),
                'url' => 'https://zakupki.gov.ru',
            ],
            PlatformEnum::Local->value => [
                'name' => PlatformEnum::Local->getLabel(),
                'url' => env('APP_URL'),
            ],
        ];

        foreach ($platforms as $code => $platform) {
            Platform::query()->firstOrCreate([
                'code' => $code,
            ], $platform);
        }
    }
}
