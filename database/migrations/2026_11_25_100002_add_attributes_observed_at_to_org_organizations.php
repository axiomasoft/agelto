<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Organization)->getTable(), function (Blueprint $table): void {
            $table->timestampTz('attributes_observed_at', precision: 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table((new Organization)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('attributes_observed_at');
        });
    }
};
