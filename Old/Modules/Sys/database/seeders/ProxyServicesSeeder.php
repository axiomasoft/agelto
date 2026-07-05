<?php

declare(strict_types=1);

namespace Sys\Database\Seeders;

use Illuminate\Database\Seeder;
use Sys\Models\Proxy\Service;

final class ProxyServicesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            [
                'name' => 'Asocks',
                'code' => 'asocks',
            ],
        ];

        foreach ($services as $service) {
            Service::query()->firstOrCreate([
                'code' => $service['code'],
            ], $service);
        }
    }
}
