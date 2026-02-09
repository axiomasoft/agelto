<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Models\Crawling\Analytics;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('crawler_id')->constrained((new Platform\Models\Crawling\Crawler())->getTable())->cascadeOnDelete();

            $table->integer('s1_found')->default(0);
            $table->integer('s1_errors')->default(0);

            $table->integer('s2_docs_indexed')->default(0);
            $table->integer('s2_errors')->default(0);

            $table->integer('s3_ai_processed')->default(0);
            $table->integer('s3_smeta_found')->default(0);
            $table->integer('s3_errors')->default(0);

            $table->integer('s4_parsed_items')->default(0);
            $table->integer('s4_errors')->default(0);

            $table->unique(['date', 'crawler_id'], 'crawler_date_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Analytics())->getTable();
    }
};
