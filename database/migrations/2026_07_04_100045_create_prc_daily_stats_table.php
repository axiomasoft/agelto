<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\DailyStat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->date('date');

            $table->foreignId('crawler_id')
                ->index()
                ->constrained(table: (new Crawler)->getTable());

            $table->integer('listing_found')->default(0);
            $table->integer('listing_errors')->default(0);
            $table->integer('detail_fetched')->default(0);
            $table->integer('detail_errors')->default(0);
            $table->integer('docs_indexed')->default(0);
            $table->integer('docs_errors')->default(0);
            $table->integer('screened')->default(0);
            $table->integer('estimates_found')->default(0);
            $table->integer('screen_errors')->default(0);
            $table->integer('extracted_items')->default(0);
            $table->integer('extract_errors')->default(0);

            $table->timestamps();

            $table->unique(['date', 'crawler_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new DailyStat)->getTable();
    }
};
