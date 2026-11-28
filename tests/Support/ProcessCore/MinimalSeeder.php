<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class MinimalSeeder extends Seeder
{
    public function run(): void
    {
        Str::createUuidsUsing(static fn (): string => '00000000-0000-7000-8000-000000000001');

        try {
            // Existing inactive training platform only: no crawler, account or paid route.
            $this->call(PlatformSeeder::class);
        } finally {
            Str::createUuidsNormally();
        }
    }
}
