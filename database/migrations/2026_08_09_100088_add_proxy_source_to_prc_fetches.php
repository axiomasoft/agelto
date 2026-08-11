<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Fetch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE '.(new Fetch)->getTable().' ADD COLUMN proxy_source text NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.(new Fetch)->getTable().' DROP COLUMN proxy_source');
    }
};
