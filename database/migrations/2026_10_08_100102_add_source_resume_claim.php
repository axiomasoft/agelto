<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Run;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (new Run)->getTable();
        Schema::table($table, function (Blueprint $table): void {
            $table->timestampTz('source_resume_claimed_until')->nullable();
            $table->timestampTz('source_resume_checked_at')->nullable();
        });
        DB::statement("CREATE INDEX prc_runs_source_resume_due ON {$table} (source_resume_claimed_until, source_resume_checked_at, id) WHERE status = 'paused' AND purpose = 'profile' AND (stats->>'source_suspended') = 'true'");
    }

    public function down(): void
    {
        Schema::table((new Run)->getTable(), function (Blueprint $table): void {
            $table->dropIndex('prc_runs_source_resume_due');
            $table->dropColumn(['source_resume_claimed_until', 'source_resume_checked_at']);
        });
    }
};
