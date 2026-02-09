<?php

namespace Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Platform\Models\Platform;
use Platform\Enums\Platform as PlatformEnum;

class PlatformsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Platform::query()->firstOrCreate([
            'code' => PlatformEnum::EIS->value,
        ], [
            'name' => PlatformEnum::EIS->getLabel(),
            'url' => 'https://zakupki.gov.ru',
        ]);
    }
}
