<?php

declare(strict_types=1);

use App\Models\Procurement\ProcurementEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $table = (new ProcurementEvent)->getTable();
        $month = Carbon::create(year: 2025, month: 1, day: 1, timezone: 'UTC')->startOfMonth();

        for ($index = 0; $index < 12; $index++) {
            $from = $month->copy()->addMonthsNoOverflow($index);
            $to = $from->copy()->addMonthNoOverflow();
            $partition = "{$table}_{$from->format('Y_m')}";

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                $partition,
                $table,
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ));
        }

        if (DB::selectOne("SELECT to_regclass('partman.part_config') AS name")?->name === null) {
            return;
        }

        DB::update(
            'UPDATE partman.part_config SET retention = NULL, retention_keep_table = true WHERE parent_table = ?',
            ["public.{$table}"],
        );
    }
};
