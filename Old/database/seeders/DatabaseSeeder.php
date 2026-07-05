<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Facades\Artisan;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
        ]);

        $output = isset($this->command) ? $this->command->getOutput() : null;

        foreach (Module::getOrdered() as $module) {
            $parameters = ['module' => $module->getName()];

            if (app()->environment('production')) {
                $parameters['--force'] = true;
            }

            Artisan::call('module:seed', $parameters, $output);
        }
    }
}
