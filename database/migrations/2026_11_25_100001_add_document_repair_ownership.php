<?php

declare(strict_types=1);

use App\Models\Procurement\Document\StorageReservation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $reservations = (new StorageReservation)->getTable();
        DB::statement("ALTER TABLE {$reservations}
            ADD COLUMN repair_attempt_id uuid NULL REFERENCES prc_process_attempts(id),
            ADD COLUMN repair_claimed_at timestamptz NULL");
    }

    public function down(): void
    {
        $reservations = (new StorageReservation)->getTable();
        DB::statement("ALTER TABLE {$reservations} DROP COLUMN repair_attempt_id, DROP COLUMN repair_claimed_at");
    }
};
