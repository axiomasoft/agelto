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
        Schema::create('platform_parser_analytics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('parser_id')->constrained('platform_parsers')->cascadeOnDelete();

            $table->integer('stage_map_found')->default(0);
            $table->integer('stage_map_errors')->default(0);

            $table->integer('stage_doc_parse_indexed')->default(0);
            $table->integer('stage_doc_parse_errors')->default(0);

            $table->integer('stage_doc_analysis_processed')->default(0);
            $table->integer('stage_doc_analysis_smeta_found')->default(0);
            $table->integer('stage_doc_analysis_errors')->default(0);

            $table->integer('stage_order_parse_parsed_items')->default(0);
            $table->integer('stage_order_parse_errors')->default(0);

            $table->unique(['date', 'parser_id'], 'parser_analytics_date_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_parser_analytics');
    }
};
