<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Registers only the current partitioned baseline tables. */
return new class extends Migration
{
    public function up(): void
    {
        // pg_partman без явного SCHEMA ставится в текущую default-схему (public), а не
        // в "partman" — все функции/part_config документированы под partman.*, поэтому
        // схема создаётся и указывается явно.
        DB::statement('CREATE SCHEMA IF NOT EXISTS partman');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_partman SCHEMA partman');

        $start = Carbon::now()->startOfMonth()->addMonthsNoOverflow(3);

        foreach (config('partitioning.tables') as $table => $column) {
            $parentTable = 'public.'.$table;

            $alreadyRegistered = DB::selectOne(
                'SELECT 1 AS registered FROM partman.part_config WHERE parent_table = ?',
                [$parentTable],
            );

            if ($alreadyRegistered) {
                continue;
            }

            DB::selectOne(
                'SELECT partman.create_parent('
                .'p_parent_table := ?, p_control := ?, p_type := ?, p_interval := ?, '
                .'p_premake := ?, p_start_partition := ?)',
                [
                    $parentTable,
                    $column,
                    'range',
                    config('partitioning.interval'),
                    config('partitioning.premake'),
                    $start->toDateTimeString(),
                ],
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(config('partitioning.tables')) as $table) {
            DB::table('partman.part_config')->where('parent_table', 'public.'.$table)->delete();
        }

        DB::statement('DROP EXTENSION IF EXISTS pg_partman');
        DB::statement('DROP SCHEMA IF EXISTS partman CASCADE');
    }
};
