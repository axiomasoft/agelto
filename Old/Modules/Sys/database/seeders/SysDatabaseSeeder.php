<?php

declare(strict_types=1);

namespace Sys\Database\Seeders;

use Illuminate\Database\Seeder;

final class SysDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            ProxyServicesSeeder::class,
        ]);
    }
}
