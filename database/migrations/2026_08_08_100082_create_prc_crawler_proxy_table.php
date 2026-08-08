<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->foreignId('crawler_id')
                ->constrained(table: (new Crawler)->getTable())
                ->cascadeOnDelete();
            $table->foreignId('proxy_id')
                ->constrained(table: (new Proxy)->getTable())
                ->cascadeOnDelete();

            $table->unique(['crawler_id', 'proxy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return 'prc_crawler_proxy';
    }
};
