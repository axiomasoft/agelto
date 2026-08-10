<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Procurement\CrawlerProfileSeeder;
use Database\Seeders\Procurement\EisPlatformSeeder;
use Database\Seeders\Procurement\Okpd2NodeSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

final class BaseSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call(command: 'guard:sync-roles');

        $this->call([
            PlatformSeeder::class,
            AiRoutingSeeder::class,
            PromptSeeder::class,
            EisPlatformSeeder::class,
            StatusMappingSeeder::class,
            Okpd2NodeSeeder::class,
            CrawlerProfileSeeder::class,
        ]);
    }
}
