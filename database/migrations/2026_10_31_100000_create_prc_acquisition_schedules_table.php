<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE prc_acquisition_schedules (
                profile_id bigint NOT NULL REFERENCES prc_crawlers(id) ON DELETE NO ACTION,
                acquisition_kind text NOT NULL CHECK (acquisition_kind IN ('fresh','archive','delta')),
                cron text NOT NULL CHECK (length(cron) BETWEEN 1 AND 200),
                timezone text NOT NULL CHECK (length(timezone) BETWEEN 1 AND 100),
                profile_version bigint NOT NULL CHECK (profile_version > 0),
                next_due_at timestamptz NOT NULL,
                PRIMARY KEY (profile_id, acquisition_kind)
            )
            SQL);
        DB::statement('CREATE INDEX prc_acquisition_schedules_due_idx ON prc_acquisition_schedules (next_due_at, profile_id, acquisition_kind)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE prc_acquisition_schedules');
    }
};
