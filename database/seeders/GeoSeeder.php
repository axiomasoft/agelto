<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Geo\Country;
use App\Models\Geo\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

final class GeoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        $entities = [];

        if (Country::query()->count() === 0) {
            $entities[] = 'countries';
        }

        if (Location::query()->count() === 0) {
            $entities[] = 'locations';
        }

        foreach ($entities as $entity) {
            $sqlFile = storage_path('tmp/'.$entity.'.sql');

            if (! file_exists($sqlFile)) {
                $zip = new ZipArchive;
                $zipFile = $zip->open(database_path('seeders/data/'.$entity.'.zip'));

                if ($zipFile) {
                    $zip->extractTo(storage_path('tmp'));
                }
            }

            DB::unprepared(file_get_contents($sqlFile));
        }

        Schema::enableForeignKeyConstraints();
    }
}
