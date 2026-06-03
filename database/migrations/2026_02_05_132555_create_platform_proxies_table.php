<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\PlatformProxy;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->references('id')->on((new App\Models\Platform())->getTable())->cascadeOnDelete();
            $table->foreignId('proxy_id')->references('id')->on((new Sys\Models\Proxy\Proxy())->getTable())->cascadeOnDelete();
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
        return (new PlatformProxy())->getTable();
    }
};
