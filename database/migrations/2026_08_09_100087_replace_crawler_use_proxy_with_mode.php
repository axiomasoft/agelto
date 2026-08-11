<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $table = (new Crawler)->getTable();
        DB::statement("ALTER TABLE {$table} ADD COLUMN proxy_mode text");
        DB::statement("UPDATE {$table} SET proxy_mode = CASE WHEN use_proxy THEN 'profile' ELSE 'direct' END");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN proxy_mode SET NOT NULL");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN proxy_mode SET DEFAULT 'default'");
        DB::statement("ALTER TABLE {$table} DROP COLUMN use_proxy");
    }

    public function down(): void
    {
        $table = (new Crawler)->getTable();
        DB::statement("ALTER TABLE {$table} ADD COLUMN use_proxy boolean NOT NULL DEFAULT false");
        DB::statement("UPDATE {$table} SET use_proxy = proxy_mode = 'profile'");
        DB::statement("ALTER TABLE {$table} DROP COLUMN proxy_mode");
    }
};
