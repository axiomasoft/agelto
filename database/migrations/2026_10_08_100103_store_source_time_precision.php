<?php

declare(strict_types=1);

use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Procurement)->getTable(), function (Blueprint $table): void {
            $table->boolean('source_updated_has_day_precision')->nullable();
            $table->timestampTz('source_updated_before')->nullable();
            $table->timestampTz('source_observed_at', 6)->nullable();
            $table->boolean('source_order_ambiguous')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table((new Procurement)->getTable(), function (Blueprint $table): void {
            $table->dropColumn(['source_updated_has_day_precision', 'source_updated_before', 'source_observed_at', 'source_order_ambiguous']);
        });
    }
};
