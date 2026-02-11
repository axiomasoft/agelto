<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Models\Parsing\Item;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained((new Platform\Models\Platform())->getTable())->cascadeOnDelete();
            $table->foreignId('crawler_id')->constrained((new Platform\Models\Crawling\Crawler())->getTable())->cascadeOnDelete();
            $table->string('reg_number', 60);

            $table->unique(['platform_id', 'reg_number']);

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
        return (new Item())->getTable();
    }
};
