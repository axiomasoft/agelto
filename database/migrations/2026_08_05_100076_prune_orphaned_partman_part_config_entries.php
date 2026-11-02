<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Removes obsolete partition entries; clean baseline installs have none. */
return new class extends Migration
{
    /** Полный снимок трёх осиротевших строк (2026-08-05, tree `6ddaa62`) — для down(). */
    private const array RESTORE = [
        [
            'parent_table' => 'public.crw_fetches', 'control' => 'fetched_at',
            'template_table' => 'partman.template_public_crw_fetches',
            'maintenance_last_run' => '2026-07-06 00:00:00.235362+00',
        ],
        [
            'parent_table' => 'public.sys_operation_logs', 'control' => 'created_at',
            'template_table' => 'partman.template_public_sys_operation_logs',
            'maintenance_last_run' => '2026-07-06 00:00:00.245981+00',
        ],
    ];

    public function up(): void
    {
        $active = array_keys(config('partitioning.tables'));

        DB::table('partman.part_config')
            ->where('parent_table', 'like', 'public.%')
            ->pluck('parent_table')
            ->each(function (string $parentTable) use ($active): void {
                if (! in_array(Str::after($parentTable, 'public.'), $active, strict: true)) {
                    DB::table('partman.part_config')->where('parent_table', $parentTable)->delete();
                }
            });
    }

    public function down(): void
    {
        foreach (self::RESTORE as $row) {
            DB::table('partman.part_config')->updateOrInsert(
                ['parent_table' => $row['parent_table']],
                [
                    'control' => $row['control'],
                    'partition_interval' => '1 mon',
                    'partition_type' => 'range',
                    'premake' => 3,
                    'automatic_maintenance' => 'on',
                    'template_table' => $row['template_table'],
                    'retention_keep_index' => true,
                    'retention_keep_table' => true,
                    'epoch' => 'none',
                    'optimize_constraint' => 30,
                    'infinite_time_partitions' => false,
                    'datetime_string' => 'YYYYMMDD',
                    'jobmon' => true,
                    'sub_partition_set_full' => false,
                    'undo_in_progress' => false,
                    'inherit_privileges' => false,
                    'constraint_valid' => true,
                    'ignore_default_data' => true,
                    'retention_keep_publication' => false,
                    'maintenance_last_run' => $row['maintenance_last_run'],
                ],
            );
        }
    }
};
