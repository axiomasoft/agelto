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
        Schema::table((new Run)->getTable(), function (Blueprint $table): void {
            $table->uuid('execution_owner_token')->nullable()->after('last_heartbeat_at');
            $table->timestampTz('execution_lease_expires_at')->nullable()->after('execution_owner_token')->index();
        });
    }

    public function down(): void
    {
        Schema::table((new Run)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['execution_lease_expires_at']);
            $table->dropColumn(['execution_owner_token', 'execution_lease_expires_at']);
        });
    }
};
