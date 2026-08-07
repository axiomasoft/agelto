<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Run;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->timestampTz('stop_requested_at')->nullable();
            $table->text('stop_kind')->nullable();
            $table->foreignId('stop_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('queue_job_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stop_requested_by');
            $table->dropColumn(['stop_requested_at', 'stop_kind', 'queue_job_id']);
        });
    }

    private function table(): string
    {
        return (new Run)->getTable();
    }
};
