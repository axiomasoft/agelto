<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_parser_map_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_url_id')->constrained('platform_parser_map_urls')->cascadeOnDelete();
            $table->string('name', 500);
            $table->string('url', 1000);

            $table->string('status', 20)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->string('process_status', 20)->default('pending');
            $table->text('process_error')->nullable();
            $table->json('process_analysis_report')->nullable();

            $table->timestamp('parse_started_at')->nullable();
            $table->timestamps();

            $table->index('process_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_parser_map_documents');
    }
};
