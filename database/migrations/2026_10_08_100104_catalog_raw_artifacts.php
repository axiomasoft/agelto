<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\RawArtifact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new RawArtifact)->getTable(), function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->bigInteger('deletion_generation')->default(0);
            $table->timestampTz('deletion_marked_at')->nullable();
            $table->string('disk');
            $table->text('path');
            $table->timestampTz('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new RawArtifact)->getTable());
    }
};
